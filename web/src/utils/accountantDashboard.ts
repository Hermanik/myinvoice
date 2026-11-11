/** Small pure helpers of the accountant view of the dashboard. */

export type DeadlineTone = 'done' | 'overdue' | 'soon' | 'normal'

/**
 * How urgent the filing deadline of the last closed VAT period looks: nothing to chase once every return of the
 * period is filed, red after the deadline, amber in the last week.
 */
export function deadlineTone(daysLeft: number, forms: ReadonlyArray<{ status: string }>): DeadlineTone {
  if (forms.length > 0 && forms.every(f => f.status !== 'unfiled')) return 'done'
  if (daysLeft < 0) return 'overdue'
  return daysLeft <= 7 ? 'soon' : 'normal'
}

/** `year=2026&month=10` - the period the tax reports and the monthly export open on. */
export function periodQuery(year: number, month: number): string {
  return `year=${year}&month=${month}`
}

/** `YYYY-MM` of a year and month, the format `formatMonth()` takes. */
export function monthKey(year: number, month: number): string {
  return `${year}-${String(month).padStart(2, '0')}`
}
