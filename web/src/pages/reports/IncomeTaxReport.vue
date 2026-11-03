<script setup lang="ts">
import { ref, onMounted, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { reportsApi } from '@/api/reports'
import { apiErrorMessage } from '@/api/errors'
import { formatMoney } from '@/composables/useFormat'
import { useYearOptions } from '@/composables/useYearOptions'
import { useSupplierStore } from '@/stores/supplier'

const { t } = useI18n()
const supplierStore = useSupplierStore()

const now = new Date()
const year = ref(now.getFullYear() - 1) // typicky podáváme za uplynulý rok
// Typ poplatníka se NEPŘEPÍNÁ — odvozuje se z dodavatele (OSVČ → DPFO, s.r.o. → DPPO).
const taxpayerType = computed<'fo' | 'po'>(() => supplierStore.currentSupplier?.taxpayer_type === 'po' ? 'po' : 'fo')

const preview = ref<Awaited<ReturnType<typeof reportsApi.incomeTaxPreview>> | null>(null)
const loading = ref(false)
const error = ref('')

async function loadPreview() {
  loading.value = true
  error.value = ''
  try {
    preview.value = await reportsApi.incomeTaxPreview(year.value, taxpayerType.value)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

function downloadXml() {
  window.open(reportsApi.incomeTaxDownloadUrl(year.value, taxpayerType.value), '_blank')
}

// CSV podklad pro daňové přiznání (DP1) — orientační čísla z přehledu (klient-side).
function exportCsv() {
  if (!preview.value) return
  const s = preview.value.summary
  const rows: Array<[string, string | number]> = [
    [t('reports.income_tax.csv_year'), s.year],
    [t('reports.income_tax.csv_type'), s.taxpayer_type === 'po' ? 'DPPO' : 'DPFO'],
    [t('reports.income_tax.revenue_orientacni'), s.revenue_orientacni],
    [t('reports.income_tax.costs_orientacni'), s.costs_orientacni],
    [t('reports.income_tax.profit_orientacni'), s.profit_orientacni],
    [t('reports.income_tax.csv_vat'), s.is_vat_payer ? t('common.yes') : t('common.no')],
    [t('reports.dph.deadline'), s.submission_deadline],
    [t('reports.income_tax.csv_paper_deadline'), s.paper_deadline],
  ]
  const csv = '﻿' + rows.map(([k, v]) =>
    `"${String(k).replace(/"/g, '""')}";"${String(v).replace(/"/g, '""')}"`).join('\r\n')
  const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }))
  const a = document.createElement('a')
  a.href = url
  a.download = `dan-z-prijmu-${s.taxpayer_type}-${s.year}.csv`
  a.click()
  URL.revokeObjectURL(url)
}

// Distinct roky z dat (issue #33) — typicky se podává za uplynulý rok, ale
// uživatel může chtít zpětně sestavit přiznání za starší roky (kdy přiznání
// zpoždil / kontroluje archiv).
const yearOptions = useYearOptions('combined', year)

watch([year, taxpayerType], loadPreview)
onMounted(loadPreview)
</script>

