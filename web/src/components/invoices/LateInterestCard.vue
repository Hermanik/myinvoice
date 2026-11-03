<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useRouter, RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { invoicesApi, type LateInterestInfo } from '@/api/invoices'
import { apiErrorMessage } from '@/api/errors'
import { formatMoney, formatDate, formatPercent } from '@/composables/useFormat'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'

const props = defineProps<{
  invoiceId: number
  /** Changes whenever payments / reminders change, which triggers a reload. */
  refreshKey: string
}>()

const { t } = useI18n()
const router = useRouter()
const toast = useToast()
const auth = useAuthStore()

const info = ref<LateInterestInfo | null>(null)
const busy = ref(false)
const showBreakdown = ref(false)

async function load() {
  try {
    info.value = await invoicesApi.getLateInterest(props.invoiceId)
  } catch {
    info.value = null
  }
}
watch(() => [props.invoiceId, props.refreshKey], load, { immediate: true })

type Choice = 'inherit' | 'on' | 'off'
const choice = computed<Choice>({
  get: () => {
    const v = info.value?.invoice_override
    return v === null || v === undefined ? 'inherit' : v ? 'on' : 'off'
  },
  set: async (v) => {
    busy.value = true
    try {
      info.value = await invoicesApi.setLateInterest(props.invoiceId, v === 'inherit' ? null : v === 'on')
    } catch (e) {
      toast.error(apiErrorMessage(e))
    } finally { busy.value = false }
  },
})

const summary = computed(() => info.value?.summary ?? null)
const canBill = computed(() => !!summary.value
  && summary.value.billable.interest > 0
  && summary.value.rate_verified
  && auth.canWrite)

async function createInterestInvoice() {
  if (!summary.value || !canBill.value) return
  if (!confirm(t('late_interest.create_confirm', {
    amount: formatMoney(summary.value.billable.interest, summary.value.currency),
  }))) return
  busy.value = true
  try {
    const r = await invoicesApi.createLateInterestInvoice(props.invoiceId, {
      include_fixed_cost: summary.value.fixed_cost.claimable,
    })
    toast.success(t('late_interest.created'))
    r.warnings.forEach(w => toast.warning(w))
    router.push(`/invoices/${r.invoice.id}/edit`)
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally { busy.value = false }
}
</script>

<template>
  <div v-if="info" class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-3">
      <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('late_interest.title') }}</h3>
      <div v-if="auth.canWrite" class="flex items-center gap-2">
        <label class="text-sm text-neutral-600">{{ t('late_interest.override_label') }}</label>
        <select v-model="choice" :disabled="busy" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option value="inherit">{{ t('late_interest.override_inherit') }}</option>
          <option value="on">{{ t('late_interest.override_on') }}</option>
          <option value="off">{{ t('late_interest.override_off') }}</option>
        </select>
      </div>
    </div>

    <p class="text-xs text-neutral-500 mb-3">
      {{ info.enabled ? t('late_interest.applies_on') : t('late_interest.applies_off') }}
      ({{ t('late_interest.source_' + info.enabled_source) }})
    </p>

    <p v-if="!summary && info.reason === 'unsupported' && info.message" class="text-sm text-warning-700">{{ info.message }}</p>

    <template v-if="summary">
      <div v-if="!summary.rate_verified" class="mb-3 bg-warning-50 border border-warning-500/40 rounded-md p-3 text-sm text-warning-700">
        {{ t('late_interest.rate_unverified') }}
        <RouterLink v-if="auth.isAdmin" to="/admin/codebooks" class="underline">{{ t('late_interest.open_codebooks') }}</RouterLink>
      </div>

      <dl class="space-y-1.5 text-sm">
        <div class="flex justify-between">
          <dt class="text-neutral-500">{{ summary.settled_on ? t('late_interest.accrued_until_paid') : t('late_interest.accrued') }}</dt>
          <dd class="font-mono font-semibold">{{ formatMoney(summary.interest, summary.currency) }}</dd>
        </div>
        <div class="flex justify-between">
          <dt class="text-neutral-500">{{ t('late_interest.days') }}</dt>
          <dd class="font-mono">{{ summary.days }}</dd>
        </div>
        <div class="flex justify-between">
          <dt class="text-neutral-500">{{ t('late_interest.rate') }}</dt>
          <dd class="font-mono">{{ formatPercent(summary.annual_rate_percent) }}</dd>
        </div>
        <div v-if="summary.fixed_cost.claimable" class="flex justify-between">
          <dt class="text-neutral-500">{{ t('late_interest.fixed_cost') }}</dt>
          <dd class="font-mono">{{ formatMoney(summary.fixed_cost.amount_czk, 'CZK') }}</dd>
        </div>
      </dl>
      <p class="text-xs text-neutral-500 mt-2">
        {{ t('late_interest.rate_composition', { repo: summary.repo.rate_percent, date: formatDate(summary.repo.half_year_start) }) }}
        {{ t('late_interest.legal_basis') }}
      </p>

      <button type="button" @click="showBreakdown = !showBreakdown"
        class="cursor-pointer mt-3 text-sm text-primary-600 hover:text-primary-700">
        {{ showBreakdown ? t('late_interest.hide_breakdown') : t('late_interest.show_breakdown') }}
      </button>
      <div v-if="showBreakdown" class="mt-2 overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('late_interest.period') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('late_interest.days') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('late_interest.balance') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('late_interest.interest') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="(s, i) in summary.segments" :key="i">
              <td class="px-3 py-2">{{ formatDate(s.from) }} – {{ formatDate(s.to) }}</td>
              <td class="px-3 py-2 text-right font-mono">{{ s.days }}</td>
              <td class="px-3 py-2 text-right font-mono">{{ formatMoney(s.balance, summary.currency) }}</td>
              <td class="px-3 py-2 text-right font-mono">{{ formatMoney(s.interest, summary.currency) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <p v-if="summary.billed_through" class="text-sm text-neutral-600 mt-3">
        {{ t('late_interest.billed_through', { date: formatDate(summary.billed_through), amount: formatMoney(summary.billed_interest, summary.currency) }) }}
      </p>

      <ul v-if="info.claims.length" class="mt-3 space-y-1 text-sm">
        <li v-for="c in info.claims" :key="c.invoice_id">
          <RouterLink :to="`/invoices/${c.invoice_id}`" class="text-primary-600 hover:underline">
            {{ c.varsymbol || t('late_interest.draft') }}
          </RouterLink>
          <span class="text-neutral-500">
            · {{ formatDate(c.period_from) }} – {{ formatDate(c.period_to) }}
            · {{ formatMoney(c.interest_amount + (c.fixed_cost_amount ?? 0), summary.currency) }}
          </span>
        </li>
      </ul>

      <div v-if="auth.canWrite" class="mt-4">
        <button type="button" @click="createInterestInvoice" :disabled="!canBill || busy"
          class="cursor-pointer h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md disabled:opacity-50 disabled:cursor-not-allowed">
          {{ busy ? '…' : t('late_interest.create_button') }}
        </button>
      </div>
    </template>
  </div>
</template>
