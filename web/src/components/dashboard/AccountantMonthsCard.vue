<script setup lang="ts">
/**
 * Two months of documents side by side (count, base without VAT, VAT) plus what needs attention - shared by the
 * issued and the received invoices of the accountant's dashboard. Two empty months show a sentence, not zeros.
 */
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import type { AccountantMonthFigures } from '@/api/dashboard'
import { formatMoney, formatMonth } from '@/composables/useFormat'
import { monthKey } from '@/utils/accountantDashboard'

export interface MonthsCardAlert {
  key: string
  text: string
  to: string
  tone: 'danger' | 'warning'
}

const props = defineProps<{
  title: string
  previous: AccountantMonthFigures
  current: AccountantMonthFigures
  listTo: string
  listLabel: string
  alerts: MonthsCardAlert[]
  /** Shown instead of the alerts when there is nothing to attend to. */
  okText: string
}>()
const { t } = useI18n()

const months = computed(() => [props.previous, props.current].map(m => ({ ...m, label: formatMonth(monthKey(m.year, m.month)) })))
const empty = computed(() => props.previous.count === 0 && props.current.count === 0)
const TONE = { danger: 'text-danger-500', warning: 'text-warning-600' } as const
</script>

<template>
  <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm flex flex-col">
    <header class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between gap-3">
      <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-600">{{ title }}</h3>
      <RouterLink :to="listTo" class="text-xs text-primary-600 hover:underline shrink-0">{{ listLabel }} →</RouterLink>
    </header>

    <p v-if="empty" class="px-5 pt-5 text-sm text-neutral-600" data-testid="months-empty">{{ t('accountant_dashboard.no_documents') }}</p>
    <table v-else class="w-full text-sm" data-testid="months-table">
      <thead>
        <tr class="text-xs text-neutral-500">
          <th scope="col" class="px-5 py-2 text-left font-normal"><span class="sr-only">{{ title }}</span></th>
          <th v-for="m in months" :key="m.label" scope="col" class="px-3 py-2 text-right font-medium capitalize whitespace-nowrap">{{ m.label }}</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-neutral-100">
        <tr>
          <th scope="row" class="px-5 py-2 text-left font-normal text-neutral-600">{{ t('accountant_dashboard.count') }}</th>
          <td v-for="m in months" :key="m.label" class="px-3 py-2 text-right font-mono text-neutral-900 whitespace-nowrap">{{ m.count }}</td>
        </tr>
        <tr>
          <th scope="row" class="px-5 py-2 text-left font-normal text-neutral-600">{{ t('accountant_dashboard.base') }}</th>
          <td v-for="m in months" :key="m.label" class="px-3 py-2 text-right font-mono text-neutral-900 whitespace-nowrap">{{ formatMoney(m.base, 'CZK', 0) }}</td>
        </tr>
        <tr>
          <th scope="row" class="px-5 py-2 text-left font-normal text-neutral-600">{{ t('accountant_dashboard.vat_amount') }}</th>
          <td v-for="m in months" :key="m.label" class="px-3 py-2 text-right font-mono text-neutral-900 whitespace-nowrap">{{ formatMoney(m.vat, 'CZK', 0) }}</td>
        </tr>
      </tbody>
    </table>

    <ul v-if="alerts.length" class="mt-auto px-5 py-3 border-t border-neutral-100 space-y-1" data-testid="months-alerts">
      <li v-for="a in alerts" :key="a.key" class="text-sm">
        <RouterLink :to="a.to" class="hover:underline" :class="TONE[a.tone]">{{ a.text }} →</RouterLink>
      </li>
    </ul>
    <p v-else class="mt-auto px-5 py-3 border-t border-neutral-100 text-sm text-success-600" data-testid="months-ok">{{ okText }}</p>
  </section>
</template>
