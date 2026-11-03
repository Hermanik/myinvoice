import { api } from './client'

export type RegistrationDeductionKind = 'registration' | 'deregistration'
export type RegistrationAssetType = 'inventory' | 'long_term_asset' | 'other'

export interface RegistrationDeductionItem {
  id: number
  kind: RegistrationDeductionKind
  event_date: string
  description: string
  asset_type: RegistrationAssetType
  acquired_on: string
  tax_base: number
  vat_amount: number
  adjustment_years: 5 | 10 | null
  in_business_assets: boolean
  /** Computed by the server (>= 0); for a deregistration item it is the REDUCTION. */
  deduction_amount: number
  purchase_invoice_id: number | null
  purchase_invoice_number: string | null
  source_item_id: number | null
  note: string | null
  created_at: string
  updated_at: string
}

/** A proposed deregistration reduction (not stored yet). */
export type RegistrationDeductionDraft = Omit<RegistrationDeductionItem, 'id' | 'created_at' | 'updated_at' | 'purchase_invoice_number'>

export interface RegistrationDeductionEvent {
  kind: RegistrationDeductionKind
  date: string
  return_period: { year: number; month: number; quarter: number | null; start: string; end: string }
  items: RegistrationDeductionItem[]
  total: number
  filed_return: null | { id: number; submission_form: string; filing_status: string; filed_at: string | null }
  /** Registration: the 12-month acquisition window. */
  window?: { start: string; end: string }
  /** Deregistration: the last registered day. */
  last_day?: string
  /** Deregistration: the computed draft reductions (section 79a) that are not stored yet. */
  suggestions?: RegistrationDeductionDraft[]
}

export interface RegistrationDeductionOverview {
  events: RegistrationDeductionEvent[]
  /** Items whose registration/deregistration row no longer exists (they are in no return). */
  orphaned: RegistrationDeductionItem[]
}

export interface RegistrationDeductionInput {
  kind: RegistrationDeductionKind
  event_date: string
  description: string
  asset_type: RegistrationAssetType
  acquired_on: string
  tax_base: number
  vat_amount: number
  adjustment_years?: 5 | 10 | null
  in_business_assets?: boolean
  purchase_invoice_id?: number | null
  source_item_id?: number | null
  note?: string | null
}

export const registrationDeductionsApi = {
  list: () =>
    api.get<RegistrationDeductionOverview>('/tax/registration-deductions').then(r => r.data),

  create: (body: RegistrationDeductionInput) =>
    api.post<RegistrationDeductionItem>('/tax/registration-deductions', body).then(r => r.data),

  update: (id: number, body: Partial<RegistrationDeductionInput>) =>
    api.put<RegistrationDeductionItem>(`/tax/registration-deductions/${id}`, body).then(r => r.data),

  remove: (id: number) =>
    api.delete<{ id: number }>(`/tax/registration-deductions/${id}`).then(r => r.data),
}
