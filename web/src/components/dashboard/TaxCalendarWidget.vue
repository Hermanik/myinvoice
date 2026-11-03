<script setup lang="ts">
/**
 * Daňový kalendář: the next tax deadlines of the current supplier (DPH, kontrolní hlášení, souhrnné
 * hlášení, income tax return, flat-tax advance) with the days left and — for the returns — the
 * filed / unfiled badge from the submission archive. Self-gating: nothing is drawn without a deadline.
 */
import { ref, computed, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { taxFilingApi, type TaxCalendarItem } from '@/api/taxFiling'
import { formatDate, formatMoney } from '@/composables/useFormat'

const { t } = useI18n()
const items = ref<TaxCalendarItem[]>([])
const MAX_ITEMS = 8

onMounted(async () => {
  try {
    items.value = (await taxFilingApi.taxCalendar()).items
  } catch {
    // best effort — the dashboard works without it
  }
})

const shown = computed(() => items.value.slice(0, MAX_ITEMS))

function daysClass(i: TaxCalendarItem): string {
  if (i.filing && i.filing.status !== 'unfiled') return 'text-neutral-500'
  if (i.days_left < 0) return 'text-danger-500 font-medium'
  if (i.days_left <= 7) return 'text-warning-600 font-medium'
  return 'text-neutral-500'
}

function badgeClass(status: string): string {
  if (status === 'confirmed') return 'bg-success-50 text-success-600'
  if (status === 'filed') return 'bg-primary-50 text-primary-700'
  return 'bg-warning-50 text-warning-700'
}
</script>

<template>
  <section v-if="shown.length" class="bg-surface border border-neutral-200 rounded-lg shadow-sm" data-testid="tax-calendar">
    <header class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
      <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-600">{{ t('dashboard.tax_calendar.title') }}</h3>
      <RouterLink to="/reports/submissions" class="text-xs text-primary-600 hover:underline">{{ t('dashboard.tax_calendar.archive') }} →</RouterLink>
    </header>
    <ul class="divide-y divide-neutral-100">
      <li v-for="i in shown" :key="i.key" :data-type="i.type" :data-days="i.days_left">
        <RouterLink :to="i.link" class="flex items-center gap-3 px-5 py-2.5 hover:bg-neutral-50">
          <div class="w-24 shrink-0">
            <div class="text-sm font-mono text-neutral-800">{{ formatDate(i.due_date) }}</div>
            <div class="text-xs" :class="daysClass(i)">
              <template v-if="i.days_left > 0">{{ t('dashboard.tax_calendar.in_days', { n: i.days_left }) }}</template>
              <template v-else-if="i.days_left === 0">{{ t('dashboard.tax_calendar.today') }}</template>
              <template v-else>{{ t('dashboard.tax_calendar.overdue_days', { n: Math.abs(i.days_left) }) }}</template>
            </div>
          </div>
          <div class="min-w-0 flex-1">
            <div class="text-sm text-neutral-800 truncate">{{ t('dashboard.tax_calendar.type_' + i.type) }}</div>
            <div class="text-xs text-neutral-500 truncate">
              {{ i.period_label }}
              <template v-if="i.type === 'pausal' && i.amount">· {{ formatMoney(i.amount, 'CZK') }}</template>
              <template v-if="i.paper_due_date">· {{ t('dashboard.tax_calendar.electronic') }} · {{ t('dashboard.tax_calendar.paper_until', { date: formatDate(i.paper_due_date) }) }}</template>
            </div>
          </div>
          <span v-if="i.filing" :class="['px-2 py-0.5 rounded text-xs font-medium shrink-0', badgeClass(i.filing.status)]" data-testid="tax-calendar-badge">
            {{ t('dashboard.tax_calendar.badge_' + i.filing.status) }}
          </span>
        </RouterLink>
      </li>
    </ul>
  </section>
</template>
