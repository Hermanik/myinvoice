/** A query value that is a plain whole number; repeated parameters (arrays) and anything else are not. */
function wholeNumber(value: unknown): number {
  return typeof value === 'string' && /^\d{1,4}$/.test(value) ? Number(value) : NaN
}

/**
 * The period a report page opens on: `?year=2026&month=10` when both are valid (a link from the accountant's
 * dashboard), otherwise the current month. The range matches what the API accepts.
 */
export function periodFromQuery(query: Record<string, unknown>, now: Date = new Date()): { year: number; month: number } {
  const year = wholeNumber(query.year)
  const month = wholeNumber(query.month)
  if (year >= 2020 && year <= 2050 && month >= 1 && month <= 12) return { year, month }
  return { year: now.getFullYear(), month: now.getMonth() + 1 }
}

export type ExportPeriodType = 'monthly' | 'quarterly' | 'yearly'
export type ExportBasisValue = 'duzp' | 'payment'

/** How documents are assigned by default: the whole year by payment date (income tax), shorter periods by DUZP (VAT). */
export function defaultExportBasis(type: ExportPeriodType): ExportBasisValue {
  return type === 'yearly' ? 'payment' : 'duzp'
}

/**
 * What the bulk export page opens on. `?period=yearly&year=2026` (optionally `&basis=duzp|payment`) is the
 * accountant's yearly link, `?period=quarterly&year=2026&quarter=2` a quarter, `?year=2026&month=10` a month;
 * anything else falls back to the month before `now`. The basis defaults to the period's own default.
 */
export function exportFromQuery(
  query: Record<string, unknown>,
  now: Date = new Date(),
): { type: ExportPeriodType; year: number; month: number; quarter: number; basis: ExportBasisValue } {
  const prevMonth = new Date(now.getFullYear(), now.getMonth() - 1, 1)
  const fallback = periodFromQuery({}, prevMonth)
  const year = wholeNumber(query.year)
  const validYear = year >= 2020 && year <= 2050

  let type: ExportPeriodType = 'monthly'
  let result = periodFromQuery(query, prevMonth)
  let quarter = Math.ceil(result.month / 3)
  if (query.period === 'yearly' && validYear) {
    type = 'yearly'
    result = { year, month: fallback.month }
    quarter = Math.ceil(fallback.month / 3)
  } else if (query.period === 'quarterly' && validYear && wholeNumber(query.quarter) >= 1 && wholeNumber(query.quarter) <= 4) {
    type = 'quarterly'
    quarter = wholeNumber(query.quarter)
    result = { year, month: (quarter - 1) * 3 + 1 }
  }

  const basis = query.basis === 'duzp' || query.basis === 'payment' ? query.basis : defaultExportBasis(type)
  return { type, year: result.year, month: result.month, quarter, basis }
}
