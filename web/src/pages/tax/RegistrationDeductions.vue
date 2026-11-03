<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  registrationDeductionsApi,
  type RegistrationAssetType,
  type RegistrationDeductionDraft,
  type RegistrationDeductionEvent,
  type RegistrationDeductionInput,
  type RegistrationDeductionItem,
  type RegistrationDeductionOverview,
} from '@/api/registrationDeductions'
import { purchaseInvoicesApi } from '@/api/purchaseInvoices'
import { apiErrorMessage } from '@/api/errors'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { submissionPeriodLabel } from '@/utils/taxFiling'
import { useToast } from '@/composables/useToast'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { useAuthStore } from '@/stores/auth'

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()

const overview = ref<RegistrationDeductionOverview | null>(null)
const loading = ref(true)
const error = ref('')
const saving = ref(false)

// Writes are admin-only on the server; the page is read-only for everybody else.
const canEdit = computed(() => auth.isAdmin)

const ASSET_TYPES: RegistrationAssetType[] = ['inventory', 'long_term_asset', 'other']
const assetLabel = (type: RegistrationAssetType) => t(`regded.asset_${type}`)
const eventKey = (e: RegistrationDeductionEvent) => `${e.kind}:${e.date}`

// Only the first load shows the spinner; later refreshes are silent so open forms and cards stay mounted.
async function load() {
  error.value = ''
  try {
    overview.value = await registrationDeductionsApi.list()
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    loading.value = false
  }
}

// The return the amount lands in (monthly "YYYY-MM" or quarterly "YYYY Qn").
function periodLabel(e: RegistrationDeductionEvent): string {
  const p = e.return_period
  return submissionPeriodLabel({ period_year: p.year, period_month: p.quarter === null ? p.month : null, period_quarter: p.quarter })
}

// Runs a write with the saving flag on; errors become a toast. Resolves to whether it succeeded.
async function withSaving(fn: () => Promise<void>): Promise<boolean> {
  saving.value = true
  try {
    await fn()
    return true
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
    return false
  } finally {
    saving.value = false
  }
}

// ---- add / edit form (one at a time) ----
interface FormState {
  key: string
  kind: 'registration' | 'deregistration'
  eventDate: string
  itemId: number | null
  description: string
  asset_type: RegistrationAssetType
  acquired_on: string
  tax_base: number | null
  vat_amount: number | null
  adjustment_years: 5 | 10
  in_business_assets: boolean
  purchase_invoice_id: number | null
  source_item_id: number | null
  note: string
}

const form = ref<FormState | null>(null)
const formEvent = computed(() => overview.value?.events.find(e => eventKey(e) === form.value?.key) ?? null)

function openAdd(e: RegistrationDeductionEvent) {
  form.value = {
    key: eventKey(e), kind: e.kind, eventDate: e.date, itemId: null,
    description: '', asset_type: 'inventory', acquired_on: '', tax_base: null, vat_amount: null,
    adjustment_years: 5, in_business_assets: true, purchase_invoice_id: null, source_item_id: null, note: '',
  }
  resetInvoicePicker(null)
}

function openEdit(e: RegistrationDeductionEvent, item: RegistrationDeductionItem) {
  form.value = {
    key: eventKey(e), kind: item.kind, eventDate: item.event_date, itemId: item.id,
    description: item.description, asset_type: item.asset_type, acquired_on: item.acquired_on,
    tax_base: item.tax_base, vat_amount: item.vat_amount, adjustment_years: item.adjustment_years ?? 5,
    in_business_assets: item.in_business_assets, purchase_invoice_id: item.purchase_invoice_id,
    source_item_id: item.source_item_id, note: item.note ?? '',
  }
  resetInvoicePicker(item.purchase_invoice_id === null ? null : { value: item.purchase_invoice_id, label: item.purchase_invoice_number || `#${item.purchase_invoice_id}` })
}

