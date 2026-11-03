<script setup lang="ts">
/**
 * "Platba DPH" card of a DPH return: the own tax liability (row 64) with due date, the tax
 * office's VAT account, variable symbol and a QR payment — or, for an excess deduction (row 66),
 * the refund. A filed return can be marked as paid by hand; a payment found on the bank
 * statement marks itself.
 */
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { taxFilingApi, type VatPayment } from '@/api/taxFiling'
import { appIsoDate } from '@/utils/date'
import { apiErrorMessage } from '@/api/errors'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'

const props = defineProps<{
  payment: VatPayment
  /** Admin only: the buttons that change the payment state. */
  canWrite: boolean
}>()
const emit = defineEmits<{ (e: 'changed', payment: VatPayment): void }>()

const { t } = useI18n()
const toast = useToast()

const busy = ref(false)
const paying = ref(false)
const paidAt = ref('')
const paidAmount = ref<number | null>(null)

const daysToDue = computed(() => {
  const due = new Date(props.payment.due_date + 'T00:00:00')
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  return Math.round((due.getTime() - today.getTime()) / 86_400_000)
})

const stateClass = computed(() => ({
  unpaid: 'bg-warning-50 text-warning-700',
  paid: 'bg-success-50 text-success-600',
  refund: 'bg-success-50 text-success-600',
  not_filed: 'bg-neutral-100 text-neutral-500',
  superseded: 'bg-neutral-100 text-neutral-500',
  none: 'bg-neutral-100 text-neutral-500',
} as Record<string, string>)[props.payment.state])

async function copy(text: string) {
  try {
    await navigator.clipboard.writeText(text)
    toast.success(t('reports.vat_payment.copied'))
  } catch {
    toast.error(t('reports.vat_payment.copy_failed'))
  }
}

function startPaying() {
  paidAt.value = appIsoDate()
  paidAmount.value = props.payment.amount_due
  paying.value = true
}

