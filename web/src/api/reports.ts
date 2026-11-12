import { api } from './client'

export interface DphPriznaniLine {
  base: number
  vat: number
  count: number
  label: string
}

export interface DphSupplementaryInfo {
  d_zjist: string
  reason: string
  based_on: { submission_id: number; submission_form: string; filed_at: string | null }
  known_tax: number
  new_tax: number
  difference: number
}

/** Params of the supplementary (D) return; `d_zjist` is in the EPO format DD.MM.YYYY. */
export interface DphFormParams {
  form?: string
  dZjist?: string
  reason?: string
}

export interface DphPriznaniPreview {
  summary: {
    period: string
    period_type: 'monthly' | 'quarterly'
    quarter: number | null
    lines: Record<string, DphPriznaniLine>
    total_vat_output: number
    total_vat_input: number
    tax_due: number
    is_excess_deduction: boolean
    submission_deadline: string
    supplier_vat_period: string
    /** Form of the previewed return (B regular, O corrective, D supplementary). */
    form?: 'B' | 'O' | 'D'
    /** Supplementary only: signed row 66 (positive = extra tax, negative = reduction). */
    line_66_dano?: number
    supplementary?: DphSupplementaryInfo
  }
  warnings: string[]
  /** Missing MANDATORY EPO identification fields — they do not block the preview, they do block the XML download. */
  missing?: EpoMissingField[]
}

/** Taxpayer identification fields missing in Settings → Tax settings. */
export interface EpoMissingField {
  field: string
  label: string
  why: string
}

export interface DphSettings {
  vat_period: 'monthly' | 'quarterly' | null
  is_vat_payer: boolean
  /** Identified person (§ 6g–6l, issue #94) — return type I, always monthly. */
  is_identified?: boolean
  taxpayer_type: 'fo' | 'po' | null
  has_financial_office: boolean
}

/** Answer to a call of the tax office (vyzva): `vyzva_odp` B = nil KH, P = confirmation of the last filed KH. */
export interface KhFormParams {
  /** B regular / O corrective / N follow-up (B is never sent). */
  form?: string
  /** Date of discovery in the EPO format DD.MM.YYYY. */
  dZjist?: string
  cJedVyzvy?: string
  vyzvaOdp?: 'B' | 'P'
}

export interface KhPreview {
  summary: {
    period: string
    /** The form in the XML (a quick answer to a call is a nil KH, B). */
    form: string
    /** How the statement is archived ('V' for a quick answer to a call). */
    archive_form?: string
    /** Answer to a call of the tax office; `answer` is null for a full follow-up KH, the whole object null without a call. */
    call: { reference: string; answer: 'B' | 'P' | null } | null
    a1_count: number
    a2_count: number
    a4_count: number
    a5_count_aggregated: number
    b1_count: number
    b2_count: number
    b3_count_aggregated: number
    submission_deadline: string
  }
  warnings: string[]
  /** Missing MANDATORY EPO identification fields — they do not block the preview, they do block the XML download. */
  missing?: EpoMissingField[]
}

export interface DphTrendRow {
  period: string
  vat_output: number
  vat_input: number
  vat_due: number
}

export interface DphDraftsPrediction {
  year: number
  month: number
  period: 'monthly' | 'quarterly'
  vat_output: number
  vat_input: number
  tax_due: number
  sale_count: number
  sale_draft_count: number
  purchase_count: number
  purchase_draft_count: number
}

export interface DphBookRow {
  invoice_id: number
  direction: 'issued' | 'received'
  doc_number: string | null
  original_doc_number: string | null
  tax_date: string | null
  accounting_date: string | null
  description: string
  counterparty_name: string
  counterparty_dic: string
  vat_classification_code: string | null
  vat_rate: number
  currency: string
  exchange_rate: number
  base: number
  vat: number
  total: number
  status: string
  is_draft: boolean
  kh_section: string | null
}