// ---- received invoice picker (remote search, same pattern as VendorPicker) ----
type InvoiceOption = { value: number; label: string; secondary?: string }
const invoiceOptions = ref<InvoiceOption[]>([])
const invoiceLoading = ref(false)
const invoiceSelected = ref<InvoiceOption | null>(null)

function resetInvoicePicker(selected: InvoiceOption | null) {
  invoiceOptions.value = []
  invoiceSelected.value = selected
}

async function searchInvoices(q: string) {
  invoiceLoading.value = true
  try {
    const res = await purchaseInvoicesApi.listGrouped({ q: q || undefined, per_page: 20 })
    invoiceOptions.value = res.data.flatMap(g => g.invoices).map(i => ({
      value: i.id,
      label: i.vendor_invoice_number,
      secondary: `${i.vendor_company_name} · ${formatDate(i.issue_date)}`,
    }))
  } catch { /* the picker simply shows no results */ } finally {
    invoiceLoading.value = false
  }
}

function onInvoiceChange(id: number | null) {
  if (form.value) form.value.purchase_invoice_id = id
  invoiceSelected.value = id === null ? null : (invoiceOptions.value.find(o => o.value === id) ?? { value: id, label: `#${id}` })
}

// Registration: the asset must be acquired inside the 12-month window before the registration.
const acquiredMin = computed(() => form.value?.kind === 'registration' ? formEvent.value?.window?.start : undefined)
const acquiredMax = computed(() => form.value?.kind === 'registration' ? formEvent.value?.window?.end : undefined)

async function submitForm() {
  const f = form.value
  if (!f) return
  if (!f.description.trim() || !f.acquired_on || f.tax_base === null || f.vat_amount === null) {
    toast.error(t('regded.form_required'))
    return
  }
  const body: RegistrationDeductionInput = {
    kind: f.kind,
    event_date: f.eventDate,
    description: f.description.trim(),
    asset_type: f.asset_type,
    acquired_on: f.acquired_on,
    tax_base: f.tax_base,
    vat_amount: f.vat_amount,
    adjustment_years: f.asset_type === 'long_term_asset' ? f.adjustment_years : null,
    in_business_assets: f.in_business_assets,
    purchase_invoice_id: f.purchase_invoice_id || null,
    source_item_id: f.source_item_id,
    note: f.note.trim() || null,
  }
  const ok = await withSaving(async () => {
    if (f.itemId === null) await registrationDeductionsApi.create(body)
    else await registrationDeductionsApi.update(f.itemId, body)
  })
  if (!ok) return
  toast.success(t('regded.saved'))
  form.value = null
  await load()
}

async function removeItem(item: RegistrationDeductionItem) {
  if (!window.confirm(t('regded.delete_confirm', { description: item.description }))) return
  if (!await withSaving(() => registrationDeductionsApi.remove(item.id).then(() => undefined))) return
  toast.success(t('regded.deleted'))
  await load()
}

// ---- deregistration: suggested reductions (section 79a, computed by the server) ----
// The draft is posted as is (kind 'deregistration', source_item_id included); the reload drops it from the suggestions.
async function addSuggestions(drafts: RegistrationDeductionDraft[]) {
  const ok = await withSaving(async () => {
    for (const draft of drafts) await registrationDeductionsApi.create({ ...draft, kind: 'deregistration' })
  })
  if (ok) toast.success(t('regded.saved'))
  await load()
}

onMounted(load)
</script>

