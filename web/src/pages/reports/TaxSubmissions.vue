<script setup lang="ts">
import { ref, computed, onMounted, reactive } from 'vue'
import { useI18n } from 'vue-i18n'
import { api } from '@/api/client'
import { apiErrorMessage } from '@/api/errors'
import {
  taxFilingApi,
  type TaxSubmission, type FilingChannel, type VatPayment, type ChangesAfterFiling,
} from '@/api/taxFiling'
import { nowForDatetimeLocal, submissionPeriodLabel, formatDbDateTime, VAT_FORMS } from '@/utils/taxFiling'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import Modal from '@/components/ui/Modal.vue'
import VatPaymentCard from '@/components/reports/VatPaymentCard.vue'
import ChangedAfterFilingList from '@/components/reports/ChangedAfterFilingList.vue'

const { t, te } = useI18n()
const toast = useToast()
const auth = useAuthStore()

const items = ref<TaxSubmission[]>([])
const loading = ref(false)
const error = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    items.value = await taxFilingApi.list()
    openFromHash()
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

/** Letter of the submission form; the supplementary (D) and call-answer (V) forms get a word label. */
function submissionFormLabel(f: string): string {
  const key = `reports.submissions.sform_${f}`
  return te(key) ? t(key) : f
}

function formCodeLabel(c: string): string {
  return ({
    dphdp3: t('reports.submissions.form_dphdp3'),
    dphkh1: t('reports.submissions.form_dphkh1'),
    dphshv: t('reports.submissions.form_dphshv'),
    ossei1: t('reports.submissions.form_ossei1'),
    dpfdp5: t('reports.submissions.form_dpfdp5'),
    dppdp9: t('reports.submissions.form_dppdp9'),
  } as Record<string, string>)[c] || c
}

function statusBadgeClass(s: string): string {
  if (s === 'passed') return 'bg-success-50 text-success-600'
  if (s === 'failed') return 'bg-danger-50 text-danger-500'
  return 'bg-neutral-100 text-neutral-500'
}

function filingBadgeClass(s: string): string {
  if (s === 'confirmed') return 'bg-success-50 text-success-600'
  if (s === 'filed') return 'bg-primary-50 text-primary-700'
  return 'bg-neutral-100 text-neutral-500'
}

function downloadXml(id: number) {
  window.open(taxFilingApi.xmlUrl(id), '_blank')
}

function replaceRow(updated: TaxSubmission) {
  const i = items.value.findIndex(x => x.id === updated.id)
  if (i >= 0) {
    // The list rows carry the payment card; a write response may omit it for non-DPH forms.
    items.value[i] = { ...items.value[i], ...updated }
  }
}

