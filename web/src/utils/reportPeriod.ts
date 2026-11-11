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
