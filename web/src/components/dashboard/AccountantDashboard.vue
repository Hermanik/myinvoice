<script setup lang="ts">
/**
 * The dashboard of the accountant (effective role `accountant`): what is needed after every login instead of the
 * owner's business statistics - VAT of the last closed month, the next obligations of the Harmonogram, the issued
 * and received invoices of the last two months and one click to the monthly documents for the accounting program.
 * Each widget copes with a business that has no data yet; the data box card exists only for an opened box.
 */
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { dashboardApi, type AccountantSummary } from '@/api/dashboard'
import { apiErrorMessage } from '@/api/errors'
import { formatMoney, formatMonth } from '@/composables/useFormat'
import { useSupplierStore } from '@/stores/supplier'
import { incomeTaxYear, monthKey, periodQuery, yearlyExportQuery } from '@/utils/accountantDashboard'
import AccountantDataboxCard from './AccountantDataboxCard.vue'
import AccountantMonthsCard, { type MonthsCardAlert } from './AccountantMonthsCard.vue'
import AccountantObligationsCard from './AccountantObligationsCard.vue'
import AccountantVatCard from './AccountantVatCard.vue'

const { t } = useI18n()
const supplierStore = useSupplierStore()

const summary = ref<AccountantSummary | null>(null)
const loading = ref(true)
const error = ref('')

onMounted(async () => {
  try {
    summary.value = await dashboardApi.accountant()
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
})

const exportMonth = computed(() => (summary.value ? formatMonth(monthKey(summary.value.export.year, summary.value.export.month)) : ''))
const exportTo = computed(() => (summary.value ? `/reports/monthly-export?${periodQuery(summary.value.export.year, summary.value.export.month)}` : '/reports/monthly-export'))
// The income tax return of the previous year is prepared until June, then the running year is the one being collected.
const taxYear = incomeTaxYear()
const taxYearExportTo = `/reports/monthly-export?${yearlyExportQuery(taxYear)}`

const issuedAlerts = computed<MonthsCardAlert[]>(() => {
  const overdue = summary.value?.issued.overdue
  return overdue && overdue.count > 0
    ? [{
        key: 'overdue',
        text: t('accountant_dashboard.issued.overdue', { count: overdue.count, total: formatMoney(overdue.total, 'CZK', 0) }),
        to: '/invoices?overdue=1',
        tone: 'danger',
      }]
    : []
})

const purchaseAlerts = computed<MonthsCardAlert[]>(() => {
  const a = summary.value?.purchase.attention
  const alerts: MonthsCardAlert[] = []
  if (a && a.drafts > 0) {
    alerts.push({ key: 'drafts', text: t('accountant_dashboard.purchase.drafts', { count: a.drafts }), to: '/purchase-invoices?status=draft&year=all', tone: 'warning' })
  }
  if (a && a.to_book > 0) {
    alerts.push({ key: 'to_book', text: t('accountant_dashboard.purchase.to_book', { count: a.to_book }), to: '/purchase-invoices?status=received&year=all', tone: 'warning' })
  }
  return alerts
})
</script>

<template>
  <div class="space-y-6" data-testid="accountant-dashboard">
    <!-- Introduction and the one thing done every month: taking the documents -->
    <header class="flex flex-wrap items-center justify-between gap-3" data-tour="dashboard-content">
      <div class="min-w-0">
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('accountant_dashboard.title') }}</h2>
        <p class="text-sm text-neutral-500 truncate">
          {{ supplierStore.currentSupplier?.company_name }}
          <span v-if="summary"> · {{ t('accountant_dashboard.subtitle') }}</span>
        </p>
      </div>
      <div v-if="summary" class="flex flex-wrap items-center gap-2">
        <RouterLink
          :to="exportTo" data-testid="accountant-export"
          class="inline-flex items-center gap-2 h-10 px-4 rounded-md bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium"
        >
          <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
          </svg>
          {{ t('accountant_dashboard.export_cta', { month: exportMonth }) }}
        </RouterLink>
        <RouterLink
          :to="taxYearExportTo" data-testid="accountant-export-year"
          class="inline-flex items-center gap-2 h-10 px-4 rounded-md border border-primary-600 text-primary-700 hover:bg-primary-50 text-sm font-medium"
        >
          <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
          </svg>
          {{ t('accountant_dashboard.export_year_cta', { year: taxYear }) }}
        </RouterLink>
      </div>
    </header>

    <div v-if="loading" class="text-center text-neutral-500 py-12">{{ t('dashboard.loading_data') }}</div>
    <div v-else-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ error }}</div>

    <template v-else-if="summary">
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 items-start">
        <AccountantVatCard :vat="summary.vat" />
        <AccountantObligationsCard
          v-if="summary.obligations"
          :items="summary.obligations.items"
          :open-count="summary.obligations.open_count"
          :overdue-count="summary.obligations.overdue_count"
        />
      </div>
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 items-stretch">
        <AccountantMonthsCard
          data-testid="accountant-issued"
          :title="t('accountant_dashboard.issued.title')"
          :previous="summary.issued.previous" :current="summary.issued.current"
          :list-to="`/invoices?${periodQuery(summary.issued.previous.year, summary.issued.previous.month)}`"
          :list-label="t('nav.invoices')"
          :alerts="issuedAlerts"
          :ok-text="t('accountant_dashboard.issued.ok')"
        />
        <AccountantMonthsCard
          data-testid="accountant-purchase"
          :title="t('accountant_dashboard.purchase.title')"
          :previous="summary.purchase.previous" :current="summary.purchase.current"
          :list-to="`/purchase-invoices?${periodQuery(summary.purchase.previous.year, summary.purchase.previous.month)}`"
          :list-label="t('nav.purchase_invoices')"
          :alerts="purchaseAlerts"
          :ok-text="t('accountant_dashboard.purchase.ok')"
        />
      </div>
      <!-- Only when the owner opened a data box to the accountant -->
      <div v-if="summary.databox" class="grid grid-cols-1 lg:grid-cols-2 gap-4 items-start">
        <AccountantDataboxCard :databox="summary.databox" />
      </div>
    </template>
  </div>
</template>
