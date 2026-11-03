import { api } from './client'
import type { VatForm } from '@/utils/taxFiling'

export type FilingStatus = 'generated' | 'filed' | 'confirmed'
export type FilingChannel = 'epo' | 'data_box' | 'other'

/** One account of the tax office's VAT payment (prefix-number/bank code + its IBAN). */
export interface VatPaymentAccount {
  office_code: string
  office_name: string
  prefix: string
  number: string
  bank_code: string
  display: string
  iban: string
}

/** The "Platba DPH" card of a DPH return. */
export interface VatPayment {
  submission_id: number
  kind: 'payment' | 'refund' | 'none'
  /** Own tax liability (row 64) or the excess deduction (row 66), whole CZK. */
  amount: number
  /** What is still to pay (after payments towards superseded returns of the same period). */
  amount_due: number
  period_label: string
  due_date: string
  state: 'none' | 'not_filed' | 'unpaid' | 'paid' | 'superseded' | 'refund'
  payable: boolean
  account: VatPaymentAccount | null
  office: { code: string; name: string } | null
  variable_symbol: string | null
  /** PNG data URI of the SPD QR payment. */
  qr: string | null
  paid: { at: string; amount: number; source: 'manual' | 'bank'; bank_transaction_id: number | null } | null
  warnings: Array<{ code: string; message: string }>
}

export interface TaxSubmission {
  id: number
  form_code: string
  submission_form: string
  period_year: number
  period_month: number | null
  period_quarter: number | null
  xml_size_bytes: number
  xml_sha256: string
  validation_status: 'passed' | 'failed' | 'skipped'
  validation_errors: string[]
  summary: Record<string, unknown> | null
  generated_at: string
  notes: string | null
  filing_status: FilingStatus
  filed_at: string | null
  filing_channel: FilingChannel | null
  filing_reference: string | null
  confirmed_at: string | null
  has_receipt: boolean
  receipt_name: string | null
  receipt_size_bytes: number | null
  receipt_sha256: string | null
  /** Present on DPH returns that carry a liability or a refund. */
  payment?: VatPayment | null
}

export interface ChangedDocument {
  source: 'sale' | 'purchase'
  id: number
  doc_number: string | null
  kind: string | null
  status: string | null
  tax_date: string | null
  counterparty: string | null
  total_with_vat: number | null
  currency: string | null
  deleted: boolean
  changes: Array<{ type: 'created' | 'issued' | 'edited' | 'cancelled' | 'deleted' | 'booked'; at: string; user_id: number | null }>
}

export interface ChangesAfterFiling {
  filed_at: string | null
  period_start: string
  period_end: string
  count: number
  items: ChangedDocument[]
}

export type CrossCheckStatus = 'ok' | 'warning' | 'error'

export interface CrossCheckRow {
  id: string
  dph: { lines: string[]; base: number; vat: number | null }
  other: { document: 'KH' | 'SH'; section: string; base: number; vat: number | null }
  diff: { base: number; vat: number | null }
  status: CrossCheckStatus
}

export interface CrossCheckDocument {
  source: 'sale' | 'purchase'
  invoice_id: number
  doc_number: string | null
  tax_date: string | null
  counterparty: string | null
  problem: 'missing_in_kh' | 'extra_in_kh' | 'missing_in_sh'
  dph_line: string | null
  kh_section: string | null
  base_czk: number
  checks: string[]
}

export interface CrossCheck {
  applicable: boolean
  reason: string | null
  status: CrossCheckStatus
  tolerance: number
  checks: CrossCheckRow[]
  documents: CrossCheckDocument[]
  documents_truncated: boolean
}

export interface VatChecks {
  cross_check: CrossCheck
  filing: Partial<TaxSubmission> | null
  changed_after_filing: ChangesAfterFiling | null
  payment: VatPayment | null
}

export interface TaxCalendarItem {
  type: 'dph' | 'kh' | 'sh' | 'income_tax' | 'pausal'
  key: string
  year: number
  month: number | null
  quarter: number | null
  period: 'monthly' | 'quarterly' | 'yearly'
  period_label: string
  form_code: string | null
  due_date: string
  days_left: number
  link: string
  filing: { status: 'unfiled' | FilingStatus; submission_id: number | null; filed_at: string | null } | null
  /** Flat-tax advance only. */
  band?: string
  amount?: number
}

function supplierQuery(): string {
  const sid = localStorage.getItem('myinvoice.current_supplier_id')
  return sid && /^\d+$/.test(sid) ? `?supplier_id=${sid}` : ''
}

export const taxFilingApi = {
  list: () => api.get<TaxSubmission[]>('/reports/submissions').then(r => r.data),

  markFiled: (id: number, body: { channel: FilingChannel; filed_at?: string; reference?: string }) =>
    api.post<TaxSubmission>(`/reports/submissions/${id}/file`, body).then(r => r.data),

  unfile: (id: number) =>
    api.post<TaxSubmission>(`/reports/submissions/${id}/unfile`, {}).then(r => r.data),

  uploadReceipt: (id: number, file: File) => {
    const fd = new FormData()
    fd.append('file', file, file.name)
    return api.post<TaxSubmission>(`/reports/submissions/${id}/receipt`, fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data)
  },

  removeReceipt: (id: number) =>
    api.delete<TaxSubmission>(`/reports/submissions/${id}/receipt`).then(r => r.data),

  /** The downloads are plain navigations (cookie auth + supplier_id query). */
  xmlUrl: (id: number) => `/api/reports/submissions/${id}/xml${supplierQuery()}`,
  receiptUrl: (id: number) => `/api/reports/submissions/${id}/receipt${supplierQuery()}`,

  payment: (id: number) =>
    api.get<VatPayment>(`/reports/submissions/${id}/payment`).then(r => r.data),

  markPaid: (id: number, body: { paid_at?: string; amount?: number } = {}) =>
    api.post<VatPayment>(`/reports/submissions/${id}/payment/paid`, body).then(r => r.data),

  clearPaid: (id: number) =>
    api.delete<VatPayment>(`/reports/submissions/${id}/payment/paid`).then(r => r.data),

  changes: (id: number) =>
    api.get<ChangesAfterFiling>(`/reports/submissions/${id}/changes`).then(r => r.data),

  vatChecks: (form: VatForm, year: number, month: number, period: 'monthly' | 'quarterly') =>
    api.get<VatChecks>('/reports/vat-checks', { params: { form, year, month, period } }).then(r => r.data),

  taxCalendar: (days = 60) =>
    api.get<{ today: string; items: TaxCalendarItem[] }>('/dashboard/tax-calendar', { params: { days } }).then(r => r.data),
}