<template>
  <div class="max-w-5xl space-y-4">
    <div>
      <h1 class="text-2xl font-semibold">{{ t('regded.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('regded.subtitle') }}</p>
      <p class="text-xs text-neutral-500 mt-2">{{ t('regded.explain_79') }}</p>
      <p class="text-xs text-neutral-500 mt-1">{{ t('regded.explain_79a') }}</p>
    </div>

    <div v-if="loading" class="bg-surface border border-neutral-200 rounded-lg p-8 text-center text-sm text-neutral-400">{{ t('common.loading') }}…</div>
    <div v-else-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm">{{ error }}</div>

    <template v-else-if="overview">
      <!-- Empty state: no registration row in the VAT history -->
      <div v-if="overview.events.length === 0" class="bg-surface border border-dashed border-neutral-300 rounded-lg p-8 text-center text-sm text-neutral-600" data-testid="regded-empty">
        <p>{{ t('regded.empty') }}</p>
        <RouterLink to="/admin/settings" class="inline-block mt-3 font-medium text-primary-700 hover:underline">{{ t('regded.empty_link') }} →</RouterLink>
      </div>

      <!-- Items whose registration/deregistration row no longer exists -->
      <div v-if="overview.orphaned.length > 0" class="bg-warning-50 border border-warning-500/40 rounded-md p-3 text-sm text-warning-700" data-testid="regded-orphaned">
        <p class="font-medium">{{ t('regded.orphaned_title') }}</p>
        <p class="text-xs mt-0.5">{{ t('regded.orphaned_hint') }}</p>
        <ul class="mt-2 space-y-1">
          <li v-for="item in overview.orphaned" :key="item.id" class="flex items-center justify-between gap-3">
            <span>{{ formatDate(item.event_date) }} · {{ item.description }} · {{ formatMoney(item.deduction_amount, 'CZK') }}</span>
            <button v-if="canEdit" type="button" class="cursor-pointer text-xs text-danger-600 hover:text-danger-500" @click="removeItem(item)">{{ t('common.delete') }}</button>
          </li>
        </ul>
      </div>

      <section v-for="event in overview.events" :key="eventKey(event)" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200 bg-neutral-50">
          <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-700">
            {{ event.kind === 'registration' ? t('regded.event_registration', { date: formatDate(event.date) }) : t('regded.event_deregistration', { date: formatDate(event.date) }) }}
          </h2>
          <p v-if="event.kind === 'registration' && event.window" class="text-xs text-neutral-500 mt-1">
            {{ t('regded.window', { from: formatDate(event.window.start), to: formatDate(event.window.end) }) }}
          </p>
          <p v-else-if="event.last_day" class="text-xs text-neutral-500 mt-1">{{ t('regded.last_day', { date: formatDate(event.last_day) }) }}</p>
          <p class="text-xs text-neutral-500 mt-0.5">
            {{ event.kind === 'registration' ? t('regded.lands_in_deduction', { period: periodLabel(event) }) : t('regded.lands_in_reduction', { period: periodLabel(event) }) }}
          </p>
        </header>

        <div v-if="event.filed_return" class="mx-5 mt-4 rounded-md border border-warning-500/50 bg-warning-50 text-warning-700 px-3 py-2 text-sm" data-testid="regded-filed-warning">
          {{ t('regded.filed_warning', { period: periodLabel(event) }) }}
          <RouterLink to="/reports/dph" class="font-medium underline">{{ t('regded.filed_link') }} →</RouterLink>
        </div>

        <div class="p-5 space-y-4">
          <div v-if="event.items.length === 0" class="text-sm text-neutral-500">{{ t('regded.no_items') }}</div>
          <div v-else class="overflow-x-auto border border-neutral-200 rounded-md">
            <table class="w-full text-sm">
              <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                <tr>
                  <th class="text-left px-3 py-2">{{ t('regded.col_description') }}</th>
                  <th class="text-left px-3 py-2">{{ t('regded.col_type') }}</th>
                  <th class="text-left px-3 py-2">{{ t('regded.col_acquired') }}</th>
                  <th class="text-right px-3 py-2">{{ t('regded.col_base') }}</th>
                  <th class="text-right px-3 py-2">{{ t('regded.col_vat') }}</th>
                  <th class="text-right px-3 py-2">{{ event.kind === 'registration' ? t('regded.col_deduction') : t('regded.col_reduction') }}</th>
                  <th v-if="canEdit" class="px-3 py-2 w-28"></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="item in event.items" :key="item.id" :class="item.in_business_assets ? 'hover:bg-neutral-50' : 'text-neutral-400 bg-neutral-50'">
                  <td class="px-3 py-2">
                    {{ item.description }}
                    <div v-if="item.purchase_invoice_id" class="text-xs">
                      <RouterLink :to="`/purchase-invoices/${item.purchase_invoice_id}`" class="text-primary-700 hover:underline">{{ item.purchase_invoice_number || `#${item.purchase_invoice_id}` }}</RouterLink>
                    </div>
                    <div v-if="item.note" class="text-xs text-neutral-500">{{ item.note }}</div>
                    <div v-if="!item.in_business_assets" class="text-xs">{{ t('regded.not_in_business_assets') }}</div>
                  </td>
                  <td class="px-3 py-2 whitespace-nowrap">
                    {{ assetLabel(item.asset_type) }}
                    <span v-if="item.adjustment_years" class="text-xs text-neutral-500">({{ t('regded.years', { n: item.adjustment_years }) }})</span>
                  </td>
                  <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(item.acquired_on) }}</td>
                  <td class="px-3 py-2 text-right font-mono">{{ formatMoney(item.tax_base, 'CZK') }}</td>
                  <td class="px-3 py-2 text-right font-mono">{{ formatMoney(item.vat_amount, 'CZK') }}</td>
                  <td class="px-3 py-2 text-right font-mono font-medium">{{ formatMoney(item.deduction_amount, 'CZK') }}</td>
                  <td v-if="canEdit" class="px-3 py-2 text-right whitespace-nowrap">
                    <button type="button" class="cursor-pointer text-xs text-primary-700 hover:underline mr-3" @click="openEdit(event, item)">{{ t('common.edit') }}</button>
                    <button type="button" class="cursor-pointer text-xs text-danger-600 hover:text-danger-500" @click="removeItem(item)">{{ t('common.delete') }}</button>
                  </td>
                </tr>
              </tbody>
              <tfoot class="bg-neutral-50">
                <tr>
                  <td :colspan="5" class="px-3 py-2 text-right text-xs uppercase tracking-wide text-neutral-500">
                    {{ event.kind === 'registration' ? t('regded.total_deduction') : t('regded.total_reduction') }}
                  </td>
                  <td class="px-3 py-2 text-right font-mono font-bold">{{ formatMoney(event.total, 'CZK') }}</td>
                  <td v-if="canEdit"></td>
                </tr>
              </tfoot>
            </table>
          </div>

          <!-- Deregistration: suggested reductions -->
          <div v-if="event.suggestions?.length && canEdit" class="border border-neutral-200 rounded-md p-3" data-testid="regded-suggestions">
            <div class="flex items-center justify-between gap-3 flex-wrap">
              <div>
                <p class="text-sm font-medium">{{ t('regded.suggest_title') }}</p>
                <p class="text-xs text-neutral-500">{{ t('regded.suggest_count', { n: event.suggestions.length }) }}</p>
              </div>
            </div>
            <ul class="mt-3 divide-y divide-neutral-100 text-sm">
              <li v-for="(draft, i) in event.suggestions" :key="i" class="py-2 flex items-center justify-between gap-3">
                <span>
                  {{ draft.description }}
                  <span class="text-xs text-neutral-500">· {{ assetLabel(draft.asset_type) }} · {{ formatMoney(draft.deduction_amount, 'CZK') }}</span>
                </span>
                <button type="button" :disabled="saving" class="cursor-pointer text-xs text-primary-700 hover:underline disabled:opacity-50" @click="addSuggestions([draft])">{{ t('regded.suggest_add') }}</button>
              </li>
            </ul>
            <button type="button" :disabled="saving" class="cursor-pointer mt-3 h-9 px-3 rounded-md bg-primary-600 text-white text-sm hover:bg-primary-700 disabled:opacity-50" @click="addSuggestions(event.suggestions)">
              {{ t('regded.suggest_add_all') }}
            </button>
          </div>

          <!-- Add / edit form -->
          <form v-if="form && form.key === eventKey(event)" class="border border-neutral-200 rounded-md p-4 grid grid-cols-1 md:grid-cols-6 gap-3" data-testid="regded-form" @submit.prevent="submitForm">
            <div class="md:col-span-4">
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('regded.field_description') }}</label>
              <input v-model="form.description" type="text" maxlength="255" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface" />
            </div>
            <div class="md:col-span-2">
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('regded.field_type') }}</label>
              <select v-model="form.asset_type" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
                <option v-for="a in ASSET_TYPES" :key="a" :value="a">{{ assetLabel(a) }}</option>
              </select>
            </div>
            <div class="md:col-span-2">
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('regded.field_acquired') }}</label>
              <input v-model="form.acquired_on" type="date" :min="acquiredMin" :max="acquiredMax" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface" />
            </div>
            <div class="md:col-span-2">
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('regded.field_base') }}</label>
              <input v-model.number="form.tax_base" type="number" step="0.01" min="0" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface text-right" />
            </div>
            <div class="md:col-span-2">
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('regded.field_vat') }}</label>
              <input v-model.number="form.vat_amount" type="number" step="0.01" min="0" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface text-right" />
              <p class="text-xs text-neutral-500 mt-1">{{ t('regded.field_vat_hint') }}</p>
            </div>
            <div v-if="form.asset_type === 'long_term_asset'" class="md:col-span-2">
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('regded.field_adjustment') }}</label>
              <select v-model.number="form.adjustment_years" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
                <option :value="5">{{ t('regded.years', { n: 5 }) }}</option>
                <option :value="10">{{ t('regded.years', { n: 10 }) }}</option>
              </select>
              <p class="text-xs text-neutral-500 mt-1">{{ t('regded.field_adjustment_hint') }}</p>
            </div>
            <div class="md:col-span-2">
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('regded.field_invoice') }}</label>
              <SearchableSelect
                :model-value="form.purchase_invoice_id"
                remote
                :loading="invoiceLoading"
                :options="invoiceOptions"
                :selected-option="invoiceSelected"
                :placeholder="t('regded.field_invoice_placeholder')"
                @search="searchInvoices"
                @update:model-value="onInvoiceChange"
              />
              <p class="text-xs text-neutral-500 mt-1">{{ t('regded.field_invoice_hint') }}</p>
            </div>
            <div class="md:col-span-4">
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('regded.field_note') }}</label>
              <input v-model="form.note" type="text" maxlength="500" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface" />
            </div>
            <label class="md:col-span-6 inline-flex items-center gap-2 text-sm text-neutral-700">
              <input v-model="form.in_business_assets" type="checkbox" class="rounded border-neutral-300" />
              {{ form.kind === 'registration' ? t('regded.field_in_assets_registration') : t('regded.field_in_assets_deregistration') }}
            </label>
            <div class="md:col-span-6 flex gap-2">
              <button type="submit" :disabled="saving" class="cursor-pointer h-9 px-4 rounded-md bg-primary-600 text-white text-sm hover:bg-primary-700 disabled:opacity-50">{{ t('common.save') }}</button>
              <button type="button" class="cursor-pointer h-9 px-4 rounded-md border border-neutral-300 text-sm hover:bg-neutral-50" @click="form = null">{{ t('common.cancel') }}</button>
            </div>
          </form>
          <button v-else-if="canEdit" type="button" class="cursor-pointer h-9 px-4 rounded-md border border-neutral-300 text-sm hover:bg-neutral-50" data-testid="regded-add" @click="openAdd(event)">
            {{ t('regded.add') }}
          </button>
        </div>
      </section>
    </template>
  </div>
</template>
