<script setup lang="ts">
/**
 * Estimate of the tax and insurance of a year that is not in the flat-tax regime: what is expected for the year,
 * what is credited (the flat-tax advances of a lost year) and what is left to pay, with the dates. Shown at the top of
 * the timeline (compact) and in the card of the lost flat-tax regime (full).
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { TaxYearEstimate } from '@/api/timeline'
import { formatDate, formatMoney } from '@/composables/useFormat'

const props = defineProps<{ estimate: TaxYearEstimate; compact?: boolean }>()
const { t } = useI18n()

const ROWS = ['income_tax', 'social', 'health'] as const
const money = (n: number) => formatMoney(n, 'CZK', 0)
/** Cash to reserve: the surcharges that are to be paid (an overpayment is not subtracted from another payment). */
const reserve = computed(() => ROWS.reduce((sum, row) => sum + Math.max(0, props.estimate[row].surcharge), 0))
</script>

<template>
  <section class="bg-surface border border-neutral-200 rounded-lg p-4 space-y-2" data-testid="timeline-estimate" :data-year="estimate.year">
    <header>
      <h2 class="text-sm font-semibold text-neutral-900">
        {{ t('timeline.estimate_box.title', { year: estimate.year }) }}
        <span class="text-xs font-normal text-neutral-500">({{ t('timeline.estimate') }})</span>
      </h2>
      <p v-if="!compact" class="text-xs text-neutral-600 mt-0.5">
        {{ t(estimate.regime === 'lost' ? 'timeline.estimate_box.subtitle_lost' : 'timeline.estimate_box.subtitle_regular', { year: estimate.year }) }}
      </p>
      <p class="text-xs text-neutral-600 mt-0.5" data-testid="timeline-estimate-income">
        {{ estimate.closed
          ? t('timeline.estimate_box.income_closed', { total: money(estimate.income.total) })
          : t('timeline.estimate_box.income', { total: money(estimate.income.total), paid: money(estimate.income.paid), unpaid: money(estimate.income.unpaid) }) }}
      </p>
    </header>

    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-xs text-neutral-500 text-right">
            <th class="font-medium text-left py-1 pr-3"></th>
            <th class="font-medium py-1 px-2">{{ t('timeline.estimate_box.col_expected') }}</th>
            <th class="font-medium py-1 px-2">{{ t('timeline.estimate_box.col_credited') }}</th>
            <th class="font-medium py-1 px-2">{{ t('timeline.estimate_box.col_surcharge') }}</th>
            <th class="font-medium py-1 pl-2">{{ t('timeline.estimate_box.col_due') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in ROWS" :key="row" class="border-t border-neutral-100 text-right tabular-nums" :data-row="row">
            <th class="font-normal text-left py-1 pr-3 text-neutral-800">{{ t('timeline.estimate_box.' + row) }}</th>
            <td class="py-1 px-2">{{ money(estimate[row].expected) }}</td>
            <td class="py-1 px-2">{{ money(estimate[row].credited) }}</td>
            <td class="py-1 px-2 font-medium" :class="estimate[row].surcharge < 0 ? 'text-success-600' : 'text-neutral-900'" data-testid="timeline-estimate-surcharge">
              {{ estimate[row].surcharge < 0 ? t('timeline.estimate_box.overpaid', { amount: money(-estimate[row].surcharge) }) : money(estimate[row].surcharge) }}
            </td>
            <td class="py-1 pl-2 whitespace-nowrap">{{ formatDate(estimate[row].due_date) }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <p class="text-sm font-medium text-neutral-900" data-testid="timeline-estimate-reserve">{{ t('timeline.estimate_box.reserve', { amount: money(reserve) }) }}</p>
    <p v-if="!compact && estimate.standing" class="text-xs text-neutral-600" data-testid="timeline-estimate-standing">
      {{ t('timeline.estimate_box.standing', { paid: money(estimate.standing.paid), months: estimate.standing.months }) }}
      <template v-if="estimate.standing.overpaid > 0">{{ t('timeline.estimate_box.standing_overpaid', { overpaid: money(estimate.standing.overpaid) }) }}</template>
    </p>
    <p v-if="!compact" class="text-xs text-neutral-500">{{ t('timeline.estimate_box.disclaimer') }}</p>
  </section>
</template>