async function deleteItem(id: number) {
  if (!confirm(t('reports.submissions.delete_confirm'))) return
  try {
    await api.delete(`/reports/submissions/${id}`)
    items.value = items.value.filter(x => x.id !== id)
    toast.success(t('common.deleted'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

const totalSize = computed(() => items.value.reduce((s, x) => s + x.xml_size_bytes, 0))
const isAdmin = computed(() => auth.user?.role === 'admin')

// ── Filing ───────────────────────────────────────────────────────────────

const fileTarget = ref<TaxSubmission | null>(null)
const fileForm = reactive({ filed_at: '', channel: 'epo' as FilingChannel, reference: '' })
const filing = ref(false)

function openFile(s: TaxSubmission) {
  fileTarget.value = s
  fileForm.filed_at = nowForDatetimeLocal()
  fileForm.channel = 'epo'
  fileForm.reference = ''
}

async function submitFile() {
  if (!fileTarget.value) return
  filing.value = true
  try {
    const updated = await taxFilingApi.markFiled(fileTarget.value.id, {
      channel: fileForm.channel,
      filed_at: fileForm.filed_at ? fileForm.filed_at.replace('T', ' ') : undefined,
      reference: fileForm.reference.trim() || undefined,
    })
    replaceRow(updated)
    toast.success(t('reports.filing.marked_filed'))
    const id = fileTarget.value.id
    fileTarget.value = null
    await refreshExpanded(id)
  } catch (e) {
    // A duplicate (409) carries a clear Czech message from the server.
    toast.error(apiErrorMessage(e))
  } finally {
    filing.value = false
  }
}

async function unfile(s: TaxSubmission) {
  if (!confirm(t('reports.filing.unfile_confirm'))) return
  try {
    replaceRow(await taxFilingApi.unfile(s.id))
    toast.success(t('reports.filing.unfiled'))
    await refreshExpanded(s.id)
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

const receiptInput = ref<HTMLInputElement | null>(null)
const receiptTarget = ref<TaxSubmission | null>(null)
const uploading = ref(false)

function pickReceipt(s: TaxSubmission) {
  receiptTarget.value = s
  receiptInput.value?.click()
}

async function onReceiptPicked(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  const target = receiptTarget.value
  input.value = ''
  if (!file || !target) return
  uploading.value = true
  try {
    replaceRow(await taxFilingApi.uploadReceipt(target.id, file))
    toast.success(t('reports.filing.receipt_uploaded'))
  } catch (err) {
    toast.error(apiErrorMessage(err))
  } finally {
    uploading.value = false
  }
}

async function removeReceipt(s: TaxSubmission) {
  if (!confirm(t('reports.filing.remove_receipt_confirm'))) return
  try {
    replaceRow(await taxFilingApi.removeReceipt(s.id))
    toast.success(t('reports.filing.receipt_removed'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

// ── Row details: payment card + documents changed after filing ──────────

const expanded = ref<number | null>(null)
const details = reactive<{ payment: Record<number, VatPayment>; changes: Record<number, ChangesAfterFiling> }>({ payment: {}, changes: {} })

async function refreshExpanded(id: number) {
  if (expanded.value === id) await loadDetails(items.value.find(x => x.id === id))
}

async function loadDetails(s: TaxSubmission | undefined) {
  if (!s) return
  delete details.payment[s.id]
  delete details.changes[s.id]
  try {
    const [payment, changes] = await Promise.all([
      s.form_code === 'dphdp3' && s.payment ? taxFilingApi.payment(s.id) : undefined,
      isVatForm(s) && s.filing_status !== 'generated' ? taxFilingApi.changes(s.id) : undefined,
    ])
    if (payment) details.payment[s.id] = payment
    if (changes) details.changes[s.id] = changes
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

async function toggle(s: TaxSubmission) {
  if (expanded.value === s.id) {
    expanded.value = null
    return
  }
  expanded.value = s.id
  await loadDetails(s)
}

function onPaymentChanged(s: TaxSubmission, payment: VatPayment) {
  details.payment[s.id] = payment
  const row = items.value.find(x => x.id === s.id)
  if (row) {
    // Keep the list badge in step with the card (no QR in the list payload).
    row.payment = { ...payment, qr: null }
  }
}

/** The dashboard item "Zaplaťte DPH" links here with #vat-payment-<id>. */
function openFromHash() {
  const m = /^#vat-payment-(\d+)$/.exec(window.location.hash)
  if (!m || expanded.value !== null) return
  const target = items.value.find(x => x.id === Number(m[1]))
  if (target) void toggle(target)
}

/** Rows with something to show in the details: the filing facts, the payment card, the changed documents. */
function hasDetails(s: TaxSubmission): boolean {
  return isVatForm(s) && (s.filing_status !== 'generated' || !!s.payment)
}

function isVatForm(s: TaxSubmission): boolean {
  return (VAT_FORMS as readonly string[]).includes(s.form_code)
}

onMounted(load)
</script>

<template>
  <div class="max-w-6xl">
    <div class="flex items-center justify-between mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('reports.submissions.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('reports.submissions.subtitle') }}</p>
      </div>
      <button type="button" @click="load"
        class="cursor-pointer h-9 px-3 border border-neutral-300 hover:bg-neutral-50 text-sm rounded-md inline-flex items-center gap-1.5">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 0 0 4.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 0 1-15.357-2m15.357 2H15"/></svg>
        {{ t('common.refresh') }}
      </button>
    </div>

    <input ref="receiptInput" type="file" accept=".p7s,.pdf,.zfo" class="hidden" data-testid="receipt-input" @change="onReceiptPicked" />

    <div v-if="loading" class="bg-surface border border-neutral-200 rounded-lg p-8 text-center text-sm text-neutral-400">{{ t('common.loading') }}…</div>
    <div v-else-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm">{{ error }}</div>

    <div v-else-if="items.length === 0" class="bg-surface border border-dashed border-neutral-300 rounded-lg p-8 text-center text-sm text-neutral-500">
      {{ t('reports.submissions.empty') }}
    </div>

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
          <tr>
            <th class="text-left px-4 py-2.5">{{ t('reports.submissions.form') }}</th>
            <th class="text-left px-3 py-2.5">{{ t('reports.submissions.period') }}</th>
            <th class="text-left px-3 py-2.5">{{ t('reports.submissions.generated_at') }}</th>
            <th class="text-left px-3 py-2.5">{{ t('reports.filing.col_status') }}</th>
            <th class="text-center px-3 py-2.5">XSD</th>
            <th class="px-4 py-2.5"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <template v-for="s in items" :key="s.id">
            <tr class="hover:bg-neutral-50" :data-submission="s.id" :data-filing-status="s.filing_status">
              <td class="px-4 py-2.5 font-medium">
                {{ formCodeLabel(s.form_code) }}
                <span class="ml-1 text-xs font-mono text-neutral-500" :title="t('reports.filing.form_label')">{{ submissionFormLabel(s.submission_form) }}</span>
              </td>
              <td class="px-3 py-2.5 font-mono text-xs">{{ submissionPeriodLabel(s) }}</td>
              <td class="px-3 py-2.5 text-xs text-neutral-500">
                {{ new Date(s.generated_at).toLocaleString() }}
                <div class="font-mono">{{ Math.round(s.xml_size_bytes / 1024) }} KiB</div>
              </td>
              <td class="px-3 py-2.5">
                <span :class="['inline-block px-2 py-0.5 rounded text-xs font-medium', filingBadgeClass(s.filing_status)]" data-testid="filing-badge">
                  {{ t('reports.filing.status_' + s.filing_status) }}
                </span>
                <div v-if="s.filed_at" class="text-xs text-neutral-500 mt-0.5">
                  {{ formatDbDateTime(s.filed_at) }}
                  <template v-if="s.filing_channel"> · {{ t('reports.filing.channel_' + s.filing_channel) }}</template>
                  <template v-if="s.filing_reference"> · {{ t('reports.filing.reference_short', { ref: s.filing_reference }) }}</template>
                </div>
                <div v-if="s.payment && s.payment.kind === 'payment' && s.filing_status !== 'generated' && s.payment.state !== 'superseded'"
                  class="text-xs mt-0.5" :class="s.payment.state === 'unpaid' ? 'text-warning-700' : 'text-success-600'">
                  {{ t('reports.vat_payment.state_' + s.payment.state) }}
                </div>
              </td>
              <td class="px-3 py-2.5 text-center">
                <span :class="['inline-block px-2 py-0.5 rounded text-xs font-medium', statusBadgeClass(s.validation_status)]"
                  :title="s.validation_errors.length > 0 ? s.validation_errors.join('\n') : ''">
                  {{ s.validation_status === 'passed' ? '✓' : s.validation_status === 'failed' ? '✗' : '−' }}
                  {{ t('reports.submissions.status_' + s.validation_status) }}
                </span>
                <span v-if="s.validation_errors.length > 0" class="ml-1 text-xs text-danger-500">({{ s.validation_errors.length }})</span>
              </td>
              <td class="px-4 py-2.5 text-right">
                <div class="inline-flex items-center justify-end gap-1.5 flex-wrap">
                  <template v-if="isAdmin">
                    <button v-if="s.filing_status === 'generated'" type="button" @click="openFile(s)" data-testid="mark-filed"
                      class="cursor-pointer px-3 h-8 text-xs border border-primary-600 text-primary-700 hover:bg-primary-50 font-medium rounded-md">
                      {{ t('reports.filing.mark_filed') }}
                    </button>
                    <button v-if="s.filing_status === 'filed'" type="button" @click="pickReceipt(s)" :disabled="uploading" data-testid="upload-receipt"
                      class="cursor-pointer px-3 h-8 text-xs border border-primary-600 text-primary-700 hover:bg-primary-50 disabled:opacity-50 font-medium rounded-md">
                      {{ t('reports.filing.upload_receipt') }}
                    </button>
                  </template>
                  <a v-if="s.has_receipt" :href="taxFilingApi.receiptUrl(s.id)" data-testid="download-receipt"
                    class="px-3 h-8 text-xs border border-neutral-300 hover:bg-neutral-50 rounded-md inline-flex items-center"
                    :title="s.receipt_name ?? ''">
                    {{ t('reports.filing.download_receipt') }}
                  </a>
                  <button type="button" @click="downloadXml(s.id)" :title="t('reports.submissions.download_xml')"
                    class="cursor-pointer px-3 h-8 text-xs bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md inline-flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    XML
                  </button>
                  <button v-if="hasDetails(s)" type="button" @click="toggle(s)" data-testid="toggle-details"
                    class="cursor-pointer px-2 h-8 text-xs text-neutral-600 hover:bg-neutral-100 rounded-md">
                    {{ expanded === s.id ? t('reports.filing.hide_details') : t('reports.filing.show_details') }}
                  </button>
                  <button v-if="isAdmin && s.filing_status === 'generated'" type="button" @click="deleteItem(s.id)" :title="t('common.delete')"
                    class="cursor-pointer px-2 h-8 text-neutral-400 hover:text-danger-600 rounded-md inline-flex items-center">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                  </button>
                </div>
              </td>
            </tr>

            <!-- Details: filing facts, payment card, documents changed after filing -->
            <tr v-if="expanded === s.id" class="bg-neutral-50/60">
              <td colspan="6" class="px-4 py-4">
                <div class="space-y-4">
                  <div v-if="s.filing_status !== 'generated'" class="text-xs text-neutral-600 flex flex-wrap items-center gap-x-5 gap-y-1">
                    <span v-if="s.has_receipt" data-testid="receipt-meta">
                      {{ t('reports.filing.receipt') }}: <strong>{{ s.receipt_name }}</strong>
                      ({{ Math.max(1, Math.round((s.receipt_size_bytes ?? 0) / 1024)) }} KiB)
                      <span class="font-mono text-neutral-400" :title="s.receipt_sha256 ?? ''">SHA-256 {{ (s.receipt_sha256 ?? '').slice(0, 12) }}…</span>
                    </span>
                    <template v-if="isAdmin">
                      <button v-if="s.filing_status === 'confirmed'" type="button" class="cursor-pointer text-primary-700 hover:underline" @click="pickReceipt(s)">{{ t('reports.filing.replace_receipt') }}</button>
                      <button v-if="s.filing_status === 'confirmed'" type="button" class="cursor-pointer text-danger-500 hover:underline" @click="removeReceipt(s)">{{ t('reports.filing.remove_receipt') }}</button>
                      <button type="button" class="cursor-pointer text-danger-500 hover:underline" data-testid="unfile" @click="unfile(s)">{{ t('reports.filing.unfile') }}</button>
                    </template>
                  </div>
                  <VatPaymentCard v-if="details.payment[s.id]" :payment="details.payment[s.id]" :can-write="isAdmin"
                    @changed="p => onPaymentChanged(s, p)" />
                  <ChangedAfterFilingList v-if="details.changes[s.id]" :changes="details.changes[s.id]" />
                </div>
              </td>
            </tr>
          </template>
        </tbody>
        <tfoot class="bg-neutral-50">
          <tr>
            <td colspan="3" class="px-4 py-2 text-xs text-neutral-500">
              {{ items.length }} {{ t('reports.submissions.records') }}
            </td>
            <td colspan="3" class="px-3 py-2 text-right font-mono text-xs text-neutral-500">{{ Math.round(totalSize / 1024) }} KiB</td>
          </tr>
        </tfoot>
      </table>
    </div>

    <!-- Mark as filed -->
    <Modal v-if="fileTarget" :title="t('reports.filing.mark_filed_title')" width-class="max-w-lg" @close="fileTarget = null">
      <form id="file-form" class="space-y-4" @submit.prevent="submitFile">
        <p class="text-sm text-neutral-600">
          {{ formCodeLabel(fileTarget.form_code) }} · {{ submissionPeriodLabel(fileTarget) }} · {{ t('reports.filing.form_label') }} {{ submissionFormLabel(fileTarget.submission_form) }}
        </p>
        <label class="block text-sm">
          <span class="text-neutral-700">{{ t('reports.filing.filed_at') }}</span>
          <input v-model="fileForm.filed_at" type="datetime-local" required data-testid="filed-at"
            class="mt-1 block h-9 w-full px-3 border border-neutral-300 rounded-md bg-surface text-sm" />
        </label>
        <label class="block text-sm">
          <span class="text-neutral-700">{{ t('reports.filing.channel') }}</span>
          <select v-model="fileForm.channel" data-testid="filing-channel"
            class="mt-1 block h-9 w-full px-3 border border-neutral-300 rounded-md bg-surface text-sm">
            <option value="epo">{{ t('reports.filing.channel_epo') }}</option>
            <option value="data_box">{{ t('reports.filing.channel_data_box') }}</option>
            <option value="other">{{ t('reports.filing.channel_other') }}</option>
          </select>
        </label>
        <label class="block text-sm">
          <span class="text-neutral-700">{{ t('reports.filing.reference') }} <span class="text-neutral-400">{{ t('common.optional') }}</span></span>
          <input v-model="fileForm.reference" type="text" maxlength="100" data-testid="filing-reference"
            class="mt-1 block h-9 w-full px-3 border border-neutral-300 rounded-md bg-surface text-sm font-mono" />
          <span class="text-xs text-neutral-500">{{ t('reports.filing.reference_hint') }}</span>
        </label>
      </form>
      <template #footer>
        <button type="button" class="cursor-pointer h-9 px-4 border border-neutral-300 hover:bg-neutral-50 text-sm rounded-md" @click="fileTarget = null">{{ t('common.cancel') }}</button>
        <button type="submit" form="file-form" :disabled="filing" data-testid="file-submit"
          class="cursor-pointer h-9 px-4 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white text-sm font-medium rounded-md">
          {{ t('reports.filing.mark_filed') }}
        </button>
      </template>
    </Modal>
  </div>
</template>
