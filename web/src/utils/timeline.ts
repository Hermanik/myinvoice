import type { TimelineItem, TimelineStatus } from '../api/timeline'

/** Date helpers work on `YYYY-MM-DD` strings in UTC, so a daylight-saving shift never moves a day. */

/** In the order the server lists items of the same day. */
export const TIMELINE_CATEGORIES = ['registration', 'dph', 'income_tax', 'pausal', 'insurance', 'invoicing', 'custom'] as const
export type TimelineCategory = (typeof TIMELINE_CATEGORIES)[number]

export type StatusFilter = 'all' | 'todo' | 'done' | 'overdue'
export const RANGE_PRESETS = ['rest_and_next', 'next_90', 'this_year', 'next_year'] as const
export type RangePreset = (typeof RANGE_PRESETS)[number]

function parse(ymd: string): Date {
  return new Date(`${ymd}T00:00:00Z`)
}

/** A real calendar date: 2026-02-30 is not (the Date parser would silently roll it over to March). */
export function isYmd(value: string): boolean {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false
  const d = parse(value)
  return !Number.isNaN(d.getTime()) && d.toISOString().slice(0, 10) === value
}

export function addDays(ymd: string, days: number): string {
  const d = parse(ymd)
  d.setUTCDate(d.getUTCDate() + days)
  return d.toISOString().slice(0, 10)
}

/**
 * The date range of a preset. "Rest of the year + next year" starts 30 days back so that what was just missed stays
 * visible — the same default as the server.
 */
export function presetRange(preset: RangePreset, today: string): { from: string; to: string } {
  const year = Number(today.slice(0, 4))
  switch (preset) {
    case 'rest_and_next': return { from: addDays(today, -30), to: `${year + 1}-12-31` }
    case 'next_90': return { from: today, to: addDays(today, 90) }
    case 'this_year': return { from: `${year}-01-01`, to: `${year}-12-31` }
    case 'next_year': return { from: `${year + 1}-01-01`, to: `${year + 1}-12-31` }
  }
}

export function matchingPreset(from: string, to: string, today: string): RangePreset | null {
  return RANGE_PRESETS.find(p => {
    const r = presetRange(p, today)
    return r.from === from && r.to === to
  }) ?? null
}

const DUE_SOON_DAYS = 7
const UNVERIFIED_AFTER_DAYS = 30

/**
 * Status of an item given whether it is done — the same rule as ObligationTimelineService::status() on the server;
 * used to update the list right after a mark, without another request.
 */
export function statusOf(item: Pick<TimelineItem, 'days_left' | 'tracked' | 'info'>, done: boolean): TimelineStatus {
  if (done) return 'done'
  if (item.info) return 'info'
  if (item.days_left < 0) return !item.tracked && item.days_left < -UNVERIFIED_AFTER_DAYS ? 'unverified' : 'overdue'
  return item.days_left <= DUE_SOON_DAYS ? 'due_soon' : 'upcoming'
}

/** "To do" is everything that is not done, so warnings and unconfirmed items do not disappear from the list of work. */
export function matchesStatus(item: Pick<TimelineItem, 'status'>, filter: StatusFilter): boolean {
  switch (filter) {
    case 'all': return true
    case 'todo': return item.status !== 'done'
    case 'done': return item.status === 'done'
    case 'overdue': return item.status === 'overdue'
  }
}

/** No category selected = all of them. */
export function filterItems(items: readonly TimelineItem[], status: StatusFilter, categories: readonly TimelineCategory[]): TimelineItem[] {
  return items.filter(i => matchesStatus(i, status) && (categories.length === 0 || categories.includes(i.category)))
}

export function countByCategory(items: readonly TimelineItem[]): Partial<Record<TimelineCategory, number>> {
  const counts: Partial<Record<TimelineCategory, number>> = {}
  for (const i of items) counts[i.category] = (counts[i.category] ?? 0) + 1
  return counts
}

export type TimelineRow =
  | { kind: 'month'; ym: string }
  | { kind: 'today' }
  | { kind: 'item'; item: TimelineItem }

/**
 * Month headings, the items between them and a "today" marker where today falls in the (date-sorted) list.
 * The marker is left out when today is outside the shown range.
 */
