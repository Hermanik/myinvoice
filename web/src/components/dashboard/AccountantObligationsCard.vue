<script setup lang="ts">
/**
 * The next open obligations of the Harmonogram for the accountant: the missed ones first (highlighted), then what
 * is coming. The texts are the timeline's own (`timeline.item.<type>.title`), the server sends codes and parameters.
 */
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import type { TimelineItem } from '@/api/timeline'
import { formatDate, formatMonth, formatMoney } from '@/composables/useFormat'
import { textParams as buildTextParams } from '@/utils/timeline'

const props = defineProps<{ items: TimelineItem[]; openCount: number; overdueCount: number }>()
const { t } = useI18n()

const formatters = {
  date: formatDate,
  money: (n: number) => formatMoney(n, 'CZK', 0),
  month: formatMonth,
  quarter: (q: number, year: number) => t('timeline.quarter_label', { q, year }),
  claim: (code: string) => t(`timeline.claim.${code}`),
}

const rows = computed(() => props.items.map(item => ({
  item,
  title: item.custom?.title ?? t(`timeline.item.${item.type}.title`, buildTextParams(item, formatters)),
  days: item.days_left > 0 ? t('timeline.in_days', { n: item.days_left })
    : item.days_left === 0 ? t('timeline.today') : t('timeline.overdue_days', { n: Math.abs(item.days_left) }),
})))
</script>

<template>
  <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm flex flex-col" data-testid="accountant-obligations">
    <header class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between gap-3">
      <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-600">{{ t('accountant_dashboard.obligations.title') }}</h3>
      <RouterLink to="/timeline" class="text-xs text-primary-600 hover:underline shrink-0">{{ t('nav.timeline') }} →</RouterLink>
    </header>

    <p v-if="rows.length === 0" class="px-5 py-6 text-sm text-neutral-600" data-testid="accountant-obligations-empty">
      {{ t('accountant_dashboard.obligations.empty') }}
    </p>

    <ul v-else class="divide-y divide-neutral-100 flex-1">
      <li v-for="r in rows" :key="r.item.key" :data-status="r.item.status">
        <RouterLink
          to="/timeline"
          class="flex items-center gap-3 px-5 py-2.5 hover:bg-neutral-50"
          :class="r.item.status === 'overdue' ? 'bg-danger-50 hover:bg-danger-50' : ''"
        >
          <div class="w-28 shrink-0">
            <div class="text-sm font-mono text-neutral-800 whitespace-nowrap">{{ formatDate(r.item.due_date) }}</div>
            <div class="text-xs"
                 :class="r.item.status === 'overdue' ? 'text-danger-500 font-medium'
                   : r.item.status === 'due_soon' ? 'text-warning-600 font-medium' : 'text-neutral-500'">
              {{ r.days }}
            </div>
          </div>
          <div class="min-w-0 flex-1 text-sm text-neutral-800">{{ r.title }}</div>
          <span v-if="r.item.status === 'overdue'" class="px-2 py-0.5 rounded text-xs font-medium shrink-0 bg-danger-50 text-danger-500 border border-danger-500/30">
            {{ t('timeline.badge_overdue') }}
          </span>
        </RouterLink>
      </li>
    </ul>

    <footer v-if="rows.length > 0" class="px-5 py-2.5 border-t border-neutral-100 text-xs text-neutral-500">
      {{ t('accountant_dashboard.obligations.counts', { open: openCount, overdue: overdueCount }) }}
    </footer>
  </section>
</template>
