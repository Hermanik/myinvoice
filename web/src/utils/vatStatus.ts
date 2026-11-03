/**
 * Dated VAT status ("plátcovství DPH") of the supplier, resolved on the client.
 *
 * The server sends the compact history with every supplier (`vat_history` in /auth/me),
 * so the invoice editor can decide the VAT UI by the invoice's DUZP without a request.
 * Rules mirror the backend (`VatStatusHistory`): the status on date D is the row with the
 * greatest `valid_from` <= D; a date before the first row takes the first row's status.
 */

export type VatStatus = 'payer' | 'non_payer' | 'identified'

export interface VatHistoryRow {
  valid_from: string
  status: VatStatus
}

export interface VatFlags {
  is_vat_payer: boolean
  is_identified: boolean
}

const day = (date: string): string => date.slice(0, 10)

/**
 * Status on `date` (YYYY-MM-DD). An empty history falls back to the supplier's cached flags
 * (`fallback`, what the server derives its own floor row from); without them it counts as a payer.
 */
export function vatStatusAt(
  history: readonly VatHistoryRow[] | null | undefined,
  date: string,
  fallback?: VatFlags,
): VatStatus {
  if (!history || history.length === 0) {
    if (!fallback) return 'payer'
    return fallback.is_vat_payer ? 'payer' : fallback.is_identified ? 'identified' : 'non_payer'
  }
  const rows = [...history].sort((a, b) => day(a.valid_from).localeCompare(day(b.valid_from)))
  const d = day(date)
  let status = rows[0].status
  for (const row of rows) {
    if (day(row.valid_from) > d) break
    status = row.status
  }
  return status
}

export function vatFlagsAt(
  history: readonly VatHistoryRow[] | null | undefined,
  date: string,
  fallback?: VatFlags,
): VatFlags {
  const status = vatStatusAt(history, date, fallback)
  return { is_vat_payer: status === 'payer', is_identified: status === 'identified' }
}

/** Date that decides a document's VAT status: DUZP, falling back to the issue date, then today. */
export function documentVatDate(taxDate: string | null | undefined, issueDate: string | null | undefined, today: string): string {
  return day(taxDate || issueDate || today)
}
