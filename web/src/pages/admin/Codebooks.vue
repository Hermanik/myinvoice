<script setup lang="ts">
import { ref, onMounted, reactive, watch, computed } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { settingsApi, type VatRate, type Country, type Unit } from '@/api/settings'
import { suppliersApi, type SupplierListItem, type SupplierCreatePayload } from '@/api/suppliers'
import { expenseCategoriesApi, type ExpenseCategory } from '@/api/expenseCategories'
import { revenueCategoriesApi, type RevenueCategory } from '@/api/revenueCategories'
import { vatClassificationsApi, type VatClassification } from '@/api/vatClassifications'
import { taxConstantsApi, type TaxConstantsYear } from '@/api/taxConstants'
import type { TaxConstantsData } from '@/api/tax'
import { clientsApi } from '@/api/clients'
import { repoRatesApi, type RepoRateSnapshot } from '@/api/repoRates'
import { apiErrorMessage } from '@/api/errors'
import { useSupplierStore } from '@/stores/supplier'
import { useAuthStore } from '@/stores/auth'
import { useHotkey } from '@/composables/useHotkey'
import { useToast } from '@/composables/useToast'
import { formatMonth, formatDate } from '@/composables/useFormat'

const { t } = useI18n()
const route = useRoute()
const toast = useToast()
const supplierStore = useSupplierStore()
const auth = useAuthStore()

type Tab = 'suppliers' | 'currencies' | 'vat' | 'countries' | 'units' | 'expense_categories' | 'revenue_categories' | 'vat_classifications' | 'tax_constants' | 'repo_rates'
const tab = ref<Tab>('suppliers')
const vatRates   = ref<VatRate[]>([])
const countries  = ref<Country[]>([])
const units      = ref<Unit[]>([])
const suppliers  = ref<SupplierListItem[]>([])
const loading    = ref(false)

async function loadAll() {
  loading.value = true
  try {
    [suppliers.value, vatRates.value, countries.value, units.value] = await Promise.all([
      suppliersApi.list(),
      settingsApi.listVatRates(),
      settingsApi.listCountries(),
      settingsApi.listUnits(),
    ])
  } finally { loading.value = false }
}
onMounted(async () => {
  await loadAll()
  // Onboarding gate (#151): dashboard sem posílá s ?create=supplier → rovnou otevři
  // formulář pro vytvoření prvního dodavatele.
  if (route.query.create === 'supplier') {
    tab.value = 'suppliers'
    newSupplier()
  }
})

// ─── Suppliers (multi-tenant firmy) — embed jako první tab ───────────────
const supplierDraft = reactive<SupplierCreatePayload>({
  company_name: '', street: '', city: '', zip: '', email: '',
  country_iso2: 'CZ', ic: '', dic: '', is_vat_payer: true,
  commercial_register: '',
  default_payment_due_days: 14, default_hourly_rate: 1500,
})
const supplierCreateOpen = ref(false)
const supplierAresLoading = ref(false)
const supplierAresMessage = ref<{ type: 'success' | 'error'; text: string } | null>(null)

// Bankovní účet nového dodavatele (volitelný, lze načíst z registru plátců DPH)
const supplierBank = reactive({ currency: 'CZK', account_number: '', bank_code: '', bank_name: '', iban: '', bic: '' })
const supplierBankLoading = ref(false)
const supplierBankMessage = ref<{ type: 'success' | 'error' | 'warning'; text: string } | null>(null)
const supplierBankAccounts = ref<import('@/api/clients').CrpDphAccount[]>([])

function supplierApplyBank(acc: import('@/api/clients').CrpDphAccount) {
  if (acc.iban) {
    supplierBank.currency = 'EUR'
    supplierBank.iban = acc.iban
  } else {
    supplierBank.currency = 'CZK'
    supplierBank.account_number = acc.prefix ? `${acc.prefix}-${acc.number}` : acc.number
    supplierBank.bank_code = acc.bank_code
  }
}

async function supplierLookupBank() {
  const dic = (supplierDraft.dic || '').replace(/\D/g, '')
  if (!/^\d{8,10}$/.test(dic)) {
    supplierBankMessage.value = { type: 'error', text: t('supplier.bank_lookup_no_dic') }
    return
  }
  supplierBankLoading.value = true
  supplierBankMessage.value = null
  supplierBankAccounts.value = []
  try {
    const r = await clientsApi.lookupBank(dic)
    supplierBankAccounts.value = r.accounts
    if (r.accounts.length === 0) {
      supplierBankMessage.value = { type: 'error', text: t('supplier.bank_lookup_none') }
    } else {
      supplierApplyBank(r.accounts[0])
      supplierBankMessage.value = r.accounts.length === 1
        ? { type: 'success', text: t('supplier.bank_lookup_one') }
        : { type: 'success', text: t('supplier.bank_lookup_many', { n: r.accounts.length }) }
    }
    if (r.unreliable === true) supplierBankMessage.value = { type: 'warning', text: t('supplier.bank_lookup_unreliable') }
  } catch (e: any) {
    supplierBankMessage.value = { type: 'error', text: e?.response?.data?.error?.message || t('supplier.bank_lookup_failed') }
  } finally {
    supplierBankLoading.value = false
  }
}

function newSupplier() {
  Object.assign(supplierDraft, {
    company_name: '', street: '', city: '', zip: '', email: '',
    country_iso2: 'CZ', ic: '', dic: '', is_vat_payer: true,
    commercial_register: '', taxpayer_type: undefined,
    default_payment_due_days: 14, default_hourly_rate: 1500,
  })
  Object.assign(supplierBank, { currency: 'CZK', account_number: '', bank_code: '', bank_name: '', iban: '', bic: '' })
  supplierBankMessage.value = null
  supplierBankAccounts.value = []
  supplierAresMessage.value = null
  supplierCreateOpen.value = true
}

async function supplierLookupAres() {
  const ic = (supplierDraft.ic || '').trim()
  if (!/^\d{8}$/.test(ic)) {
    supplierAresMessage.value = { type: 'error', text: t('supplier.ares_invalid_ic') }
    return
  }
  supplierAresLoading.value = true
  supplierAresMessage.value = null
  try {
    const r = await clientsApi.lookupAres(ic)
    if (!r.found || !r.data) {
      supplierAresMessage.value = { type: 'error', text: t('supplier.ares_not_found') }
      return
    }
    const d = r.data
    supplierDraft.company_name = d.company_name || supplierDraft.company_name
    supplierDraft.street       = d.street       || supplierDraft.street
    supplierDraft.city         = d.city         || supplierDraft.city
    supplierDraft.zip          = d.zip          || supplierDraft.zip
    supplierDraft.country_iso2 = d.country_iso2 || supplierDraft.country_iso2 || 'CZ'
    supplierDraft.ic           = d.ic           || ic
    supplierDraft.dic          = d.dic          || supplierDraft.dic
    supplierDraft.is_vat_payer = d.is_vat_payer
    supplierDraft.commercial_register = d.commercial_register || supplierDraft.commercial_register
    if (d.taxpayer_type === 'fo' || d.taxpayer_type === 'po') supplierDraft.taxpayer_type = d.taxpayer_type
    supplierAresMessage.value = { type: 'success', text: t('supplier.ares_loaded', { name: d.company_name }) }
  } catch (e: any) {
    supplierAresMessage.value = { type: 'error', text: e?.response?.data?.error?.message || t('supplier.ares_failed') }
  } finally {
    supplierAresLoading.value = false
  }
}