async function markPaid() {
  busy.value = true
  try {
    const updated = await taxFilingApi.markPaid(props.payment.submission_id, {
      paid_at: paidAt.value || undefined,
      amount: paidAmount.value ?? undefined,
    })
    paying.value = false
    toast.success(t('reports.vat_payment.marked_paid'))
    emit('changed', updated)
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

async function clearPaid() {
  if (!confirm(t('reports.vat_payment.clear_confirm'))) return
  busy.value = true
  try {
    emit('changed', await taxFilingApi.clearPaid(props.payment.submission_id))
    toast.success(t('reports.vat_payment.cleared'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden"
    :id="`vat-payment-${payment.submission_id}`" data-testid="vat-payment-card">
    <header class="px-5 py-3 border-b border-neutral-200 bg-neutral-50 flex items-center justify-between gap-3 flex-wrap">
      <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-700">
        {{ t('reports.vat_payment.title') }} <span class="font-mono normal-case text-neutral-500">{{ payment.period_label }}</span>
      </h3>
      <span :class="['px-2 py-0.5 rounded text-xs font-medium', stateClass]">
        {{ t('reports.vat_payment.state_' + payment.state) }}
        <template v-if="payment.state === 'paid' && payment.paid">
          · {{ formatDate(payment.paid.at) }} · {{ t('reports.vat_payment.source_' + payment.paid.source) }}
        </template>
      </span>
    </header>

    <!-- Excess deduction: nothing to pay, the tax office refunds -->
    <div v-if="payment.kind === 'refund'" class="p-5 space-y-1">
      <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium">{{ t('reports.vat_payment.refund') }}</div>
      <div class="text-2xl font-bold font-mono text-success-600">{{ formatMoney(payment.amount, 'CZK') }}</div>
      <p class="text-sm text-neutral-600">{{ t('reports.vat_payment.refund_hint') }}</p>
    </div>

    <div v-else-if="payment.kind === 'payment'" class="p-5 grid gap-5 md:grid-cols-[1fr_auto]">
      <div class="space-y-3">
        <div class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
          <div>
            <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium">{{ t('reports.vat_payment.amount') }}</div>
            <div class="text-xl font-bold font-mono text-danger-500" data-testid="vat-payment-amount">{{ formatMoney(payment.amount_due, 'CZK') }}</div>
            <div v-if="payment.amount_due !== payment.amount" class="text-xs text-neutral-500">
              {{ t('reports.vat_payment.amount_of', { total: formatMoney(payment.amount, 'CZK') }) }}
            </div>
          </div>
          <div>
            <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium">{{ t('reports.vat_payment.due') }}</div>
            <div class="text-xl font-bold font-mono"
              :class="payment.state === 'unpaid' && daysToDue < 0 ? 'text-danger-500' : 'text-neutral-900'">{{ formatDate(payment.due_date) }}</div>
            <div v-if="payment.state === 'unpaid'" class="text-xs"
              :class="daysToDue < 0 ? 'text-danger-500' : daysToDue <= 7 ? 'text-warning-600' : 'text-neutral-500'">
              <template v-if="daysToDue >= 0">{{ t('reports.dph.deadline_in', { n: daysToDue }) }}</template>
              <template v-else>{{ t('reports.dph.deadline_passed', { n: Math.abs(daysToDue) }) }}</template>
            </div>
          </div>
          <div class="col-span-2">
            <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium">{{ t('reports.vat_payment.account') }}</div>
            <div v-if="payment.account" class="flex items-center gap-2 flex-wrap">
              <span class="font-mono text-base" data-testid="vat-payment-account">{{ payment.account.display }}</span>
              <button type="button" class="cursor-pointer text-xs text-primary-600 hover:underline" @click="copy(payment.account.display)">{{ t('reports.vat_payment.copy') }}</button>
            </div>
            <div v-if="payment.account" class="text-xs text-neutral-500 font-mono break-all">{{ payment.account.iban }}</div>
            <div v-if="payment.office" class="text-xs text-neutral-500">{{ payment.office.name }} ({{ payment.office.code }})</div>
            <div v-if="!payment.account" class="text-sm text-neutral-400">—</div>
          </div>
          <div>
            <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium">{{ t('reports.vat_payment.variable_symbol') }}</div>
            <div v-if="payment.variable_symbol" class="flex items-center gap-2">
              <span class="font-mono text-base" data-testid="vat-payment-vs">{{ payment.variable_symbol }}</span>
              <button type="button" class="cursor-pointer text-xs text-primary-600 hover:underline" @click="copy(payment.variable_symbol)">{{ t('reports.vat_payment.copy') }}</button>
            </div>
            <div v-else class="text-sm text-neutral-400">—</div>
          </div>
        </div>

        <ul v-if="payment.warnings.length" class="text-sm text-warning-700 bg-warning-50 border border-warning-500/40 rounded-md p-3 list-disc list-inside space-y-0.5">
          <li v-for="w in payment.warnings" :key="w.code">{{ t('reports.vat_payment.warning_' + w.code) }}</li>
        </ul>
        <p v-if="payment.state === 'not_filed'" class="text-xs text-neutral-500">{{ t('reports.vat_payment.not_filed_hint') }}</p>
        <p v-else-if="payment.state === 'superseded'" class="text-xs text-neutral-500">{{ t('reports.vat_payment.superseded_hint') }}</p>
        <p v-else-if="payment.state === 'unpaid'" class="text-xs text-neutral-500">{{ t('reports.vat_payment.recognition_hint') }}</p>

        <div v-if="canWrite" class="flex items-center gap-2 flex-wrap pt-1">
          <template v-if="payment.state === 'unpaid' && !paying">
            <button type="button" :disabled="busy" @click="startPaying"
              class="cursor-pointer h-8 px-3 text-xs bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
              {{ t('reports.vat_payment.mark_paid') }}
            </button>
          </template>
          <form v-if="paying" class="flex items-end gap-2 flex-wrap" @submit.prevent="markPaid">
            <label class="text-xs text-neutral-600">{{ t('reports.vat_payment.paid_on') }}
              <input v-model="paidAt" type="date" required class="block h-8 px-2 border border-neutral-300 rounded-md bg-surface text-sm" />
            </label>
            <label class="text-xs text-neutral-600">{{ t('reports.vat_payment.paid_amount') }}
              <input v-model.number="paidAmount" type="number" min="1" step="1" required class="block h-8 w-28 px-2 border border-neutral-300 rounded-md bg-surface text-sm font-mono" />
            </label>
            <button type="submit" :disabled="busy" class="cursor-pointer h-8 px-3 text-xs bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">{{ t('common.save') }}</button>
            <button type="button" class="cursor-pointer h-8 px-3 text-xs border border-neutral-300 hover:bg-neutral-50 rounded-md" @click="paying = false">{{ t('common.cancel') }}</button>
          </form>
          <button v-if="payment.state === 'paid' && payment.paid" type="button" :disabled="busy" @click="clearPaid"
            class="cursor-pointer h-8 px-3 text-xs border border-neutral-300 hover:bg-neutral-50 rounded-md">
            {{ t('reports.vat_payment.clear_paid') }}
          </button>
        </div>
      </div>

      <div v-if="payment.qr && payment.state !== 'superseded'" class="flex flex-col items-center gap-1">
        <img :src="payment.qr" :alt="t('reports.vat_payment.qr_alt')" class="w-40 h-40 border border-neutral-200 rounded-md bg-white" data-testid="vat-payment-qr" />
        <span class="text-xs text-neutral-500">{{ t('reports.vat_payment.qr_caption') }}</span>
      </div>
    </div>
  </section>
</template>