<template>
  <div class="max-w-5xl">
    <!-- ⚠️ MEGA disclaimer — DPFO/DPPO jsou MVP, NE production -->
    <div class="bg-danger-50 border-2 border-danger-500 rounded-lg p-4 mb-4">
      <div class="flex items-start gap-3">
        <svg class="w-6 h-6 text-danger-600 shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
          <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 1 1-2 0 1 1 0 0 1 2 0zm-1-8a1 1 0 0 0-1 1v3a1 1 0 0 0 2 0V6a1 1 0 0 0-1-1z" clip-rule="evenodd"/>
        </svg>
        <div class="text-sm text-danger-700">
          <p class="font-semibold mb-1">{{ t('reports.income_tax.mvp_disclaimer_title') }}</p>
          <p>{{ t('reports.income_tax.mvp_disclaimer_body') }}</p>
        </div>
      </div>
    </div>

    <!-- Topbar -->
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('reports.income_tax.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('reports.income_tax.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-2">
        <!-- Typ poplatníka odvozen z dodavatele (nastav v Nastavení), nepřepíná se. -->
        <span class="px-3 h-9 inline-flex items-center rounded-md border border-neutral-300 bg-neutral-50 text-sm font-medium text-neutral-700"
          :title="t('reports.income_tax.type_from_supplier')">
          {{ taxpayerType === 'po' ? 'DPPO' : 'DPFO' }}
        </span>
        <select v-model.number="year" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
        </select>
        <button type="button" @click="exportCsv" :disabled="loading || !preview"
          class="cursor-pointer h-9 px-3 border border-neutral-300 bg-surface hover:bg-neutral-50 disabled:opacity-50 text-sm font-medium rounded-md inline-flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
          {{ t('reports.income_tax.export_csv') }}
        </button>
        <button type="button" @click="downloadXml" :disabled="loading || !preview"
          class="cursor-pointer h-9 px-4 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white text-sm font-medium rounded-md inline-flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
          {{ t('reports.income_tax.download_xml') }}
        </button>
      </div>
    </div>

    <div v-if="loading" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-8 text-center text-neutral-400">{{ t('common.loading') }}…</div>
    <div v-else-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm">{{ error }}</div>

    <div v-else-if="preview" class="space-y-4">
      <!-- Warnings -->
      <div v-if="preview.warnings.length > 0" class="bg-warning-50 border border-warning-500/40 rounded-md p-3 text-sm text-warning-700">
        <strong>{{ t('reports.dph.warnings') }}:</strong>
        <ul class="mt-1 list-disc list-inside">
          <li v-for="w in preview.warnings" :key="w">{{ w }}</li>
        </ul>
      </div>

      <!-- Orientační čísla -->
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-1">{{ t('reports.income_tax.revenue_orientacni') }}</div>
          <div class="text-xl font-bold font-mono text-neutral-900">
            {{ formatMoney(preview.summary.revenue_orientacni, 'CZK') }}
          </div>
          <div class="text-xs text-neutral-500 mt-1">
            {{ t('reports.income_tax.revenue_hint') }} ·
            {{ preview.summary.is_vat_payer ? t('reports.income_tax.vat_base_excl') : t('reports.income_tax.vat_base_incl') }}
          </div>
          <div v-if="(preview.summary.exempt_revenue_orientacni ?? 0) > 0" class="text-xs text-amber-700 mt-1">
            {{ t('reports.income_tax.exempt_revenue_orientacni') }}: {{ formatMoney(preview.summary.exempt_revenue_orientacni, 'CZK') }}
          </div>
        </div>
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-1">{{ t('reports.income_tax.costs_orientacni') }}</div>
          <div class="text-xl font-bold font-mono text-neutral-900">
            {{ formatMoney(preview.summary.costs_orientacni, 'CZK') }}
          </div>
          <div class="text-xs text-neutral-500 mt-1">
            {{ t('reports.income_tax.costs_hint') }} ·
            {{ preview.summary.is_vat_payer ? t('reports.income_tax.vat_base_excl') : t('reports.income_tax.vat_base_incl') }}
          </div>
        </div>
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-1">{{ t('reports.income_tax.profit_orientacni') }}</div>
          <div class="text-xl font-bold font-mono"
            :class="preview.summary.profit_orientacni >= 0 ? 'text-success-600' : 'text-danger-500'">
            {{ formatMoney(preview.summary.profit_orientacni, 'CZK') }}
          </div>
          <div class="text-xs text-neutral-500 mt-1">{{ t('reports.income_tax.profit_hint') }}</div>
        </div>
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-1">{{ t('reports.dph.deadline') }}</div>
          <div class="text-xl font-bold font-mono text-neutral-900">
            {{ preview.summary.submission_deadline }}
          </div>
          <div class="text-xs text-neutral-500 mt-1">{{ t('reports.income_tax.deadline_hint', { paper: preview.summary.paper_deadline }) }}</div>
        </div>
      </div>

      <!-- Co XML chybí -->
      <div class="bg-warning-50 border border-warning-500/40 rounded-lg p-5">
        <h3 class="text-sm font-semibold text-warning-700 mb-2">{{ t('reports.income_tax.missing_data_title') }}</h3>
        <ul class="text-sm text-warning-700 space-y-1 list-disc list-inside">
          <li>{{ t('reports.income_tax.missing_odpisy') }}</li>
          <li>{{ t('reports.income_tax.missing_mzdy') }}</li>
          <li>{{ t('reports.income_tax.missing_socsec') }}</li>
          <li>{{ t('reports.income_tax.missing_zaloha') }}</li>
          <li>{{ t('reports.income_tax.missing_slevy') }}</li>
        </ul>
        <p class="text-sm text-warning-700 mt-3">{{ t('reports.income_tax.missing_advice') }}</p>
      </div>
    </div>
  </div>
</template>