export interface DphBookSection {
  key: string
  direction: 'USKUTEČNĚNÁ' | 'PŘIJATÁ'
  label: string
  dphdp3_line: string
  vat_rate: number
  is_secondary: boolean
  rows: DphBookRow[]
  subtotal_base: number
  subtotal_vat: number
  subtotal_total: number
}

export interface DphBookPreview {
  period: {
    year: number
    month: number
    period_type: 'monthly' | 'quarterly'
    quarter: number | null
    start: string
    end: string
    label: string
  }
  supplier: Record<string, unknown>
  sections: DphBookSection[]
  totals: {
    issued: { base: number; vat: number; total: number }
    received: { base: number; vat: number; total: number }
    vat_balance: number
  }
}

export interface OssPreview {
  period: {
    year: number
    quarter: number
    start: string
    end: string
    label: string
    submission_deadline: string
  }
  settings: {
    oss_enabled: boolean
    oss_valid_from: string | null
    oss_valid_to: string | null
    oss_identification_country: string | null
    oss_return_currency: string
  }
  summary: {
    return_currency: string
    total_base: number
    total_vat: number
    total_corrections: number
    total_payable: number
    invoice_count: number
    row_count: number
    correction_row_count: number
    invalid_correction_count: number
    conversion_missing_count: number
  }
  countries: Array<{
    country: string
    base: number
    vat: number
    rates: Array<{
      rate: number
      rate_type: string | null
      base: number
      vat: number
      count: number
    }>
    rows: Array<{
      invoice_id: number
      item_id: number
      doc_number: string | null
      invoice_type: string
      tax_date: string | null
      client_name: string
      description: string
      currency: string
      base: number
      vat: number
      base_return: number
      vat_return: number
      vat_rate: number
      rate_type: string | null
      supply_type: string | null
    }>
  }>
  corrections: Array<{
    period: string
    year: number
    quarter: number
    state_consumption: string
    correction: number
    count: number
    rows: Array<{
      invoice_id: number
      item_id: number
      doc_number: string | null
      invoice_type: string
      tax_date: string | null
      client_name: string
      description: string
      currency: string
      base_return: number
      vat_return: number
      original_period: string
    }>
  }>
  warnings: string[]
}

export type MonthlyExportPart =
  | 'sales_pdf' | 'sales_isdoc' | 'purchase_pdf' | 'purchase_isdoc'
  | 'bank_pdf' | 'bank_gpc' | 'dph_book' | 'summary_csv'

/** Period of the bulk export — a month, a quarter or a whole year. */
export type ExportPeriodArg =
  | { type: 'monthly'; year: number; month: number }
  | { type: 'quarterly'; year: number; quarter: number }
  | { type: 'yearly'; year: number }

/** How documents are assigned to the period: DUZP (VAT) or payment date (income tax). */
export type ExportBasis = 'duzp' | 'payment'

export interface MonthlyExportPreview {
  period: string
  period_type: 'monthly' | 'quarterly' | 'yearly'
  basis: ExportBasis
  counts: Record<MonthlyExportPart, number>
  /** Documents with a DUZP in the period that are not paid in it (payment basis only). */
  outside: { sales: number; purchase: number }
}

export interface MonthlyExportJob {
  id: number
  status: 'queued' | 'running' | 'completed' | 'failed' | 'cancelled'
  total_items: number | null
  processed: number
  created_count: number
  failed_count: number
  current_step: string | null
  log_text?: string | null
  last_error: string | null
  cancel_requested: boolean
  result_name: string | null
  result_size: number | null
  params: Record<string, unknown> | null
  created_at: string
  finished_at: string | null
}

/** Query/body period parameters of the bulk export (period + year + month|quarter) and the assignment basis. */
function monthlyExportPeriodParams(period: ExportPeriodArg, basis: ExportBasis): Record<string, string | number> {
  switch (period.type) {
    case 'quarterly': return { period: 'quarterly', year: period.year, quarter: period.quarter, basis }
    case 'yearly': return { period: 'yearly', year: period.year, basis }
    default: return { period: 'monthly', year: period.year, month: period.month, basis }
  }
}

