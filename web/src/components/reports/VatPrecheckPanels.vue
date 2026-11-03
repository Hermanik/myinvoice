<script setup lang="ts">
/**
 * Pre-export panels of the DPH / KH / SH pages: the filing state of the period, "Kontrola souladu",
 * "Doklady změněné po podání" and — on the DPH page — the "Platba DPH" card of a filed return.
 * One request ({@see taxFilingApi.vatChecks}) feeds all of them; it reloads when the period changes.
 */
import { ref, watch, onMounted, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { taxFilingApi, type VatChecks, type VatPayment } from '@/api/taxFiling'
import type { VatForm } from '@/utils/taxFiling'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { formatDate } from '@/composables/useFormat'
import VatCrossCheckPanel from './VatCrossCheckPanel.vue'
import ChangedAfterFilingList from './ChangedAfterFilingList.vue'
import VatPaymentCard from './VatPaymentCard.vue'

const props = defineProps<{
  form: VatForm
  year: number
  month: number
  period: 'monthly' | 'quarterly'
}>()

const { t } = useI18n()
const auth = useAuthStore()
const data = ref<VatChecks | null>(null)
const error = ref('')
// A response of a stale period must never overwrite a fresher one.
let seq = 0

async function load() {
  const mine = ++seq
  error.value = ''
  try {
    const r = await taxFilingApi.vatChecks(props.form, props.year, props.month, props.period)
    if (mine === seq) data.value = r
  } catch (e) {
    if (mine === seq) {
      data.value = null
      error.value = apiErrorMessage(e)
    }
  }
}

const filing = computed(() => data.value?.filing ?? null)

/** A payment change touches the payment card only — the cross-check and the changes list stay as they are. */
async function reloadPayment(changed: VatPayment) {
  if (!data.value) return
  try {
    data.value.payment = await taxFilingApi.payment(changed.submission_id)
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

watch(() => [props.form, props.year, props.month, props.period], load)
onMounted(load)
</script>

<template>
  <div class="space-y-4" data-testid="vat-precheck">
    <div v-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm">{{ error }}</div>

    <template v-if="data">
      <!-- Filing state of this return -->
      <div v-if="filing" class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm bg-success-50 border border-success-500/30 rounded-md px-4 py-2.5" data-testid="filing-banner">
        <span class="font-medium text-success-700">
          {{ t('reports.filing.status_' + filing.filing_status) }}
          <template v-if="filing.filed_at"> · {{ formatDate(filing.filed_at) }}</template>
        </span>
        <span v-if="filing.filing_channel" class="text-neutral-600">{{ t('reports.filing.channel_' + filing.filing_channel) }}</span>
        <span v-if="filing.filing_reference" class="text-neutral-600">{{ t('reports.filing.reference_short', { ref: filing.filing_reference }) }}</span>
        <RouterLink to="/reports/submissions" class="text-primary-700 hover:underline ml-auto">{{ t('reports.filing.open_archive') }} →</RouterLink>
      </div>

      <VatCrossCheckPanel :check="data.cross_check" />
      <ChangedAfterFilingList v-if="data.changed_after_filing" :changes="data.changed_after_filing" />
      <VatPaymentCard v-if="data.payment && data.payment.kind !== 'none'" :payment="data.payment"
        :can-write="auth.isAdmin" @changed="reloadPayment" />
    </template>
  </div>
</template>