async function saveSupplier() {
  if (!supplierDraft.company_name || !supplierDraft.street || !supplierDraft.city || !supplierDraft.zip || !supplierDraft.email) {
    toast.error(t('common.error'))
    return
  }
  try {
    const payload = { ...supplierDraft }
    if (supplierBank.account_number || supplierBank.iban) {
      payload.bank_account = {
        currency: supplierBank.currency,
        account_number: supplierBank.account_number || undefined,
        bank_code: supplierBank.bank_code || undefined,
        bank_name: supplierBank.bank_name || undefined,
        iban: supplierBank.iban || undefined,
        bic: supplierBank.bic || undefined,
      }
    }
    await suppliersApi.create(payload)
    supplierCreateOpen.value = false
    toast.success(t('common.saved'))
    await loadAll()
    await auth.refresh()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

async function removeSupplier(s: SupplierListItem) {
  if (s.clients_count > 0 || s.invoices_count > 0) return
  if (!confirm(t('supplier.delete_confirm'))) return
  try {
    await suppliersApi.delete(s.id)
    toast.success(t('common.deleted'))
    await loadAll()
    await auth.refresh()
    if (supplierStore.currentSupplierId === s.id) {
      const first = suppliers.value[0]
      if (first) supplierStore.setSupplier(first.id)
    }
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

function switchSupplier(id: number) {
  if (id === supplierStore.currentSupplierId) return
  supplierStore.setSupplier(id)
  window.location.reload()
}

// ─── VAT rates ────────────────────────────────────────────
const vatDraft = reactive<Partial<VatRate> & { _new?: boolean }>({})
const vatOpen = ref(false)

// Platná sazba = dnešek spadá do intervalu valid_from..valid_to
function isVatValid(v: VatRate): boolean {
  const today = new Date().toISOString().slice(0, 10)
  if (v.valid_from && v.valid_from > today) return false
  if (v.valid_to && v.valid_to < today) return false
  return true
}
// Nejdřív platné sazby, pak ostatní (stabilní řazení v rámci skupin)
const sortedVatRates = computed(() =>
  [...vatRates.value].sort((a, b) => (isVatValid(a) ? 0 : 1) - (isVatValid(b) ? 0 : 1))
)
function newVat() {
  Object.assign(vatDraft, {
    id: undefined, code: '', rate_percent: 21, country: 'CZ',
    label_cs: '', label_en: '', is_default: false, is_reverse_charge: false,
    valid_from: new Date().toISOString().slice(0, 10), valid_to: null, _new: true,
  })
  vatOpen.value = true
}
function editVat(v: VatRate) {
  Object.assign(vatDraft, { ...v, _new: false })
  vatOpen.value = true
}
async function saveVat() {
  try {
    if (vatDraft._new) await settingsApi.createVatRate(vatDraft)
    else if (vatDraft.id) await settingsApi.updateVatRate(vatDraft.id, vatDraft)
    vatOpen.value = false
    toast.success(t('common.saved'))
    await loadAll()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}
async function deleteVat(v: VatRate) {
  if (!confirm(`Smazat sazbu ${v.code} (${v.rate_percent} %)?`)) return
  try {
    await settingsApi.deleteVatRate(v.id)
    toast.success(t('common.deleted'))
    await loadAll()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

// ─── Countries ────────────────────────────────────────────
const countryDraft = reactive<Partial<Country> & { _new?: boolean }>({})
const countryOpen = ref(false)

useHotkey('escape', () => {
  if (vatOpen.value) vatOpen.value = false
  else if (countryOpen.value) countryOpen.value = false
  else if (unitOpen.value) unitOpen.value = false
})

// ─── Units ─────────────────────────────────────────────────
const unitDraft = reactive<Partial<Unit> & { _new?: boolean }>({})
const unitOpen = ref(false)
function newUnit() {
  Object.assign(unitDraft, {
    id: undefined, code: '', label_cs: '', label_en: '',
    is_default: false, display_order: 0, _new: true,
  })
  unitOpen.value = true
}
function editUnit(u: Unit) {
  Object.assign(unitDraft, { ...u, _new: false })
  unitOpen.value = true
}
async function saveUnit() {
  try {
    if (unitDraft._new) await settingsApi.createUnit(unitDraft)
    else if (unitDraft.id) await settingsApi.updateUnit(unitDraft.id, unitDraft)
    unitOpen.value = false
    toast.success(t('common.saved'))
    await loadAll()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}
async function deleteUnit(u: Unit) {
  if (!confirm(`Smazat jednotku ${u.code}?`)) return
  try {
    await settingsApi.deleteUnit(u.id)
    toast.success(t('common.deleted'))
    await loadAll()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}
function newCountry() {
  Object.assign(countryDraft, { id: undefined, iso2: '', iso3: '', name_cs: '', name_en: '', is_eu: false, _new: true })
  countryOpen.value = true
}
function editCountry(c: Country) {
  Object.assign(countryDraft, { ...c, _new: false })
  countryOpen.value = true
}
async function saveCountry() {
  try {
    if (countryDraft._new) await settingsApi.createCountry(countryDraft)
    else if (countryDraft.id) await settingsApi.updateCountry(countryDraft.id, countryDraft)
    countryOpen.value = false
    toast.success(t('common.saved'))
    await loadAll()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}
async function deleteCountry(c: Country) {
  if (!confirm(`Smazat zemi ${c.iso2} – ${c.name_cs}?`)) return
  try {
    await settingsApi.deleteCountry(c.id)
    toast.success(t('common.deleted'))
    await loadAll()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

// ─── Expense categories (kategorie nákladů pro CRM rozpad) ─────────────
const expenseCategories = ref<ExpenseCategory[]>([])
const expenseDraft = reactive({
  id: 0,
  code: '',
  label: '',
  fixed_or_var: 'variable' as 'fixed' | 'variable',
  display_order: 0,
  archived: false,
})
const expenseOpen = ref(false)

async function loadExpenseCategories() {
  expenseCategories.value = await expenseCategoriesApi.list(true)
}

function newExpense() {
  Object.assign(expenseDraft, { id: 0, code: '', label: '', fixed_or_var: 'variable', display_order: 0, archived: false })
  expenseOpen.value = true
}

function editExpense(c: ExpenseCategory) {
  Object.assign(expenseDraft, c)
  expenseOpen.value = true
}

async function saveExpense() {
  try {
    if (expenseDraft.id) {
      await expenseCategoriesApi.update(expenseDraft.id, {
        code: expenseDraft.code,
        label: expenseDraft.label,
        fixed_or_var: expenseDraft.fixed_or_var,
        display_order: expenseDraft.display_order,
        archived: expenseDraft.archived,
      })
    } else {
      await expenseCategoriesApi.create({
        code: expenseDraft.code,
        label: expenseDraft.label,
        fixed_or_var: expenseDraft.fixed_or_var,
        display_order: expenseDraft.display_order,
      })
    }
    expenseOpen.value = false
    toast.success(t('common.saved'))
    await loadExpenseCategories()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

async function removeExpense(c: ExpenseCategory) {
  if (!confirm(t('expense_categories.delete_confirm', { label: c.label }))) return
  try {
    const r = await expenseCategoriesApi.delete(c.id)
    toast.success(r.deleted ? t('common.deleted') : t('expense_categories.archived_due_to_usage'))
    await loadExpenseCategories()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

// ─── Revenue categories (kategorie tržeb pro CRM/Stats rozpad) ─────────
const revenueCategories = ref<RevenueCategory[]>([])
const revenueDraft = reactive({
  id: 0,
  code: '',
  label: '',
  display_order: 0,
  archived: false,
})
const revenueOpen = ref(false)

async function loadRevenueCategories() {
  revenueCategories.value = await revenueCategoriesApi.list(true)
}

function newRevenue() {
  Object.assign(revenueDraft, { id: 0, code: '', label: '', display_order: 0, archived: false })
  revenueOpen.value = true
}

function editRevenue(c: RevenueCategory) {
  Object.assign(revenueDraft, c)
  revenueOpen.value = true
}

async function saveRevenue() {
  try {
    if (revenueDraft.id) {
      await revenueCategoriesApi.update(revenueDraft.id, {
        code: revenueDraft.code,
        label: revenueDraft.label,
        display_order: revenueDraft.display_order,
        archived: revenueDraft.archived,
      })
    } else {
      await revenueCategoriesApi.create({
        code: revenueDraft.code,
        label: revenueDraft.label,
        display_order: revenueDraft.display_order,
      })
    }
    revenueOpen.value = false
    toast.success(t('common.saved'))
    await loadRevenueCategories()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

async function removeRevenue(c: RevenueCategory) {
  if (!confirm(t('revenue_categories.delete_confirm', { label: c.label }))) return
  try {
    const r = await revenueCategoriesApi.delete(c.id)
    toast.success(r.deleted ? t('common.deleted') : t('revenue_categories.archived_due_to_usage'))
    await loadRevenueCategories()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

// ─── VAT classifications (kódy DPHDP3 + KH) ──────────────────────────
const vatClassifications = ref<VatClassification[]>([])
const vatClsDraft = reactive({
  id: 0,
  code: '',
  label: '',
  direction: 'both' as 'sale' | 'purchase' | 'both',
  dphdp3_line: '',
  kh_section: '',
  vat_rate: null as number | null,
  is_reverse_charge: false,
  kh_regime_code: '' as '' | '0' | '1' | '2',
  kh_bad_debt: '' as '' | 'N' | 'P',
  display_order: 100,
  archived: false,
})
const vatClsOpen = ref(false)
const vatClsEditMode = ref<'create' | 'edit'>('create')

async function loadVatClassifications() {
  vatClassifications.value = await vatClassificationsApi.list(undefined, true)
}

function newVatCls() {
  Object.assign(vatClsDraft, { id: 0, code: '', label: '', direction: 'both',
    dphdp3_line: '', kh_section: '', vat_rate: null, is_reverse_charge: false,
    kh_regime_code: '', kh_bad_debt: '',
    display_order: 100, archived: false })
  vatClsEditMode.value = 'create'
  vatClsOpen.value = true
}

function editVatCls(c: VatClassification) {
  Object.assign(vatClsDraft, {
    id: c.id, code: c.code, label: c.label, direction: c.direction,
    dphdp3_line: c.dphdp3_line || '', kh_section: c.kh_section || '',
    vat_rate: c.vat_rate, is_reverse_charge: c.is_reverse_charge,
    kh_regime_code: c.kh_regime_code || '', kh_bad_debt: c.kh_bad_debt || '',
    display_order: c.display_order, archived: c.archived,
  })
  vatClsEditMode.value = 'edit'
  vatClsOpen.value = true
}

async function saveVatCls() {
  try {
    if (vatClsEditMode.value === 'edit') {
      await vatClassificationsApi.update(vatClsDraft.id, {
        label: vatClsDraft.label,
        direction: vatClsDraft.direction,
        dphdp3_line: vatClsDraft.dphdp3_line || null,
        kh_section: vatClsDraft.kh_section || null,
        vat_rate: vatClsDraft.vat_rate,
        is_reverse_charge: vatClsDraft.is_reverse_charge,
        kh_regime_code: vatClsDraft.kh_regime_code || null,
        kh_bad_debt: vatClsDraft.kh_bad_debt || null,
        display_order: vatClsDraft.display_order,
        archived: vatClsDraft.archived,
      })
    } else {
      await vatClassificationsApi.create({
        code: vatClsDraft.code,
        label: vatClsDraft.label,
        direction: vatClsDraft.direction,
        dphdp3_line: vatClsDraft.dphdp3_line || null,
        kh_section: vatClsDraft.kh_section || null,
        vat_rate: vatClsDraft.vat_rate,
        is_reverse_charge: vatClsDraft.is_reverse_charge,
        kh_regime_code: vatClsDraft.kh_regime_code || null,
        kh_bad_debt: vatClsDraft.kh_bad_debt || null,
        display_order: vatClsDraft.display_order,
      })
    }
    vatClsOpen.value = false
    toast.success(t('common.saved'))
    await loadVatClassifications()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

async function removeVatCls(c: VatClassification) {
  if (c.supplier_id === null) {
    toast.error(t('vat_classifications.cannot_delete_global'))
    return
  }
  if (!confirm(t('vat_classifications.delete_confirm', { code: c.code }))) return
  try {
    await vatClassificationsApi.delete(c.id)
    toast.success(t('common.deleted'))
    await loadVatClassifications()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

// ─── Daňové konstanty (číselník — override defaultů z backendu) ──────────
const taxYears = ref<TaxConstantsYear[]>([])
const taxYear = ref<number>(0)
const taxModel = ref<TaxConstantsData | null>(null)
const taxIsOverride = ref(false)
const taxIsNew = ref(false)   // nově přidaný, ještě neuložený rok
const taxSaving = ref(false)

/** Nejvyšší přidatelný rok = aktuální kalendářní rok + 1 (jeden rok dopředu, ne dál). */
const maxAddableTaxYear = computed(() => new Date().getFullYear() + 1)
const canAddTaxYear = computed(() =>
  taxYears.value.length > 0 &&
  Math.max(...taxYears.value.map(y => y.year)) < maxAddableTaxYear.value,
)
const taxRates = [30, 40, 60, 80] as const
const taxBands = ['band1', 'band2', 'band3'] as const

async function loadTaxConstants() {
  taxYears.value = await taxConstantsApi.list()
  if (taxYears.value.length && !taxYears.value.find(y => y.year === taxYear.value)) {
    selectTaxYear(taxYears.value[0].year)
  }
}
function selectTaxYear(year: number) {
  const row = taxYears.value.find(y => y.year === year)
  if (!row) return
  taxYear.value = year
  taxIsOverride.value = row.is_override
  taxIsNew.value = false
  taxModel.value = JSON.parse(JSON.stringify(row.data)) // hluboká kopie pro editaci
  normalizePausalMonthly(year)
}

/**
 * Paušální daň se edituje po MĚSÍČNÍCH zálohách — sazba se může změnit uprostřed
 * roku (2026: 1. pásmo 9 984 → 9 162 Kč od 1. 7.). Roční částka je odvozená,
 * backend ji přepočítá a neukládá. Starší override bez rozvrhu dopočítáme z roční.
 */
function normalizePausalMonthly(year: number) {
  const m = taxModel.value
  if (!m) return
  const segs = Array.isArray(m.pausal_monthly) ? m.pausal_monthly : []
  if (!segs.length) {
    const a = m.pausal_annual || {}
    m.pausal_monthly = [{
      from: `${year}-01-01`,
      band1: Math.round(((a.band1 ?? 0) / 12) * 100) / 100,
      band2: Math.round(((a.band2 ?? 0) / 12) * 100) / 100,
      band3: Math.round(((a.band3 ?? 0) / 12) * 100) / 100,
    }]
    return
  }
  // Ukotvi k editovanému roku (klonovaný rok nese data předchozího) a setřiď.
  m.pausal_monthly = segs
    .map(s => ({ ...s, from: `${year}-${(s.from || '').slice(5, 7) || '01'}-01` }))
    .sort((a, b) => a.from.localeCompare(b.from))
  m.pausal_monthly[0].from = `${year}-01-01`
}

const pausalMonthOptions = computed(() =>
  Array.from({ length: 12 }, (_, i) => ({
    value: `${taxYear.value}-${String(i + 1).padStart(2, '0')}-01`,
    label: formatMonth(`${taxYear.value}-${String(i + 1).padStart(2, '0')}`),
  })))

/** Roční částka = součet 12 měsíčních záloh (zrcadlo PausalSchedule::annual). */
const pausalAnnualPreview = computed<Record<string, number>>(() => {
  const segs = taxModel.value?.pausal_monthly ?? []
  const out: Record<string, number> = { band1: 0, band2: 0, band3: 0 }
  if (!segs.length) return out
  for (let mo = 1; mo <= 12; mo++) {
    const key = `${taxYear.value}-${String(mo).padStart(2, '0')}-01`
    let cur = segs[0]
    for (const s of segs) if (s.from <= key) cur = s
    for (const b of taxBands) out[b] += Number((cur as any)[b] || 0)
  }
  return out
})

function addPausalSegment() {
  const segs = taxModel.value?.pausal_monthly
  if (!segs?.length) return
  const last = segs[segs.length - 1]
  const nextMonth = Number(last.from.slice(5, 7)) + 1
  if (nextMonth > 12) return
  segs.push({ ...last, from: `${taxYear.value}-${String(nextMonth).padStart(2, '0')}-01` })
}
function removePausalSegment(i: number) {
  if (i > 0) taxModel.value?.pausal_monthly.splice(i, 1)
}
/** Po ruční změně data drž období vzestupně (backend jinak zápis odmítne). */
function sortPausalSegments() {
  taxModel.value?.pausal_monthly.sort((a, b) => a.from.localeCompare(b.from))
}
function pausalMonthTaken(value: string, i: number): boolean {
  return (taxModel.value?.pausal_monthly ?? []).some((s, j) => j !== i && s.from === value)
}

/** Přidá další rok (nejnovější + 1) předvyplněný hodnotami nejnovějšího roku a rovnou ho uloží do DB,
 *  aby přežil reload. Hodnoty lze následně upravit a uložit znovu.
 *  Strop: lze přidat maximálně aktuální kalendářní rok + 1 (jeden rok dopředu). */
async function addTaxYear() {
  if (!taxYears.value.length) return
  const maxYear = Math.max(...taxYears.value.map(y => y.year))
  const newYear = maxYear + 1
  if (newYear > maxAddableTaxYear.value) return
  if (taxYears.value.some(y => y.year === newYear)) { selectTaxYear(newYear); return }
  const base = taxYears.value.find(y => y.year === maxYear)
  if (!base) return
  const cloned = JSON.parse(JSON.stringify(base.data))
  cloned.year = newYear
  // Rozvrh paušálních záloh je vázaný na rok: do nového roku přebíráme poslední
  // platnou sazbu jako jediné období od 1. 1. (změnu si admin doplní sám).
  const baseSegs = Array.isArray(cloned.pausal_monthly) ? cloned.pausal_monthly : []
  if (baseSegs.length) {
    cloned.pausal_monthly = [{ ...baseSegs[baseSegs.length - 1], from: `${newYear}-01-01` }]
  }
  taxSaving.value = true
  try {
    const saved = await taxConstantsApi.save(newYear, cloned)
    taxYears.value.unshift(saved)
    taxYears.value.sort((a, b) => b.year - a.year)
    taxYear.value = newYear
    taxIsOverride.value = saved.is_override
    taxIsNew.value = false
    taxModel.value = JSON.parse(JSON.stringify(saved.data))
    toast.success(t('codebooks.tax_year_added', { year: newYear }))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally { taxSaving.value = false }
}
async function saveTaxConstants() {
  if (!taxModel.value) return
  taxSaving.value = true
  try {
    const updated = await taxConstantsApi.save(taxYear.value, taxModel.value)
    const i = taxYears.value.findIndex(y => y.year === taxYear.value)
    if (i >= 0) taxYears.value[i] = updated
    taxIsOverride.value = updated.is_override
    taxIsNew.value = false
    toast.success(t('codebooks.tax_saved'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally { taxSaving.value = false }
}
async function resetTaxConstants() {
  if (!confirm(t('codebooks.tax_reset_confirm'))) return
  try {
    const updated = await taxConstantsApi.reset(taxYear.value)
    const i = taxYears.value.findIndex(y => y.year === taxYear.value)
    if (i >= 0) taxYears.value[i] = updated
    taxIsOverride.value = false
    taxModel.value = JSON.parse(JSON.stringify(updated.data))
    toast.success(t('codebooks.tax_reset_done'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

// Načti při přepnutí na tab=expense_categories / revenue_categories / vat_classifications / tax_constants
watch(tab, (newTab) => {
  if (newTab === 'expense_categories') loadExpenseCategories()
  if (newTab === 'revenue_categories') loadRevenueCategories()
  if (newTab === 'vat_classifications') loadVatClassifications()
  if (newTab === 'tax_constants') loadTaxConstants()
  if (newTab === 'repo_rates' && !repoSnapshot.value) loadRepoRates()
})

// ─── CNB repo rate (late-payment interest) ────────────────────────────────
const repoSnapshot = ref<RepoRateSnapshot | null>(null)
const repoBusy = ref(false)
const repoNew = reactive({ valid_from: '', rate_percent: null as number | null })

async function loadRepoRates() {
  try {
    repoSnapshot.value = await repoRatesApi.list()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

async function fetchRepoRates() {
  repoBusy.value = true
  try {
    const r = await repoRatesApi.fetchFromCnb()
    repoSnapshot.value = r
    toast.success(t('codebooks.repo_fetched', { count: r.fetch.added.length }))
    if (r.fetch.conflicts.length > 0) {
      toast.error(t('codebooks.repo_conflicts', { count: r.fetch.conflicts.length }))
    }
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally { repoBusy.value = false }
}

async function addRepoRate() {
  if (!repoNew.valid_from || typeof repoNew.rate_percent !== 'number') return
  repoBusy.value = true
  try {
    repoSnapshot.value = await repoRatesApi.create({ valid_from: repoNew.valid_from, rate_percent: repoNew.rate_percent })
    repoNew.valid_from = ''
    repoNew.rate_percent = null
    toast.success(t('codebooks.repo_added'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally { repoBusy.value = false }
}

async function removeRepoRate(validFrom: string) {
  if (!confirm(t('codebooks.repo_delete_confirm', { date: formatDate(validFrom) }))) return
  repoBusy.value = true
  try {
    repoSnapshot.value = await repoRatesApi.remove(validFrom)
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally { repoBusy.value = false }
}
</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('codebooks.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('codebooks.subtitle') }}</p>
    </div>

    <!-- Tabs — Dodavatelé jako první volba (multi-tenant firmy embed do Codebooks) -->
    <div class="border-b border-neutral-200 mb-4 flex gap-1 overflow-x-auto">
      <button v-for="tt in (['suppliers', 'vat', 'vat_classifications', 'expense_categories', 'revenue_categories', 'countries', 'units', 'tax_constants', 'repo_rates'] as const)" :key="tt"
        @click="tab = tt"
        class="cursor-pointer px-4 py-2 text-sm border-b-2 transition whitespace-nowrap"
        :class="tab === tt
          ? 'border-primary-600 text-primary-700 font-medium'
          : 'border-transparent text-neutral-600 hover:text-neutral-900'">
        {{ tt === 'suppliers' ? t('nav.suppliers')
          : tt === 'vat' ? t('codebooks.tab_vat')
          : tt === 'vat_classifications' ? t('codebooks.tab_vat_classifications')
          : tt === 'expense_categories' ? t('codebooks.tab_expense_categories')
          : tt === 'revenue_categories' ? t('codebooks.tab_revenue_categories')
          : tt === 'countries' ? t('codebooks.tab_countries')
          : tt === 'units' ? t('codebooks.tab_units')
          : tt === 'tax_constants' ? t('codebooks.tab_tax_constants')
          : t('codebooks.tab_repo_rates') }}
      </button>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <!-- ====== SUPPLIERS (multi-tenant firmy) ====== -->
    <section v-else-if="tab === 'suppliers'">
      <div class="flex justify-end mb-3">
        <button @click="newSupplier"
          class="cursor-pointer h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md inline-flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
          {{ t('supplier.new') }}
        </button>
      </div>

      <!-- Desktop tabulka -->
      <div class="hidden md:block bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-sm table-sticky-first">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 w-10"></th>
                <th class="px-3 py-2 text-left font-medium">{{ t('supplier.company_name') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('supplier.ic') }} / {{ t('supplier.dic') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('supplier.clients') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('supplier.invoices') }}</th>
                <th class="px-3 py-2 w-48"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="s in suppliers" :key="s.id" class="hover:bg-neutral-50">
                <td class="px-3 py-2 text-center">
                  <span v-if="s.id === supplierStore.currentSupplierId" class="text-primary-600 text-base" :title="t('supplier.active_label')">●</span>
                </td>
                <td class="px-3 py-2">
                  <div class="font-medium text-neutral-900">{{ s.company_name }}</div>
                  <div v-if="s.display_name && s.display_name !== s.company_name" class="text-xs text-neutral-500">{{ s.display_name }}</div>
                </td>
                <td class="px-3 py-2 font-mono text-xs">
                  <span v-if="s.ic">{{ s.ic }}</span>
                  <span v-if="s.ic && s.dic"> / </span>
                  <span v-if="s.dic">{{ s.dic }}</span>
                  <span v-if="!s.ic && !s.dic" class="text-neutral-400">—</span>
                </td>
                <td class="px-3 py-2 text-right font-mono">{{ s.clients_count }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ s.invoices_count }}</td>
                <td class="px-3 py-2 text-right text-xs">
                  <button v-if="s.id !== supplierStore.currentSupplierId" @click="switchSupplier(s.id)"
                    class="cursor-pointer text-primary-600 hover:text-primary-700 mr-3">
                    {{ t('supplier.switch') }}
                  </button>
                  <button @click="removeSupplier(s)" :disabled="s.clients_count > 0 || s.invoices_count > 0 || suppliers.length <= 1"
                    class="cursor-pointer text-danger-500 hover:text-danger-600 disabled:opacity-30 disabled:cursor-not-allowed">
                    {{ t('common.delete') }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Mobile karty -->
      <div class="md:hidden bg-surface border border-neutral-200 rounded-lg shadow-sm divide-y divide-neutral-100 overflow-hidden">
        <div v-for="s in suppliers" :key="`m-${s.id}`" class="px-4 py-3">
          <div class="flex items-baseline justify-between gap-2">
            <div class="font-medium text-neutral-900 flex items-center gap-1.5 min-w-0 truncate">
              <span v-if="s.id === supplierStore.currentSupplierId" class="text-primary-600 text-base shrink-0" :title="t('supplier.active_label')">●</span>
              {{ s.company_name }}
            </div>
          </div>
          <div class="flex items-baseline justify-between gap-2 mt-1 text-xs text-neutral-500">
            <span class="font-mono">
              <span v-if="s.ic">{{ s.ic }}</span>
              <span v-if="s.ic && s.dic"> / </span>
              <span v-if="s.dic">{{ s.dic }}</span>
              <span v-if="!s.ic && !s.dic" class="text-neutral-400">—</span>
            </span>
            <span class="font-mono">{{ t('supplier.clients') }}: {{ s.clients_count }} · {{ t('supplier.invoices') }}: {{ s.invoices_count }}</span>
          </div>
          <div class="flex gap-3 mt-2 text-xs">
            <button v-if="s.id !== supplierStore.currentSupplierId" @click="switchSupplier(s.id)"
              class="cursor-pointer text-primary-600 hover:text-primary-700">{{ t('supplier.switch') }}</button>
            <button @click="removeSupplier(s)" :disabled="s.clients_count > 0 || s.invoices_count > 0 || suppliers.length <= 1"
              class="cursor-pointer ml-auto text-danger-500 hover:text-danger-600 disabled:opacity-30 disabled:cursor-not-allowed">
              {{ t('common.delete') }}
            </button>
          </div>
        </div>
      </div>
    </section>

    <!-- ====== VAT RATES ====== -->
    <section v-else-if="tab === 'vat'">
      <div class="flex justify-end mb-3">
        <button @click="newVat"
          class="cursor-pointer h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md inline-flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
          {{ t('codebooks.new_vat') }}
        </button>
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <!-- Desktop: tabulka -->
        <div class="hidden md:block overflow-x-auto">
        <table class="w-full text-sm table-sticky-first">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-center font-medium">{{ t('codebooks.country') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('codebooks.code') }}</th>
              <th class="px-3 py-2 text-right font-medium">%</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('codebooks.name_cs') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('codebooks.is_default') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('codebooks.is_reverse_charge') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('codebooks.valid') }}</th>
              <th class="px-3 py-2 w-32"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="v in sortedVatRates" :key="v.id" :class="isVatValid(v) ? 'font-semibold' : 'text-neutral-400'">
              <td class="px-3 py-2 text-center font-mono">{{ v.country }}</td>
              <td class="px-3 py-2 font-mono text-xs">{{ v.code }}</td>
              <td class="px-3 py-2 text-right font-mono">{{ v.rate_percent }} %</td>
              <td class="px-3 py-2">{{ v.label_cs }}</td>
              <td class="px-3 py-2 text-center"><span v-if="v.is_default" class="text-primary-600">✓</span></td>
              <td class="px-3 py-2 text-center"><span v-if="v.is_reverse_charge" class="text-warning-600">⇄</span></td>
              <td class="px-3 py-2 text-xs text-neutral-500">{{ v.valid_from }}<span v-if="v.valid_to"> – {{ v.valid_to }}</span></td>
              <td class="px-3 py-2 text-right text-xs">
                <button @click="editVat(v)" class="cursor-pointer text-primary-600 hover:text-primary-700 mr-3">{{ t('common.edit') }}</button>
                <button @click="deleteVat(v)" :disabled="(v.items_count ?? 0) > 0"
                  class="cursor-pointer text-danger-500 hover:text-danger-600 disabled:opacity-30 disabled:cursor-not-allowed"
                  :title="(v.items_count ?? 0) > 0 ? t('codebooks.in_use_vat', { n: v.items_count }) : t('common.delete')">
                  {{ t('common.delete') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
        </div>

        <!-- Mobile: karty -->
        <div class="md:hidden divide-y divide-neutral-100">
          <div v-for="v in sortedVatRates" :key="`m-${v.id}`" class="p-3 space-y-1.5" :class="{ 'opacity-50': !isVatValid(v) }">
            <div class="flex items-baseline justify-between gap-2">
              <div class="flex items-baseline gap-2">
                <span class="font-mono text-xs">{{ v.country }}</span>
                <span class="font-mono text-sm font-semibold">{{ v.code }}</span>
                <span class="text-sm text-neutral-700">{{ v.label_cs }}</span>
              </div>
              <span class="font-mono font-semibold">{{ v.rate_percent }} %</span>
            </div>
            <div class="flex items-center justify-between gap-2 text-xs">
              <span class="text-neutral-500">
                <span v-if="v.is_default" class="text-primary-600">✓ {{ t('codebooks.is_default') }}</span>
                <span v-if="v.is_default && v.is_reverse_charge" class="text-neutral-400 mx-1.5">·</span>
                <span v-if="v.is_reverse_charge" class="text-warning-600">⇄ RC</span>
              </span>
              <span class="text-neutral-500">{{ v.valid_from }}<span v-if="v.valid_to"> – {{ v.valid_to }}</span></span>
            </div>
            <div class="flex justify-end gap-2">
              <button @click="editVat(v)" class="cursor-pointer h-8 px-3 text-xs border border-primary-500/40 text-primary-700 hover:bg-primary-50 rounded">{{ t('common.edit') }}</button>
              <button @click="deleteVat(v)" :disabled="(v.items_count ?? 0) > 0"
                class="cursor-pointer h-8 px-3 text-xs border border-danger-500/40 text-danger-500 hover:bg-danger-50 disabled:opacity-30 disabled:cursor-not-allowed rounded"
                :title="(v.items_count ?? 0) > 0 ? t('codebooks.in_use_vat', { n: v.items_count }) : t('common.delete')">
                {{ t('common.delete') }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ====== COUNTRIES ====== -->
    <section v-else-if="tab === 'countries'">
      <div class="flex justify-end mb-3">
        <button @click="newCountry"
          class="cursor-pointer h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md inline-flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
          {{ t('codebooks.new_country') }}
        </button>
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <!-- Desktop: tabulka -->
        <div class="hidden md:block overflow-x-auto">
        <table class="w-full text-sm table-sticky-first">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-center font-medium">{{ t('codebooks.iso2') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('codebooks.iso3') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('codebooks.name_cs') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('codebooks.name_en') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('codebooks.is_eu') }}</th>
              <th class="px-3 py-2 w-32"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="c in countries" :key="c.id">
              <td class="px-3 py-2 text-center font-mono">{{ c.iso2 }}</td>
              <td class="px-3 py-2 text-center font-mono text-xs">{{ c.iso3 }}</td>
              <td class="px-3 py-2">{{ c.name_cs }}</td>
              <td class="px-3 py-2 text-neutral-500">{{ c.name_en }}</td>
              <td class="px-3 py-2 text-center"><span v-if="c.is_eu" class="text-primary-600">EU</span></td>
              <td class="px-3 py-2 text-right text-xs">
                <button @click="editCountry(c)" class="cursor-pointer text-primary-600 hover:text-primary-700 mr-3">{{ t('common.edit') }}</button>
                <button @click="deleteCountry(c)" :disabled="(c.uses_count ?? 0) > 0"
                  class="cursor-pointer text-danger-500 hover:text-danger-600 disabled:opacity-30 disabled:cursor-not-allowed"
                  :title="(c.uses_count ?? 0) > 0 ? t('codebooks.in_use_country', { n: c.uses_count }) : t('common.delete')">
                  {{ t('common.delete') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
        </div>

        <!-- Mobile: karty -->
        <div class="md:hidden divide-y divide-neutral-100">
          <div v-for="c in countries" :key="`m-${c.id}`" class="p-3 space-y-1.5">
            <div class="flex items-baseline justify-between gap-2">
              <div class="flex items-baseline gap-2">
                <span class="font-mono font-semibold">{{ c.iso2 }}</span>
                <span class="font-mono text-xs text-neutral-500">{{ c.iso3 }}</span>
                <span class="text-sm">{{ c.name_cs }}</span>
              </div>
              <span v-if="c.is_eu" class="text-xs px-2 py-0.5 rounded bg-primary-100 text-primary-700">EU</span>
            </div>
            <div class="flex items-center justify-between gap-2">
              <span class="text-xs text-neutral-500 truncate">{{ c.name_en }}</span>
              <div class="flex gap-2">
                <button @click="editCountry(c)" class="cursor-pointer h-8 px-3 text-xs border border-primary-500/40 text-primary-700 hover:bg-primary-50 rounded">{{ t('common.edit') }}</button>
                <button @click="deleteCountry(c)" :disabled="(c.uses_count ?? 0) > 0"
                  class="cursor-pointer h-8 px-3 text-xs border border-danger-500/40 text-danger-500 hover:bg-danger-50 disabled:opacity-30 disabled:cursor-not-allowed rounded"
                  :title="(c.uses_count ?? 0) > 0 ? t('codebooks.in_use_country', { n: c.uses_count }) : t('common.delete')">
                  {{ t('common.delete') }}
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ====== UNITS ====== -->
    <section v-else-if="tab === 'units'">
      <div class="flex justify-end mb-3">
        <button @click="newUnit"
          class="cursor-pointer h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md inline-flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
          {{ t('codebooks.new_unit') }}
        </button>
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <!-- Desktop: tabulka -->
        <div class="hidden md:block overflow-x-auto">
        <table class="w-full text-sm table-sticky-first">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('codebooks.code') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('codebooks.name_cs') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('codebooks.name_en') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('codebooks.is_default') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('codebooks.display_order') }}</th>
              <th class="px-3 py-2 w-32"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="u in units" :key="u.id">
              <td class="px-3 py-2 font-mono">{{ u.code }}</td>
              <td class="px-3 py-2">{{ u.label_cs }}</td>
              <td class="px-3 py-2 text-neutral-500">{{ u.label_en }}</td>
              <td class="px-3 py-2 text-center"><span v-if="u.is_default" class="text-primary-600">✓</span></td>
              <td class="px-3 py-2 text-center font-mono text-xs">{{ u.display_order }}</td>
              <td class="px-3 py-2 text-right text-xs">
                <button @click="editUnit(u)" class="cursor-pointer text-primary-600 hover:text-primary-700 mr-3">{{ t('common.edit') }}</button>
                <button @click="deleteUnit(u)" :disabled="(u.items_count ?? 0) > 0"
                  class="cursor-pointer text-danger-500 hover:text-danger-600 disabled:opacity-30 disabled:cursor-not-allowed"
                  :title="(u.items_count ?? 0) > 0 ? t('codebooks.in_use_unit', { n: u.items_count }) : t('common.delete')">
                  {{ t('common.delete') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
        </div>

        <!-- Mobile: karty -->
        <div class="md:hidden divide-y divide-neutral-100">
          <div v-for="u in units" :key="`m-${u.id}`" class="p-3 space-y-1.5">
            <div class="flex items-baseline justify-between gap-2">
              <div class="flex items-baseline gap-2">
                <span class="font-mono font-semibold">{{ u.code }}</span>
                <span class="text-sm text-neutral-700">{{ u.label_cs }}</span>
                <span class="text-xs text-neutral-500">· {{ u.label_en }}</span>
              </div>
              <span v-if="u.is_default" class="text-primary-600 text-xs">✓ {{ t('codebooks.is_default') }}</span>
            </div>
            <div class="flex justify-end gap-2">
              <button @click="editUnit(u)" class="cursor-pointer h-8 px-3 text-xs border border-primary-500/40 text-primary-700 hover:bg-primary-50 rounded">{{ t('common.edit') }}</button>
              <button @click="deleteUnit(u)" :disabled="(u.items_count ?? 0) > 0"
                class="cursor-pointer h-8 px-3 text-xs border border-danger-500/40 text-danger-500 hover:bg-danger-50 disabled:opacity-30 disabled:cursor-not-allowed rounded"
                :title="(u.items_count ?? 0) > 0 ? t('codebooks.in_use_unit', { n: u.items_count }) : t('common.delete')">
                {{ t('common.delete') }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ====== EXPENSE CATEGORIES ====== -->
    <section v-else-if="tab === 'expense_categories'">
      <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center mb-3 gap-2">
        <p class="text-sm text-neutral-500">{{ t('expense_categories.hint') }}</p>
        <button @click="newExpense"
          class="cursor-pointer shrink-0 self-start whitespace-nowrap h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md inline-flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
          {{ t('expense_categories.new') }}
        </button>
      </div>

      <div v-if="expenseCategories.length === 0" class="bg-surface border border-dashed border-neutral-300 rounded-lg p-8 text-center text-sm text-neutral-500">
        {{ t('expense_categories.empty') }}
      </div>

      <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium w-24">{{ t('expense_categories.code') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('expense_categories.label') }}</th>
              <th class="px-3 py-2 text-center font-medium w-24">{{ t('expense_categories.fixed_or_var') }}</th>
              <th class="px-3 py-2 text-right font-medium w-24">{{ t('expense_categories.usage') }}</th>
              <th class="px-3 py-2 w-40"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="c in expenseCategories" :key="c.id" :class="['hover:bg-neutral-50', c.archived ? 'opacity-50' : '']">
              <td class="px-3 py-2 font-mono text-xs">{{ c.code }}</td>
              <td class="px-3 py-2">
                {{ c.label }}
                <span v-if="c.archived" class="ml-2 text-xs px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-500">{{ t('expense_categories.archived') }}</span>
              </td>
              <td class="px-3 py-2 text-center text-xs">
                <span :class="c.fixed_or_var === 'fixed' ? 'text-primary-700' : 'text-warning-600'">
                  {{ c.fixed_or_var === 'fixed' ? t('expense_categories.fixed') : t('expense_categories.variable') }}
                </span>
              </td>
              <td class="px-3 py-2 text-right font-mono text-xs text-neutral-600">{{ c.purchases_count || 0 }}</td>
              <td class="px-3 py-2 text-right text-xs">
                <button @click="editExpense(c)" class="cursor-pointer text-primary-600 hover:text-primary-700 mr-3">
                  {{ t('common.edit') }}
                </button>
                <button @click="removeExpense(c)" class="cursor-pointer text-danger-500 hover:text-danger-600">
                  {{ t('common.delete') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- ====== REVENUE CATEGORIES (kategorie tržeb) ====== -->
    <section v-else-if="tab === 'revenue_categories'">
      <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center mb-3 gap-2">
        <p class="text-sm text-neutral-500">{{ t('revenue_categories.hint') }}</p>
        <button @click="newRevenue"
          class="cursor-pointer shrink-0 self-start whitespace-nowrap h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md inline-flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
          {{ t('revenue_categories.new') }}
        </button>
      </div>

      <div v-if="revenueCategories.length === 0" class="bg-surface border border-dashed border-neutral-300 rounded-lg p-8 text-center text-sm text-neutral-500">
        {{ t('revenue_categories.empty') }}
      </div>

      <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium w-24">{{ t('revenue_categories.code') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('revenue_categories.label') }}</th>
              <th class="px-3 py-2 text-right font-medium w-24">{{ t('revenue_categories.usage') }}</th>
              <th class="px-3 py-2 w-40"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="c in revenueCategories" :key="c.id" :class="['hover:bg-neutral-50', c.archived ? 'opacity-50' : '']">
              <td class="px-3 py-2 font-mono text-xs">{{ c.code }}</td>
              <td class="px-3 py-2">
                {{ c.label }}
                <span v-if="c.archived" class="ml-2 text-xs px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-500">{{ t('revenue_categories.archived') }}</span>
              </td>
              <td class="px-3 py-2 text-right font-mono text-xs text-neutral-600">{{ c.invoices_count || 0 }}</td>
              <td class="px-3 py-2 text-right text-xs">
                <button @click="editRevenue(c)" class="cursor-pointer text-primary-600 hover:text-primary-700 mr-3">
                  {{ t('common.edit') }}
                </button>
                <button @click="removeRevenue(c)" class="cursor-pointer text-danger-500 hover:text-danger-600">
                  {{ t('common.delete') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- ====== VAT CLASSIFICATIONS ====== -->
    <section v-else-if="tab === 'vat_classifications'">
      <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center mb-3 gap-2">
        <p class="text-sm text-neutral-500">{{ t('vat_classifications.hint') }}</p>
        <button @click="newVatCls"
          class="cursor-pointer shrink-0 self-start whitespace-nowrap h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md inline-flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
          {{ t('vat_classifications.new') }}
        </button>
      </div>

      <div v-if="vatClassifications.length === 0" class="bg-surface border border-dashed border-neutral-300 rounded-lg p-8 text-center text-sm text-neutral-500">
        {{ t('vat_classifications.empty') }}
      </div>

      <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium w-20">{{ t('vat_classifications.code') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('vat_classifications.label') }}</th>
              <th class="px-3 py-2 text-center font-medium w-24">{{ t('vat_classifications.direction') }}</th>
              <th class="px-3 py-2 text-center font-medium w-20">{{ t('vat_classifications.dphdp3_line') }}</th>
              <th class="px-3 py-2 text-center font-medium w-20">{{ t('vat_classifications.kh_section') }}</th>
              <th class="px-3 py-2 text-right font-medium w-16">{{ t('vat_classifications.vat_rate') }}</th>
              <th class="px-3 py-2 w-32"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="c in vatClassifications" :key="c.id" :class="['hover:bg-neutral-50', c.archived ? 'opacity-50' : '']">
              <td class="px-3 py-2 font-mono text-xs font-medium">
                {{ c.code }}
                <span v-if="c.is_reverse_charge" class="ml-1 text-xs px-1 py-0.5 rounded bg-warning-50 text-warning-600">RC</span>
              </td>
              <td class="px-3 py-2 text-xs">
                {{ c.label.length > 80 ? c.label.slice(0, 80) + '…' : c.label }}
                <span v-if="c.supplier_id === null" class="ml-2 text-xs px-1.5 py-0.5 rounded bg-primary-50 text-primary-700">{{ t('vat_classifications.global') }}</span>
                <span v-if="c.archived" class="ml-2 text-xs px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-500">{{ t('vat_classifications.archived') }}</span>
              </td>
              <td class="px-3 py-2 text-center text-xs">
                <span :class="c.direction === 'sale' ? 'text-success-600' : c.direction === 'purchase' ? 'text-warning-600' : 'text-neutral-600'">
                  {{ t('vat_classifications.direction_' + c.direction) }}
                </span>
              </td>
              <td class="px-3 py-2 text-center font-mono text-xs">{{ c.dphdp3_line ?? '—' }}</td>
              <td class="px-3 py-2 text-center font-mono text-xs">{{ c.kh_section ?? '—' }}</td>
              <td class="px-3 py-2 text-right font-mono text-xs">{{ c.vat_rate !== null ? c.vat_rate.toFixed(0) + '%' : '—' }}</td>
              <td class="px-3 py-2 text-right text-xs">
                <button @click="editVatCls(c)" :disabled="c.supplier_id === null"
                  :title="c.supplier_id === null ? t('vat_classifications.global_readonly') : t('common.edit')"
                  class="cursor-pointer text-primary-600 hover:text-primary-700 disabled:opacity-30 disabled:cursor-not-allowed mr-3">
                  {{ t('common.edit') }}
                </button>
                <button @click="removeVatCls(c)" :disabled="c.supplier_id === null"
                  :title="c.supplier_id === null ? t('vat_classifications.global_readonly') : t('common.delete')"
                  class="cursor-pointer text-danger-500 hover:text-danger-600 disabled:opacity-30 disabled:cursor-not-allowed">
                  {{ t('common.delete') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- ====== CNB REPO RATE (late-payment interest) ====== -->
    <section v-else-if="tab === 'repo_rates'">
      <div class="flex flex-wrap items-center gap-3 mb-3">
        <p class="text-sm text-neutral-500 max-w-3xl">{{ t('codebooks.repo_hint') }}</p>
        <button v-if="auth.isAdmin" @click="fetchRepoRates" :disabled="repoBusy" type="button"
          class="cursor-pointer ml-auto h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md disabled:opacity-50">
          {{ repoBusy ? '…' : t('codebooks.repo_fetch') }}
        </button>
      </div>

      <div v-if="repoSnapshot" class="mb-3 text-sm rounded-md px-3 py-2 border"
        :class="!repoSnapshot.is_current ? 'bg-warning-50 border-warning-500/40 text-warning-700' : 'bg-success-50 border-success-500/40 text-success-700'">
        <template v-if="repoSnapshot.verified_on">{{ t('codebooks.repo_verified_on', { date: formatDate(repoSnapshot.verified_on) }) }}</template>
        <template v-else>{{ t('codebooks.repo_never_verified') }}</template>
        <template v-if="!repoSnapshot.is_current"> — {{ t('codebooks.repo_stale') }}</template>
      </div>

      <div v-if="!repoSnapshot" class="text-center text-neutral-500 py-8 text-sm">{{ t('common.loading') }}</div>
      <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('codebooks.repo_valid_from') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('codebooks.repo_rate') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('codebooks.repo_source') }}</th>
              <th v-if="auth.isAdmin" class="px-3 py-2 w-24"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="r in repoSnapshot.rates" :key="r.valid_from" class="hover:bg-neutral-50">
              <td class="px-3 py-2">{{ formatDate(r.valid_from) }}</td>
              <td class="px-3 py-2 text-right font-mono">{{ r.rate_percent.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }} %</td>
              <td class="px-3 py-2">
                <span class="text-xs px-1.5 py-0.5 rounded"
                  :class="r.source === 'manual' ? 'bg-warning-50 text-warning-600' : r.source === 'cnb' ? 'bg-primary-50 text-primary-700' : 'bg-neutral-100 text-neutral-500'">
                  {{ t('codebooks.repo_source_' + r.source) }}
                </span>
              </td>
              <td v-if="auth.isAdmin" class="px-3 py-2 text-right text-xs">
                <button v-if="r.source === 'manual'" @click="removeRepoRate(r.valid_from)" :disabled="repoBusy" type="button"
                  class="cursor-pointer text-danger-500 hover:text-danger-600 disabled:opacity-50">{{ t('common.delete') }}</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <form v-if="auth.isAdmin" @submit.prevent="addRepoRate" class="mt-4 flex flex-wrap items-end gap-3">
        <div>
          <label class="block text-xs text-neutral-500 mb-1">{{ t('codebooks.repo_valid_from') }}</label>
          <input v-model="repoNew.valid_from" type="date" required class="h-9 px-3 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div>
          <label class="block text-xs text-neutral-500 mb-1">{{ t('codebooks.repo_rate') }}</label>
          <input v-model.number="repoNew.rate_percent" type="number" step="0.01" min="0" required class="w-28 h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
        </div>
        <button type="submit" :disabled="repoBusy"
          class="cursor-pointer h-9 px-3 text-sm border border-primary-300 text-primary-700 rounded-md hover:bg-primary-50 disabled:opacity-50">{{ t('codebooks.repo_add') }}</button>
        <p class="text-xs text-neutral-500 basis-full">{{ t('codebooks.repo_add_hint') }}</p>
      </form>
    </section>

    <!-- ====== TAX CONSTANTS (roční daňové konstanty) ====== -->
    <section v-else-if="tab === 'tax_constants'">
      <div class="flex flex-wrap items-center gap-3 mb-3">
        <label class="text-sm text-neutral-600">{{ t('codebooks.tax_year') }}:</label>
        <select v-model.number="taxYear" @change="selectTaxYear(taxYear)"
          class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option v-for="y in taxYears" :key="y.year" :value="y.year">{{ y.year }}</option>
        </select>
        <button v-if="auth.user?.role === 'admin'" @click="addTaxYear" type="button"
          :disabled="!canAddTaxYear"
          :title="canAddTaxYear ? '' : t('codebooks.tax_add_year_max', { year: maxAddableTaxYear })"
          class="cursor-pointer h-9 px-3 text-sm border border-primary-300 text-primary-700 rounded-md hover:bg-primary-50 inline-flex items-center gap-1 disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-transparent">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
          {{ t('codebooks.tax_add_year') }}
        </button>
        <span v-if="taxIsNew" class="text-xs px-2 py-0.5 rounded bg-primary-50 text-primary-700 font-medium">{{ t('codebooks.tax_year_new') }}</span>
        <span v-else-if="taxIsOverride" class="text-xs px-2 py-0.5 rounded bg-warning-50 text-warning-600 font-medium">{{ t('codebooks.tax_overridden') }}</span>
        <span v-else class="text-xs px-2 py-0.5 rounded bg-neutral-100 text-neutral-500">{{ t('codebooks.tax_default') }}</span>
        <div class="ml-auto flex items-center gap-2" v-if="auth.user?.role === 'admin'">
          <button v-if="taxIsOverride" @click="resetTaxConstants" type="button"
            class="cursor-pointer h-9 px-3 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">{{ t('codebooks.tax_reset') }}</button>
          <button @click="saveTaxConstants" :disabled="taxSaving" type="button"
            class="cursor-pointer h-9 px-4 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md disabled:opacity-50">{{ t('common.save') }}</button>
        </div>
      </div>
      <p class="text-xs text-neutral-500 mb-4 max-w-3xl">{{ t('codebooks.tax_hint') }}</p>

      <div v-if="taxModel" class="grid lg:grid-cols-2 gap-4">
        <!-- DPH a výkazy — platí pro VŠECHNY plátce (nejen OSVČ), proto zvýrazněně nahoře -->
        <div class="bg-primary-50/40 border border-primary-300 rounded-lg p-4 shadow-sm lg:col-span-2">
          <div class="flex flex-wrap items-center gap-2 mb-1">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-primary-700">{{ t('codebooks.tax_g_vat_general') }}</h3>
            <span class="text-[11px] px-2 py-0.5 rounded-full bg-primary-600 text-white font-medium">{{ t('codebooks.tax_g_vat_general_badge') }}</span>
          </div>
          <p class="text-xs text-neutral-500 mb-3 max-w-3xl">{{ t('codebooks.tax_g_vat_general_hint') }}</p>
          <div class="grid sm:grid-cols-2 lg:grid-cols-5 gap-3">
            <label class="block"><span class="text-xs text-neutral-500">{{ t('codebooks.tax_f_vat_rate_standard') }}</span>
              <input v-model.number="taxModel.vat_rate_standard" type="number" step="0.1" class="mt-0.5 h-8 w-full px-2 border border-neutral-300 rounded text-sm font-mono" /></label>
            <label class="block"><span class="text-xs text-neutral-500">{{ t('codebooks.tax_f_vat_rate_reduced') }}</span>
              <input v-model.number="taxModel.vat_rate_reduced" type="number" step="0.1" class="mt-0.5 h-8 w-full px-2 border border-neutral-300 rounded text-sm font-mono" /></label>
            <label class="block"><span class="text-xs text-neutral-500">{{ t('codebooks.tax_f_kh_threshold') }}</span>
              <input v-model.number="taxModel.kh_item_threshold" type="number" class="mt-0.5 h-8 w-full px-2 border border-neutral-300 rounded text-sm font-mono" /></label>
            <label class="block"><span class="text-xs text-neutral-500">{{ t('codebooks.tax_f_vat_low') }}</span>
              <input v-model.number="taxModel.vat_limit_low" type="number" class="mt-0.5 h-8 w-full px-2 border border-neutral-300 rounded text-sm font-mono" /></label>
            <label class="block"><span class="text-xs text-neutral-500">{{ t('codebooks.tax_f_vat_high') }}</span>
              <input v-model.number="taxModel.vat_limit_high" type="number" class="mt-0.5 h-8 w-full px-2 border border-neutral-300 rounded text-sm font-mono" /></label>
          </div>
        </div>

        <!-- Paušální daň -->
        <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm">
          <h3 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('codebooks.tax_g_pausal') }}</h3>
          <p class="text-[11px] text-neutral-400 mb-3">{{ t('codebooks.tax_pausal_hint') }}</p>

          <div v-for="(seg, i) in taxModel.pausal_monthly" :key="seg.from" class="grid grid-cols-[1fr_repeat(3,minmax(0,1fr))_auto] gap-2 items-end mb-2">
            <label class="block">
              <span class="text-xs text-neutral-500">{{ t('codebooks.tax_f_pausal_from') }}</span>
              <select v-if="i > 0" v-model="seg.from" @change="sortPausalSegments"
                class="mt-0.5 h-8 w-full px-2 border border-neutral-300 rounded text-sm bg-surface">
                <option v-for="o in pausalMonthOptions" :key="o.value" :value="o.value" :disabled="pausalMonthTaken(o.value, i)">{{ o.label }}</option>
              </select>
              <div v-else class="mt-0.5 h-8 flex items-center px-2 text-sm text-neutral-500">{{ pausalMonthOptions[0]?.label }}</div>
            </label>
            <label v-for="(b, bi) in taxBands" :key="b" class="block">
              <span class="text-xs text-neutral-500">{{ t('codebooks.tax_f_band', { n: bi + 1 }) }}</span>
              <input v-model.number="seg[b]" type="number" min="0" class="mt-0.5 h-8 w-full px-2 border border-neutral-300 rounded text-sm font-mono" />
            </label>
            <button v-if="i > 0" type="button" @click="removePausalSegment(i)"
              class="h-8 px-2 text-danger-600 hover:bg-danger-50 rounded text-sm" :title="t('common.delete')">✕</button>
            <span v-else class="h-8 w-8"></span>
          </div>

          <div class="flex items-center justify-between gap-3 mt-3 pt-3 border-t border-neutral-100">
            <button type="button" @click="addPausalSegment"
              class="text-xs text-primary-600 hover:text-primary-700 font-medium">+ {{ t('codebooks.tax_pausal_add_period') }}</button>
            <div class="text-xs text-neutral-500">
              {{ t('codebooks.tax_pausal_annual_derived') }}
              <span class="font-mono text-neutral-700 ml-1">{{ taxBands.map(b => pausalAnnualPreview[b].toLocaleString('cs-CZ')).join(' · ') }}</span>
            </div>
          </div>
        </div>

        <!-- Daň z příjmu -->
        <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm">
          <h3 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('codebooks.tax_g_income') }}</h3>
          <div class="grid grid-cols-3 gap-3">
            <label class="block"><span class="text-xs text-neutral-500">{{ t('codebooks.tax_f_rate_low') }}</span>
              <input v-model.number="taxModel.tax_rate_low" type="number" step="0.001" class="mt-0.5 h-8 w-full px-2 border border-neutral-300 rounded text-sm font-mono" /></label>
            <label class="block"><span class="text-xs text-neutral-500">{{ t('codebooks.tax_f_rate_high') }}</span>
              <input v-model.number="taxModel.tax_rate_high" type="number" step="0.001" class="mt-0.5 h-8 w-full px-2 border border-neutral-300 rounded text-sm font-mono" /></label>
            <label class="block"><span class="text-xs text-neutral-500">{{ t('codebooks.tax_f_threshold') }}</span>
              <input v-model.number="taxModel.tax_high_threshold" type="number" class="mt-0.5 h-8 w-full px-2 border border-neutral-300 rounded text-sm font-mono" /></label>
          </div>
        </div>

        <!-- Pojistné -->
        <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm">
          <h3 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('codebooks.tax_g_insurance') }}</h3>
          <div class="grid grid-cols-2 gap-3">
            <label class="block"><span class="text-xs text-neutral-500">{{ t('codebooks.tax_f_social_rate') }}</span>
              <input v-model.number="taxModel.social_rate" type="number" step="0.001" class="mt-0.5 h-8 w-full px-2 border border-neutral-300 rounded text-sm font-mono" /></label>
            <label class="block"><span class="text-xs text-neutral-500">{{ t('codebooks.tax_f_health_rate') }}</span>
              <input v-model.number="taxModel.health_rate" type="number" step="0.001" class="mt-0.5 h-8 w-full px-2 border border-neutral-300 rounded text-sm font-mono" /></label>
            <label class="block"><span class="text-xs text-neutral-500">{{ t('codebooks.tax_f_social_pct') }}</span>
              <input v-model.number="taxModel.social_assessment_pct" type="number" step="0.01" class="mt-0.5 h-8 w-full px-2 border border-neutral-300 rounded text-sm font-mono" /></label>
            <label class="block"><span class="text-xs text-neutral-500">{{ t('codebooks.tax_f_health_pct') }}</span>
              <input v-model.number="taxModel.health_assessment_pct" type="number" step="0.01" class="mt-0.5 h-8 w-full px-2 border border-neutral-300 rounded text-sm font-mono" /></label>
            <label class="block"><span class="text-xs text-neutral-500">{{ t('codebooks.tax_f_social_min_main') }}</span>
              <input v-model.number="taxModel.social_min_base_main" type="number" class="mt-0.5 h-8 w-full px-2 border border-neutral-300 rounded text-sm font-mono" /></label>
            <label class="block"><span class="text-xs text-neutral-500">{{ t('codebooks.tax_f_social_min_sec') }}</span>
              <input v-model.number="taxModel.social_min_base_secondary" type="number" class="mt-0.5 h-8 w-full px-2 border border-neutral-300 rounded text-sm font-mono" /></label>
            <label class="block"><span class="text-xs text-neutral-500">{{ t('codebooks.tax_f_social_sec_threshold') }}</span>
              <input v-model.number="taxModel.social_secondary_participation_threshold" type="number" class="mt-0.5 h-8 w-full px-2 border border-neutral-300 rounded text-sm font-mono" /></label>
            <label class="block"><span class="text-xs text-neutral-500">{{ t('codebooks.tax_f_health_min') }}</span>
              <input v-model.number="taxModel.health_min_base" type="number" class="mt-0.5 h-8 w-full px-2 border border-neutral-300 rounded text-sm font-mono" /></label>
          </div>
        </div>

        <!-- Slevy a zvýhodnění -->
        <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm">
          <h3 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('codebooks.tax_g_credits') }}</h3>
          <div class="grid grid-cols-2 gap-3">
            <label class="block"><span class="text-xs text-neutral-500">{{ t('codebooks.tax_f_credit_taxpayer') }}</span>
              <input v-model.number="taxModel.credit_taxpayer" type="number" class="mt-0.5 h-8 w-full px-2 border border-neutral-300 rounded text-sm font-mono" /></label>
            <label class="block"><span class="text-xs text-neutral-500">{{ t('codebooks.tax_f_credit_spouse') }}</span>
              <input v-model.number="taxModel.credit_spouse" type="number" class="mt-0.5 h-8 w-full px-2 border border-neutral-300 rounded text-sm font-mono" /></label>
            <label v-for="i in 3" :key="i" class="block"><span class="text-xs text-neutral-500">{{ t('codebooks.tax_f_child', { n: i }) }}</span>
              <input v-model.number="taxModel.child_credits[i - 1]" type="number" class="mt-0.5 h-8 w-full px-2 border border-neutral-300 rounded text-sm font-mono" /></label>
          </div>
        </div>

        <!-- Výdajové paušály -->
        <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm">
          <h3 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('codebooks.tax_g_expense_caps') }}</h3>
          <div class="grid grid-cols-4 gap-3">
            <label v-for="r in taxRates" :key="r" class="block"><span class="text-xs text-neutral-500">{{ t('codebooks.tax_f_cap', { rate: r }) }}</span>
              <input v-model.number="taxModel.expense_caps[r]" type="number" class="mt-0.5 h-8 w-full px-2 border border-neutral-300 rounded text-sm font-mono" /></label>
          </div>
        </div>

        <!-- Odpočty (DPH konstanty jsou ve zvýrazněném boxu nahoře) -->
        <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm">
          <h3 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('codebooks.tax_g_deductions_vat') }}</h3>
          <div class="grid grid-cols-2 gap-3">
            <label class="block"><span class="text-xs text-neutral-500">{{ t('codebooks.tax_f_mortgage_cap') }}</span>
              <input v-model.number="taxModel.mortgage_cap" type="number" class="mt-0.5 h-8 w-full px-2 border border-neutral-300 rounded text-sm font-mono" /></label>
            <label class="block"><span class="text-xs text-neutral-500">{{ t('codebooks.tax_f_pension_cap') }}</span>
              <input v-model.number="taxModel.pension_cap" type="number" class="mt-0.5 h-8 w-full px-2 border border-neutral-300 rounded text-sm font-mono" /></label>
          </div>
        </div>

        <!-- Stropy pásem paušální daně (rate × band) -->
        <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm lg:col-span-2">
          <h3 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('codebooks.tax_g_ceilings') }}</h3>
          <table class="text-sm">
            <thead>
              <tr class="text-xs text-neutral-500">
                <th class="text-left font-medium pr-3 pb-1">{{ t('codebooks.tax_f_rate') }}</th>
                <th v-for="(b, i) in taxBands" :key="b" class="text-left font-medium px-2 pb-1">{{ t('codebooks.tax_f_band', { n: i + 1 }) }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in taxRates" :key="r">
                <td class="pr-3 py-1 text-neutral-600 font-mono">{{ r }} %</td>
                <td v-for="b in taxBands" :key="b" class="px-2 py-1">
                  <input v-model.number="taxModel.band_ceilings[r][b]" type="number" class="h-8 w-32 px-2 border border-neutral-300 rounded text-sm font-mono" />
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <!-- ====== Modals ====== -->

    <!-- VAT classification modal -->
    <div v-if="vatClsOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-xl w-full p-5">
        <h3 class="text-lg font-semibold mb-3">
          {{ vatClsEditMode === 'edit' ? t('vat_classifications.edit_title') : t('vat_classifications.new_title') }}
        </h3>
        <div class="space-y-3">
          <div class="grid grid-cols-3 gap-3">
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('vat_classifications.code') }} *</label>
              <input v-model="vatClsDraft.code" type="text" maxlength="8"
                :disabled="vatClsEditMode === 'edit'"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono disabled:bg-neutral-100" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('vat_classifications.direction') }}</label>
              <select v-model="vatClsDraft.direction" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
                <option value="sale">{{ t('vat_classifications.direction_sale') }}</option>
                <option value="purchase">{{ t('vat_classifications.direction_purchase') }}</option>
                <option value="both">{{ t('vat_classifications.direction_both') }}</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('vat_classifications.vat_rate') }}</label>
              <input v-model.number="vatClsDraft.vat_rate" type="number" step="0.1" placeholder="21"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('vat_classifications.label') }} *</label>
            <input v-model="vatClsDraft.label" type="text" maxlength="150"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div class="grid grid-cols-3 gap-3">
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('vat_classifications.dphdp3_line') }}</label>
              <input v-model="vatClsDraft.dphdp3_line" type="text" maxlength="10" placeholder="1"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('vat_classifications.kh_section') }}</label>
              <input v-model="vatClsDraft.kh_section" type="text" maxlength="8" placeholder="A.4"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('vat_classifications.display_order') }}</label>
              <input v-model.number="vatClsDraft.display_order" type="number" step="1"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
          </div>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="vatClsDraft.is_reverse_charge" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('vat_classifications.is_reverse_charge') }}
          </label>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('vat_classifications.kh_regime_code') }}</label>
              <select v-model="vatClsDraft.kh_regime_code" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
                <option value="">{{ t('vat_classifications.kh_default') }}</option>
                <option value="0">0 — {{ t('vat_classifications.kh_regime_standard') }}</option>
                <option value="1">1 — {{ t('vat_classifications.kh_regime_travel') }}</option>
                <option value="2">2 — {{ t('vat_classifications.kh_regime_used_goods') }}</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('vat_classifications.kh_bad_debt') }}</label>
              <select v-model="vatClsDraft.kh_bad_debt" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
                <option value="">{{ t('vat_classifications.kh_default') }}</option>
                <option value="N">N — {{ t('vat_classifications.kh_bad_debt_no') }}</option>
                <option value="P">P — {{ t('vat_classifications.kh_bad_debt_yes') }}</option>
              </select>
            </div>
          </div>
          <label v-if="vatClsEditMode === 'edit'" class="flex items-center gap-2 text-sm">
            <input v-model="vatClsDraft.archived" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('vat_classifications.archive') }}
          </label>
        </div>
        <div class="flex justify-end gap-2 pt-4 mt-3 border-t border-neutral-200">
          <button @click="vatClsOpen = false" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">{{ t('common.cancel') }}</button>
          <button @click="saveVatCls" class="cursor-pointer px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md">{{ t('common.save') }}</button>
        </div>
      </div>
    </div>

    <!-- Expense category modal -->
    <div v-if="expenseOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-3">
          {{ expenseDraft.id ? t('expense_categories.edit_title') : t('expense_categories.new_title') }}
        </h3>
        <div class="space-y-3">
          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('expense_categories.code') }} *</label>
            <input v-model="expenseDraft.code" type="text" maxlength="20" placeholder="hosting"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            <p class="text-xs text-neutral-500 mt-1">{{ t('expense_categories.code_hint') }}</p>
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('expense_categories.label') }} *</label>
            <input v-model="expenseDraft.label" type="text" maxlength="100" placeholder="Hosting a domény"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('expense_categories.fixed_or_var') }}</label>
            <select v-model="expenseDraft.fixed_or_var" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
              <option value="variable">{{ t('expense_categories.variable') }}</option>
              <option value="fixed">{{ t('expense_categories.fixed') }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('expense_categories.display_order') }}</label>
            <input v-model.number="expenseDraft.display_order" type="number" step="1"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <label v-if="expenseDraft.id" class="flex items-center gap-2 text-sm">
            <input v-model="expenseDraft.archived" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('expense_categories.archive') }}
          </label>
        </div>
        <div class="flex justify-end gap-2 pt-4 mt-3 border-t border-neutral-200">
          <button @click="expenseOpen = false" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">{{ t('common.cancel') }}</button>
          <button @click="saveExpense" class="cursor-pointer px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md">{{ t('common.save') }}</button>
        </div>
      </div>
    </div>

    <!-- Revenue category modal -->
    <div v-if="revenueOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-3">
          {{ revenueDraft.id ? t('revenue_categories.edit_title') : t('revenue_categories.new_title') }}
        </h3>
        <div class="space-y-3">
          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('revenue_categories.code') }} *</label>
            <input v-model="revenueDraft.code" type="text" maxlength="20" placeholder="konzultace"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            <p class="text-xs text-neutral-500 mt-1">{{ t('revenue_categories.code_hint') }}</p>
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('revenue_categories.label') }} *</label>
            <input v-model="revenueDraft.label" type="text" maxlength="100" placeholder="Konzultace a poradenství"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('revenue_categories.display_order') }}</label>
            <input v-model.number="revenueDraft.display_order" type="number" step="1"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <label v-if="revenueDraft.id" class="flex items-center gap-2 text-sm">
            <input v-model="revenueDraft.archived" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('revenue_categories.archive') }}
          </label>
        </div>
        <div class="flex justify-end gap-2 pt-4 mt-3 border-t border-neutral-200">
          <button @click="revenueOpen = false" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">{{ t('common.cancel') }}</button>
          <button @click="saveRevenue" class="cursor-pointer px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md">{{ t('common.save') }}</button>
        </div>
      </div>
    </div>

    <!-- Supplier create modal (multi-tenant firma) -->
    <div v-if="supplierCreateOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-xl w-full p-5">
        <h3 class="text-lg font-semibold mb-1">{{ t('supplier.create_title') }}</h3>
        <p class="text-xs text-neutral-500 mb-4">{{ t('supplier.create_hint') }}</p>
        <form @submit.prevent="saveSupplier">
          <div class="space-y-3">
            <div class="bg-primary-50/50 border border-primary-200 rounded-md p-3">
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('supplier.ares_lookup') }}</label>
              <div class="flex gap-2">
                <input v-model="supplierDraft.ic" type="text" placeholder="12345678" maxlength="8"
                  @keydown.enter.prevent="supplierLookupAres"
                  class="flex-1 h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
                <button type="button" @click="supplierLookupAres" :disabled="supplierAresLoading"
                  class="cursor-pointer h-10 px-4 text-sm bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md inline-flex items-center gap-1.5">
                  <svg v-if="!supplierAresLoading" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 1 1-14 0 7 7 0 0 1 14 0z"/></svg>
                  <span v-else>…</span>
                  {{ supplierAresLoading ? t('common.loading') : t('supplier.ares_load') }}
                </button>
              </div>
              <div v-if="supplierAresMessage" class="mt-2 text-xs px-2 py-1 rounded"
                :class="supplierAresMessage.type === 'success' ? 'bg-success-50 text-success-600' : 'bg-danger-50 text-danger-500'">
                {{ supplierAresMessage.text }}
              </div>
            </div>

            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('supplier.company_name') }} *</label>
              <input v-model="supplierDraft.company_name" type="text" required class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('supplier.dic') }}</label>
              <input v-model="supplierDraft.dic" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('supplier.street') }} *</label>
              <input v-model="supplierDraft.street" type="text" required class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div class="grid grid-cols-3 gap-3">
              <div>
                <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('supplier.zip') }} *</label>
                <input v-model="supplierDraft.zip" type="text" required class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
              </div>
              <div class="col-span-2">
                <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('supplier.city') }} *</label>
                <input v-model="supplierDraft.city" type="text" required class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
              </div>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('supplier.email') }} *</label>
              <input v-model="supplierDraft.email" type="email" required class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.commercial_register') }}</label>
              <input v-model="supplierDraft.commercial_register" type="text" :placeholder="t('settings.commercial_register_placeholder')" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>

            <div class="border-t border-neutral-200 pt-3">
              <div class="flex items-center justify-between mb-2 gap-2">
                <label class="block text-xs font-medium text-neutral-700">{{ t('settings.account_cz') }} / {{ t('settings.iban') }} <span class="text-neutral-400">{{ t('common.optional') }}</span></label>
                <button type="button" @click="supplierLookupBank" :disabled="supplierBankLoading"
                  class="cursor-pointer h-8 px-3 text-xs bg-surface border border-primary-300 text-primary-700 rounded-md hover:bg-primary-50 disabled:opacity-50 shrink-0">
                  {{ supplierBankLoading ? t('common.loading') : t('supplier.bank_lookup') }}
                </button>
              </div>
              <div v-if="supplierBankMessage" class="mb-2 text-xs px-2 py-1 rounded"
                :class="{
                  'bg-success-50 text-success-600': supplierBankMessage.type === 'success',
                  'bg-danger-50 text-danger-500': supplierBankMessage.type === 'error',
                  'bg-warning-50 text-warning-600': supplierBankMessage.type === 'warning',
                }">
                {{ supplierBankMessage.text }}
              </div>
              <div v-if="supplierBankAccounts.length > 1" class="mb-2 flex flex-wrap gap-1.5">
                <button v-for="(acc, i) in supplierBankAccounts" :key="i" type="button" @click="supplierApplyBank(acc)"
                  class="cursor-pointer px-2 py-1 text-xs font-mono border border-neutral-300 rounded hover:bg-primary-50 hover:border-primary-300">
                  {{ acc.display }}
                </button>
              </div>
              <div class="grid grid-cols-3 gap-3">
                <div>
                  <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('common.currency') }}</label>
                  <select v-model="supplierBank.currency" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
                    <option value="CZK">CZK</option>
                    <option value="EUR">EUR</option>
                  </select>
                </div>
                <template v-if="supplierBank.currency === 'CZK'">
                  <div>
                    <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.currency_account_cz') }}</label>
                    <input v-model="supplierBank.account_number" placeholder="1000000005" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
                  </div>
                  <div>
                    <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.currency_bank_code') }}</label>
                    <input v-model="supplierBank.bank_code" maxlength="4" placeholder="0100" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
                  </div>
                </template>
                <template v-else>
                  <div class="col-span-2">
                    <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.iban') }}</label>
                    <input v-model="supplierBank.iban" placeholder="CZ65 0800 0000 1920 0014 5399" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
                  </div>
                </template>
              </div>
            </div>
          </div>
          <div class="flex justify-end gap-2 pt-4 mt-3 border-t border-neutral-200">
            <button type="button" @click="supplierCreateOpen = false" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">{{ t('common.cancel') }}</button>
            <button type="submit" class="cursor-pointer px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md">{{ t('common.create') }}</button>
          </div>
        </form>
      </div>
    </div>

    <div v-if="vatOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-3">{{ vatDraft._new ? t('codebooks.new_vat') : vatDraft.code }}</h3>
        <div class="space-y-3">
          <div class="grid grid-cols-3 gap-3">
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.country') }}</label>
              <input v-model="vatDraft.country" type="text" maxlength="2" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono uppercase" /></div>
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.code') }} *</label>
              <input v-model="vatDraft.code" type="text" placeholder="STD" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" /></div>
            <div><label class="block text-sm font-medium mb-1">% *</label>
              <input v-model.number="vatDraft.rate_percent" type="number" step="0.01" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" /></div>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.name_cs') }}</label>
              <input v-model="vatDraft.label_cs" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" /></div>
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.name_en') }}</label>
              <input v-model="vatDraft.label_en" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" /></div>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.valid_from') }}</label>
              <input v-model="vatDraft.valid_from" type="date" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" /></div>
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.valid_to') }}</label>
              <input v-model="vatDraft.valid_to" type="date" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" /></div>
          </div>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="vatDraft.is_default" type="checkbox" class="rounded border-neutral-300 text-primary-600" /> {{ t('codebooks.is_default_for_country') }}
          </label>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="vatDraft.is_reverse_charge" type="checkbox" class="rounded border-neutral-300 text-primary-600" /> {{ t('codebooks.is_reverse_charge_label') }}
          </label>
          <div class="flex justify-end gap-2 pt-2">
            <button @click="vatOpen = false" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">{{ t('common.cancel') }}</button>
            <button @click="saveVat" class="cursor-pointer px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md">{{ t('common.save') }}</button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="unitOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-3">{{ unitDraft._new ? t('codebooks.new_unit') : unitDraft.code }}</h3>
        <div class="space-y-3">
          <div>
            <label class="block text-sm font-medium mb-1">{{ t('codebooks.code') }} *</label>
            <input v-model="unitDraft.code" :disabled="!unitDraft._new" type="text" maxlength="20" placeholder="ks"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono disabled:bg-neutral-50" />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.name_cs') }}</label>
              <input v-model="unitDraft.label_cs" type="text" placeholder="kus" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" /></div>
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.name_en') }}</label>
              <input v-model="unitDraft.label_en" type="text" placeholder="piece" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" /></div>
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">{{ t('codebooks.display_order') }}</label>
            <input v-model.number="unitDraft.display_order" type="number" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
          </div>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="unitDraft.is_default" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('codebooks.is_default_unit_hint') }}
          </label>
          <div class="flex justify-end gap-2 pt-2">
            <button @click="unitOpen = false" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">{{ t('common.cancel') }}</button>
            <button @click="saveUnit" class="cursor-pointer px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md">{{ t('common.save') }}</button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="countryOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-3">{{ countryDraft._new ? t('codebooks.new_country') : countryDraft.iso2 }}</h3>
        <div class="space-y-3">
          <div class="grid grid-cols-2 gap-3">
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.iso2') }} *</label>
              <input v-model="countryDraft.iso2" :disabled="!countryDraft._new" type="text" maxlength="2" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono uppercase disabled:bg-neutral-50" /></div>
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.iso3') }}</label>
              <input v-model="countryDraft.iso3" type="text" maxlength="3" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono uppercase" /></div>
          </div>
          <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.name_cs') }}</label>
            <input v-model="countryDraft.name_cs" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" /></div>
          <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.name_en') }}</label>
            <input v-model="countryDraft.name_en" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" /></div>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="countryDraft.is_eu" type="checkbox" class="rounded border-neutral-300 text-primary-600" /> {{ t('codebooks.is_eu_label') }}
          </label>
          <div class="flex justify-end gap-2 pt-2">
            <button @click="countryOpen = false" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">{{ t('common.cancel') }}</button>
            <button @click="saveCountry" class="cursor-pointer px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md">{{ t('common.save') }}</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
