<script setup lang="ts">
/**
 * "Doklady změněné po podání": documents of a filed period that were created, issued, edited,
 * cancelled or deleted after the return was filed — with the hint that a corrective or
 * supplementary filing may be needed.
 */
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import type { ChangesAfterFiling, ChangedDocument } from '@/api/taxFiling'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { documentLink, formatDbDateTime } from '@/utils/taxFiling'

defineProps<{ changes: ChangesAfterFiling }>()
const { t } = useI18n()

function linkOf(d: ChangedDocument): string | null {
  return d.deleted ? null : documentLink(d)
}

function sourceLabel(d: ChangedDocument): string {
  if (d.source === 'registration_deduction') return t(`reports.changes_after_filing.deduction_${d.kind}`)
  return d.source === 'sale' ? t('reports.cross_check.sale') : t('reports.cross_check.purchase')
}
</script>

<template>
  <section class="bg-surface border rounded-lg shadow-sm overflow-hidden" data-testid="changed-after-filing"
    :class="changes.count > 0 ? 'border-warning-500/50' : 'border-neutral-200'">
    <header class="px-5 py-3 border-b border-neutral-200 bg-neutral-50 flex items-center justify-between gap-3 flex-wrap">
      <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-700">{{ t('reports.changes_after_filing.title') }}</h3>
      <span v-if="changes.filed_at" class="text-xs text-neutral-500">
        {{ t('reports.changes_after_filing.since', { when: formatDbDateTime(changes.filed_at) }) }}
      </span>
    </header>
    <p v-if="changes.count === 0" class="px-5 py-4 text-sm text-neutral-500">{{ t('reports.changes_after_filing.none') }}</p>
    <template v-else>
      <div class="px-5 py-3 text-sm text-warning-700 bg-warning-50 border-b border-warning-500/30" data-testid="changed-after-filing-hint">
        {{ t('reports.changes_after_filing.hint') }}
      </div>
      <ul class="divide-y divide-neutral-100">
        <li v-for="d in changes.items" :key="d.source + d.id + (d.deleted ? 'd' : '')" class="px-5 py-2.5 text-sm">
          <div class="flex flex-wrap items-baseline gap-x-2">
            <RouterLink v-if="linkOf(d)" :to="linkOf(d)!" class="font-mono text-primary-700 hover:underline">{{ d.doc_number ?? '#' + d.id }}</RouterLink>
            <span v-else class="font-mono text-neutral-700 line-through">{{ d.doc_number ?? '#' + d.id }}</span>
            <span class="text-neutral-500">{{ sourceLabel(d) }}
              <template v-if="d.counterparty"> · {{ d.counterparty }}</template>
              <template v-if="d.tax_date"> · {{ t('reports.changes_after_filing.duzp') }} {{ formatDate(d.tax_date) }}</template></span>
            <span v-if="d.total_with_vat !== null" class="font-mono">{{ formatMoney(d.total_with_vat, d.currency || 'CZK') }}</span>
          </div>
          <div class="text-xs text-neutral-500 mt-0.5 flex flex-wrap gap-x-3">
            <span v-for="(c, i) in d.changes" :key="i">
              <strong class="text-neutral-700">{{ t('reports.changes_after_filing.change_' + c.type) }}</strong> {{ formatDbDateTime(c.at) }}
            </span>
          </div>
        </li>
      </ul>
    </template>
  </section>
</template>
