<script setup lang="ts">
/**
 * VAT of the last closed period for the accountant: the filing deadline, the state of the DPH / KH / SH filings
 * from the archive, the output and input VAT of the return and links into the reports. A supplier that is not a VAT
 * payer in that period (VAT may start only next year) gets a short explanation instead of empty figures.
 */
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import type { AccountantFilingStatus, AccountantVat } from '@/api/dashboard'
import { formatDate, formatMoney, formatMonth } from '@/composables/useFormat'
import { deadlineTone, monthKey, periodQuery } from '@/utils/accountantDashboard'

const props = defineProps<{ vat: AccountantVat }>()
const { t } = useI18n()

const period = computed(() => props.vat.period)
const periodText = computed(() => period.value.type === 'quarterly'
  ? t('timeline.quarter_label', { q: period.value.quarter, year: period.value.year })
  : formatMonth(monthKey(period.value.year, period.value.month)))
const query = computed(() => periodQuery(period.value.year, period.value.month))

const FORM_ROUTES = { dph: '/reports/dph', kh: '/reports/kh', sh: '/reports/shv' } as const
const forms = computed(() => (props.vat.forms ?? []).map(f => ({ ...f, to: `${FORM_ROUTES[f.type]}?${query.value}` })))

const tone = computed(() => deadlineTone(props.vat.days_left ?? 0, props.vat.forms ?? []))
const TONE_CLASS = { done: 'text-success-600', overdue: 'text-danger-500 font-medium', soon: 'text-warning-600 font-medium', normal: 'text-neutral-500' } as const
const daysText = computed(() => {
  const n = props.vat.days_left ?? 0
  if (tone.value === 'done') return t('accountant_dashboard.vat.all_filed')
  return n > 0 ? t('timeline.in_days', { n }) : n === 0 ? t('timeline.today') : t('timeline.overdue_days', { n: Math.abs(n) })
})

const BADGE: Record<AccountantFilingStatus, string> = {
  unfiled: 'bg-warning-50 text-warning-600',
  filed: 'bg-primary-50 text-primary-700',
  confirmed: 'bg-success-50 text-success-600',
}

const figures = computed(() => props.vat.return ?? null)
/** Own tax liability (positive) or excess deduction (negative) of the return. */
const taxDue = computed(() => figures.value?.tax_due ?? 0)
</script>

<template>
  <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm" data-testid="accountant-vat">
    <header class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between gap-3">
      <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-600">
        {{ t('accountant_dashboard.vat.title', { period: periodText }) }}
      </h3>
      <RouterLink to="/reports/submissions" class="text-xs text-primary-600 hover:underline shrink-0">
        {{ t('nav.reports_submissions') }} →
      </RouterLink>
    </header>

    <div v-if="!vat.applicable" class="px-5 py-6 text-sm text-neutral-600" data-testid="accountant-vat-empty">
      <p v-if="vat.payer_since">{{ t('accountant_dashboard.vat.not_yet_payer', { date: formatDate(vat.payer_since) }) }}</p>
      <p v-else>{{ t('accountant_dashboard.vat.not_payer') }}</p>
    </div>

    <div v-else class="p-5 space-y-4">
      <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
        <span class="text-xs uppercase tracking-wide text-neutral-500">{{ t('accountant_dashboard.vat.deadline') }}</span>
        <span class="text-base font-semibold font-mono text-neutral-900">{{ formatDate(vat.deadline) }}</span>
        <span class="text-sm" :class="TONE_CLASS[tone]" data-testid="accountant-vat-days">{{ daysText }}</span>
      </div>

      <ul class="flex flex-wrap gap-2" data-testid="accountant-vat-forms">
        <li v-for="f in forms" :key="f.type">
          <RouterLink :to="f.to" class="inline-flex items-center gap-2 h-8 px-3 rounded-md border border-neutral-200 hover:bg-neutral-50 text-sm text-neutral-800">
            <span>{{ t(`accountant_dashboard.vat.form.${f.type}`) }}</span>
            <span class="px-2 py-0.5 rounded text-xs font-medium" :class="BADGE[f.status]">
              {{ t(`accountant_dashboard.vat.status.${f.status}`) }}
              <template v-if="f.filed_at"> · {{ formatDate(f.filed_at) }}</template>
            </span>
          </RouterLink>
        </li>
      </ul>

      <dl v-if="figures" class="grid grid-cols-3 gap-2 sm:gap-3" data-testid="accountant-vat-figures">
        <div class="rounded-md bg-neutral-50 border border-neutral-100 px-2.5 sm:px-3 py-2.5 min-w-0">
          <dt class="text-xs text-neutral-500">{{ t('accountant_dashboard.vat.output') }}</dt>
          <dd class="mt-0.5 text-sm sm:text-base font-semibold font-mono text-neutral-900 whitespace-nowrap">{{ formatMoney(figures.output_vat, 'CZK', 0) }}</dd>
        </div>
        <div class="rounded-md bg-neutral-50 border border-neutral-100 px-2.5 sm:px-3 py-2.5 min-w-0">
          <dt class="text-xs text-neutral-500">{{ t('accountant_dashboard.vat.input') }}</dt>
          <dd class="mt-0.5 text-sm sm:text-base font-semibold font-mono text-neutral-900 whitespace-nowrap">{{ formatMoney(figures.input_vat, 'CZK', 0) }}</dd>
        </div>
        <div class="rounded-md bg-neutral-50 border border-neutral-100 px-2.5 sm:px-3 py-2.5 min-w-0">
          <dt class="text-xs text-neutral-500">{{ taxDue < 0 ? t('accountant_dashboard.vat.excess') : t('accountant_dashboard.vat.liability') }}</dt>
          <dd class="mt-0.5 text-sm sm:text-base font-semibold font-mono whitespace-nowrap" :class="taxDue < 0 ? 'text-success-600' : 'text-neutral-900'">
            {{ formatMoney(Math.abs(taxDue), 'CZK', 0) }}
          </dd>
        </div>
      </dl>
      <p v-else class="text-sm text-neutral-500" data-testid="accountant-vat-no-figures">{{ t('accountant_dashboard.vat.no_figures') }}</p>

      <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
        <RouterLink :to="`/reports/dph?${query}`" class="text-primary-600 hover:underline">{{ t('accountant_dashboard.vat.open_return') }} →</RouterLink>
        <RouterLink :to="`/reports/dph-book?${query}`" class="text-primary-600 hover:underline">{{ t('nav.reports_dph_book') }} →</RouterLink>
      </div>
    </div>
  </section>
</template>
