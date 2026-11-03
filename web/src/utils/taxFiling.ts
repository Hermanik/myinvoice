/** Pure helpers of the filing workflow UI (kept free of imports so they can be unit-tested directly). */

/** `2027-01` / `2027 Q1` / `2027` label of an archive row. */
export function submissionPeriodLabel(s: { period_year: number; period_month: number | null; period_quarter: number | null }): string {
  if (s.period_month !== null) return `${s.period_year}-${String(s.period_month).padStart(2, '0')}`
  if (s.period_quarter !== null) return `${s.period_year} Q${s.period_quarter}`
  return String(s.period_year)
}

/** Value for an <input type="datetime-local"> in the user's local time (`YYYY-MM-DDTHH:MM`). */
export function nowForDatetimeLocal(now: Date = new Date()): string {
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`
}

/** The returns built from the VAT ledger — the ones with a consistency check and "changed after filing". */
export const VAT_FORMS = ['dphdp3', 'dphkh1', 'dphshv'] as const
export type VatForm = typeof VAT_FORMS[number]

/** The page of a document of a return: an issued invoice, a received invoice or the agenda of the deduction on registration. */
export function documentLink(d: { source: 'sale' | 'purchase' | 'registration_deduction'; id: number }): string {
  if (d.source === 'registration_deduction') return '/tax/registration-deductions'
  return d.source === 'sale' ? `/invoices/${d.id}` : `/purchase-invoices/${d.id}`
}

/** `2027-02-24 09:15:00` (a DB timestamp) as a local date and time. */
export function formatDbDateTime(value: string): string {
  return new Date(value.replace(' ', 'T')).toLocaleString()
}
