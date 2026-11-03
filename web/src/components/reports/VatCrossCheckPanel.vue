<script setup lang="ts">
/**
 * "Kontrola souladu": the same supplies as reported by the DPH return, the kontrolní hlášení and the
 * souhrnné hlášení, side by side — mismatches with the amounts and the documents that cause them.
 */
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import type { CrossCheck, CrossCheckStatus } from '@/api/taxFiling'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { documentLink } from '@/utils/taxFiling'

const props = defineProps<{ check: CrossCheck }>()
const { t } = useI18n()

const problems = computed(() => props.check.checks.filter(c => c.status !== 'ok'))

function badge(status: CrossCheckStatus): string {
  return ({
    ok: 'bg-success-50 text-success-600',
    warning: 'bg-warning-50 text-warning-700',
    error: 'bg-danger-50 text-danger-500',
  } as Record<string, string>)[status]
}

function money(value: number | null): string {
  return value === null ? '—' : formatMoney(value, 'CZK')
}
</script>

<template>
  <section class="bg-surface border rounded-lg shadow-sm overflow-hidden"
    :class="check.applicable && check.status === 'error' ? 'border-danger-500/50' : check.applicable && check.status === 'warning' ? 'border-warning-500/50' : 'border-neutral-200'"
    data-testid="cross-check">
    <header class="px-5 py-3 border-b border-neutral-200 bg-neutral-50 flex items-center justify-between gap-3 flex-wrap">
      <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-700">{{ t('reports.cross_check.title') }}</h3>
      <span v-if="check.applicable" :class="['px-2 py-0.5 rounded text-xs font-medium', badge(check.status)]" data-testid="cross-check-status">
        {{ t('reports.cross_check.status_' + check.status) }}
      </span>
    </header>

    <p v-if="!check.applicable" class="px-5 py-4 text-sm text-neutral-500">
      {{ t('reports.cross_check.not_applicable_' + (check.reason ?? 'build_failed')) }}
    </p>
    <p v-else-if="check.checks.length === 0" class="px-5 py-4 text-sm text-neutral-500">{{ t('reports.cross_check.nothing') }}</p>

    <template v-else>
      <p class="px-5 pt-3 text-xs text-neutral-500">{{ t('reports.cross_check.intro', { limit: formatMoney(check.tolerance, 'CZK') }) }}</p>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="text-left px-5 py-2">{{ t('reports.cross_check.pair') }}</th>
              <th class="text-right px-3 py-2">{{ t('reports.cross_check.dph_side') }}</th>
              <th class="text-right px-3 py-2">{{ t('reports.cross_check.other_side') }}</th>
              <th class="text-right px-3 py-2">{{ t('reports.cross_check.difference') }}</th>
              <th class="text-center px-5 py-2"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="c in check.checks" :key="c.id" :data-check="c.id" :data-status="c.status">
              <td class="px-5 py-2.5">
                <div class="text-neutral-800">{{ t('reports.cross_check.check_' + c.id) }}</div>
                <div class="text-xs text-neutral-500">
                  {{ t('reports.cross_check.lines', { lines: c.dph.lines.join(', ') }) }} ↔ {{ c.other.document }} {{ c.other.section }}
                </div>
              </td>
              <td class="px-3 py-2.5 text-right font-mono">
                {{ money(c.dph.base) }}
                <div v-if="c.dph.vat !== null" class="text-xs text-neutral-500">{{ t('reports.cross_check.vat') }} {{ money(c.dph.vat) }}</div>
              </td>
              <td class="px-3 py-2.5 text-right font-mono">
                {{ money(c.other.base) }}
                <div v-if="c.other.vat !== null" class="text-xs text-neutral-500">{{ t('reports.cross_check.vat') }} {{ money(c.other.vat) }}</div>
              </td>
              <td class="px-3 py-2.5 text-right font-mono" :class="c.status === 'ok' ? 'text-neutral-500' : c.status === 'warning' ? 'text-warning-700' : 'text-danger-500'">
                {{ money(c.diff.base) }}
                <div v-if="c.diff.vat !== null" class="text-xs">{{ t('reports.cross_check.vat') }} {{ money(c.diff.vat) }}</div>
              </td>
              <td class="px-5 py-2.5 text-center">
                <span :class="['inline-block px-2 py-0.5 rounded text-xs font-medium', badge(c.status)]">{{ c.status === 'ok' ? '✓' : '!' }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="problems.length && check.documents.length" class="px-5 py-4 border-t border-neutral-200" data-testid="cross-check-documents">
        <h4 class="text-xs font-semibold uppercase tracking-wide text-neutral-600 mb-2">{{ t('reports.cross_check.documents_title') }}</h4>
        <ul class="space-y-1.5 text-sm">
          <li v-for="d in check.documents" :key="d.source + d.invoice_id + d.problem" class="flex flex-wrap items-baseline gap-x-2">
            <RouterLink :to="documentLink({ source: d.source, id: d.invoice_id })" class="font-mono text-primary-700 hover:underline">{{ d.doc_number ?? '#' + d.invoice_id }}</RouterLink>
            <span class="text-neutral-500">{{ d.source === 'sale' ? t('reports.cross_check.sale') : t('reports.cross_check.purchase') }}
              <template v-if="d.counterparty"> · {{ d.counterparty }}</template>
              <template v-if="d.tax_date"> · {{ formatDate(d.tax_date) }}</template></span>
            <span class="font-mono">{{ formatMoney(d.base_czk, 'CZK') }}</span>
            <span class="text-warning-700">— {{ t('reports.cross_check.problem_' + d.problem, { line: d.dph_line ?? '—', section: d.kh_section ?? '—' }) }}</span>
          </li>
        </ul>
        <p v-if="check.documents_truncated" class="text-xs text-neutral-500 mt-2">{{ t('reports.cross_check.documents_truncated') }}</p>
      </div>
    </template>
  </section>
</template>