/** Query params of the return form: B is the default and is never sent. */
function dphFormQuery({ form, dZjist, reason }: DphFormParams): Record<string, string> {
  if (!form || form === 'B') return {}
  return {
    form,
    ...(dZjist ? { d_zjist: dZjist } : {}),
    ...(form === 'D' && reason ? { reason } : {}),
  }
}

function khFormQuery({ form, dZjist, cJedVyzvy, vyzvaOdp }: KhFormParams): Record<string, string> {
  return {
    ...(form && form !== 'B' ? { form } : {}),
    ...(dZjist ? { d_zjist: dZjist } : {}),
    ...(cJedVyzvy ? { c_jed_vyzvy: cJedVyzvy } : {}),
    ...(vyzvaOdp ? { vyzva_odp: vyzvaOdp } : {}),
  }
}

export const reportsApi = {
  dphSettings: () =>
    api.get<DphSettings>('/reports/dphdp3/settings').then(r => r.data),

  dphPreview: (year: number, month: number, period?: 'monthly' | 'quarterly', formParams: DphFormParams = {}) =>
    api.get<DphPriznaniPreview>('/reports/dphdp3/preview', {
      params: { year, month, ...(period ? { period } : {}), ...dphFormQuery(formParams) },
    }).then(r => r.data),

  dphTrend: (months = 12) =>
    api.get<DphTrendRow[]>('/reports/dphdp3/trend', { params: { months } }).then(r => r.data),

  dphDraftsPrediction: (year: number, month: number, period?: 'monthly' | 'quarterly') =>
    api.get<DphDraftsPrediction>('/reports/dphdp3/drafts-prediction', {
      params: { year, month, ...(period ? { period } : {}) },
    }).then(r => r.data),

  khPreview: (year: number, month: number, period?: 'monthly' | 'quarterly', formParams: KhFormParams = {}) =>
    api.get<KhPreview>('/reports/dphkh1/preview', {
      params: { year, month, ...(period ? { period } : {}), ...khFormQuery(formParams) },
    }).then(r => r.data),

  // EC Sales List (EU supplies) — payers and identified persons; quarterly is possible for services
  shvPreview: (year: number, month: number, period?: 'monthly' | 'quarterly') =>
    api.get<{
      summary: {
        period: string
        rows_count: number
        total_amount: number
        rows: Array<{
          country_iso2: string
          vat_id: string
          sh_type: '0' | '1' | '2' | '3'
          amount: number
          count: number
          counterparty_name: string
        }>
        submission_deadline: string
      }
      warnings: string[]
      /** Missing MANDATORY EPO identification fields — they do not block the preview, they do block the XML download. */
      missing?: EpoMissingField[]
    }>('/reports/dphshv/preview', { params: { year, month, ...(period ? { period } : {}) } }).then(r => r.data),

  shvDownloadUrl: (year: number, month: number, period?: 'monthly' | 'quarterly') => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams({ year: String(year), month: String(month) })
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    if (period) params.set('period', period)
    return `/api/reports/dphshv?${params.toString()}`
  },

  incomeTaxPreview: (year: number, type: 'fo' | 'po') =>
    api.get<{
      summary: {
        year: number
        taxpayer_type: 'fo' | 'po'
        revenue_orientacni: number
        exempt_revenue_orientacni?: number
        costs_orientacni: number
        profit_orientacni: number
        /** Electronic filing (§ 136(2)(a) DŘ). */
        submission_deadline: string
        /** Paper filing (§ 136 odst. 1 DŘ). */
        paper_deadline: string
        currency: string
        is_vat_payer: boolean
      }
      warnings: string[]
    }>('/reports/income-tax/preview', { params: { year, type } }).then(r => r.data),

  incomeTaxDownloadUrl: (year: number, type: 'fo' | 'po') => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams({ year: String(year), type })
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    return `/api/reports/income-tax?${params.toString()}`
  },

  khDownloadUrl: (year: number, month: number, period?: 'monthly' | 'quarterly', formParams: KhFormParams = {}) => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams({ year: String(year), month: String(month) })
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    if (period) params.set('period', period)
    // Filing form (B regular / O corrective / N subsequent) + date the reasons were found
    // for a subsequent KH (DD.MM.YYYY, mandatory for N) — see KontrolniHlaseniAction.
    for (const [k, v] of Object.entries(khFormQuery(formParams))) params.set(k, v)
    return `/api/reports/dphkh1?${params.toString()}`
  },

  // VAT book (internal VAT journal — NOT an EPO submission)
  dphBookPreview: (year: number, month: number, period?: 'monthly' | 'quarterly') =>
    api.get<DphBookPreview>('/reports/dph-book/preview', {
      params: period ? { year, month, period } : { year, month },
    }).then(r => r.data),

  ossPreview: (year: number, quarter: number) =>
    api.get<OssPreview>('/reports/oss/preview', { params: { year, quarter } }).then(r => r.data),

  ossDownloadUrl: (year: number, quarter: number) => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams({ year: String(year), quarter: String(quarter) })
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    return `/api/reports/oss?${params.toString()}`
  },

  dphBookPdfUrl: (year: number, month: number, period?: 'monthly' | 'quarterly') => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams({ year: String(year), month: String(month) })
    if (period) params.set('period', period)
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    return `/api/reports/dph-book?${params.toString()}`
  },

  // Bulk export — background job (counts per part for the UI checkboxes)
  monthlyExportPreview: (period: ExportPeriodArg, basis: ExportBasis) =>
    api.get<MonthlyExportPreview>('/reports/monthly-export/preview', { params: monthlyExportPeriodParams(period, basis) })
      .then(r => r.data),

  /** Starts the export job in the background → returns job_id. */
  monthlyExportStart: (period: ExportPeriodArg, basis: ExportBasis, parts: string[]) =>
    api.post<{ job_id: number; status: string; params: Record<string, unknown> }>(
      '/reports/monthly-export/start', { ...monthlyExportPeriodParams(period, basis), parts },
    ).then(r => r.data),

  /** Job status (polling). */
  monthlyExportJob: (id: number) =>
    api.get<MonthlyExportJob>(`/reports/monthly-export/jobs/${id}`).then(r => r.data),

  /** Recent exports (history — they stay downloadable until deleted / cleaned up). */
  monthlyExportJobs: (signal?: AbortSignal) =>
    api.get<MonthlyExportJob[]>('/reports/monthly-export/jobs', { signal }).then(r => r.data),

  monthlyExportCancel: (id: number) =>
    api.post<{ ok: boolean; cancel_requested: boolean }>(`/reports/monthly-export/jobs/${id}/cancel`).then(r => r.data),

  monthlyExportDeleteJob: (id: number) =>
    api.delete<{ ok: boolean; deleted: boolean }>(`/reports/monthly-export/jobs/${id}`).then(r => r.data),

  /** URL of the finished ZIP — opened by direct navigation (cookie auth + supplier_id query). */
  monthlyExportDownloadUrl: (id: number) => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams()
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    const qs = params.toString()
    return `/api/reports/monthly-export/jobs/${id}/download${qs ? `?${qs}` : ''}`
  },

  /** URL of the download endpoint — the frontend opens it in a new window */
  dphDownloadUrl: (year: number, month: number, period?: 'monthly' | 'quarterly', formParams: DphFormParams = {}) => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams({ year: String(year), month: String(month) })
    if (period) params.set('period', period)
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    // Form of the return (B regular / O corrective / D supplementary) — see DphPriznaniAction.
    for (const [k, v] of Object.entries(dphFormQuery(formParams))) params.set(k, v)
    return `/api/reports/dphdp3?${params.toString()}`
  },
}