export function buildRows(items: readonly TimelineItem[], today: string, from: string, to: string): TimelineRow[] {
  const rows: TimelineRow[] = []
  const showMarker = today >= from && today <= to
  const todayMonth = today.slice(0, 7)
  let placed = !showMarker
  let lastMonth = ''
  for (const item of items) {
    const ym = item.due_date.slice(0, 7)
    const reached = item.due_date >= today
    if (!placed && reached && ym !== lastMonth && todayMonth < ym) {
      rows.push({ kind: 'today' })
      placed = true
    }
    if (ym !== lastMonth) {
      rows.push({ kind: 'month', ym })
      lastMonth = ym
    }
    if (!placed && reached) {
      rows.push({ kind: 'today' })
      placed = true
    }
    rows.push({ kind: 'item', item })
  }
  if (!placed) rows.push({ kind: 'today' })
  return rows
}

export interface TextFormatters {
  date: (ymd: string) => string
  money: (amount: number) => string
  /** `YYYY-MM` => "listopad 2026" in the active language. */
  month: (ym: string) => string
  quarter: (quarter: number, year: number) => string
  claim: (code: string) => string
}

const MONEY_KEYS = new Set(['limit', 'income', 'turnover', 'remaining', 'tax'])

/**
 * Parameters of the catalogue texts of an item: the server's own `params` formatted for display (dates, money in
 * CZK) plus the derived ones the texts refer to (`period_text`, `month_text`, `band_no`, `form_label`, `claims_text`,
 * `next_year`, `due`).
 */
export function textParams(item: Pick<TimelineItem, 'params' | 'due_date'>, f: TextFormatters): Record<string, unknown> {
  const p = item.params
  const out: Record<string, unknown> = {}
  for (const [k, v] of Object.entries(p)) {
    out[k] = typeof v === 'string' && isYmd(v) ? f.date(v)
      : typeof v === 'number' && MONEY_KEYS.has(k) ? f.money(v)
      : v
  }
  out.due = f.date(item.due_date)
  if (typeof p.year === 'number') out.next_year = p.year + 1
  if (typeof p.band === 'string') out.band_no = p.band.replace('band', '')
  if (typeof p.form === 'string') out.form_label = p.form === 'dppdp9' ? 'DPPO' : 'DPFO'
  if (p.period === 'monthly' || p.period === 'quarterly') {
    out.period_text = p.period === 'quarterly'
      ? f.quarter(Number(p.quarter), Number(p.year))
      : f.month(`${p.year}-${String(p.month).padStart(2, '0')}`)
  }
  const ym = typeof p.period === 'string' && /^\d{4}-\d{2}$/.test(p.period) ? p.period
    : typeof p.year === 'number' && typeof p.month === 'number' ? `${p.year}-${String(p.month).padStart(2, '0')}` : null
  if (ym) out.month_text = f.month(ym)
  if (Array.isArray(p.claims)) out.claims_text = p.claims.map(c => f.claim(String(c))).join(', ')
  return out
}

const formatterCache = new Map<string, { month: Intl.DateTimeFormat; weekday: Intl.DateTimeFormat }>()

/** Short month and long weekday formatters of a UI language, built once instead of once per card. */
export function dateFormatters(lang: string): { month: Intl.DateTimeFormat; weekday: Intl.DateTimeFormat } {
  let f = formatterCache.get(lang)
  if (!f) {
    const loc = lang === 'en' ? 'en-US' : 'cs-CZ'
    f = {
      month: new Intl.DateTimeFormat(loc, { month: 'short', timeZone: 'UTC' }),
      weekday: new Intl.DateTimeFormat(loc, { weekday: 'long', timeZone: 'UTC' }),
    }
    formatterCache.set(lang, f)
  }
  return f
}

/** A link is shown only when it is an in-app path or an http(s) address — never `javascript:` and the like. */
export function safeLink(url: string | null | undefined): { kind: 'internal' | 'external'; href: string } | null {
  if (!url) return null
  if (/^\/(?![/\\])\S*$/.test(url)) return { kind: 'internal', href: url }
  if (/^https?:\/\/\S+$/i.test(url)) return { kind: 'external', href: url }
  return null
}
