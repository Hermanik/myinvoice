import { api } from './client'
import type { TimelineCategory } from '../utils/timeline'

export type { TimelineCategory }
export type TimelineStatus = 'done' | 'overdue' | 'due_soon' | 'upcoming' | 'info' | 'unverified'

export interface TimelineMark {
  done: boolean
  note: string | null
  /** Name of the user who made the mark. */
  by: string | null
  at: string
}

export interface TimelineNote {
  code: string
  params: Record<string, unknown>
}

export interface TimelineLink {
  to: string
  /** Key of `timeline.link.*`. */
  label: string
}

export interface TimelineItem {
  /** Stable key — the key of a manual mark; `custom:<id>` for a custom item. */
  key: string
  /** Key of the text in the catalogue (`timeline.item.<type>.*`). */
  type: string
  category: TimelineCategory
  due_date: string
  start_date: string | null
  days_left: number
  status: TimelineStatus
  /** `auto` = evidence in the application, `manual` = a mark by hand. */
  done_source: 'auto' | 'manual' | null
  /** The application can tell the state itself; otherwise only a mark finishes the item. */
  tracked: boolean
  obligation: 'legal' | 'recommended'
  /** Informational only — never overdue. */
  info: boolean
  severity: 'normal' | 'warning'
  params: Record<string, unknown>
  notes: TimelineNote[]
  legal: string | null
  amount: number | null
  amount_estimated: boolean
  links: TimelineLink[]
  filing?: { status: 'unfiled' | 'filed' | 'confirmed'; submission_id: number | null; filed_at: string | null } | null
  mark: TimelineMark | null
  /** Only a custom task: its own text and link. */
  custom?: Pick<TimelineCustomItem, 'id' | 'title' | 'note' | 'url'>
}

export interface TimelineSummary {
  total: number
  by_status: Record<TimelineStatus, number>
  by_category: Partial<Record<TimelineCategory, number>>
}

export interface Timeline {
  today: string
  from: string
  to: string
  items: TimelineItem[]
  summary: TimelineSummary
}

export interface TimelineCustomInput {
  title: string
  due_date: string
  start_date?: string | null
  category?: TimelineCategory
  note?: string | null
  url?: string | null
  done?: boolean
}

export interface TimelineCustomItem {
  id: number
  title: string
  due_date: string
  start_date: string | null
  category: TimelineCategory
  note: string | null
  url: string | null
  done_at: string | null
  done_by: string | null
}

export const timelineApi = {
  get: (from: string, to: string) =>
    api.get<Timeline>('/timeline', { params: { from, to } }).then(r => r.data),

  setMark: (key: string, done: boolean, note: string | null) =>
    api.put<{ key: string; mark: TimelineMark | null }>('/timeline/marks', { key, done, note }).then(r => r.data),

  clearMark: (key: string) =>
    api.delete<{ key: string; mark: null }>('/timeline/marks', { params: { key } }).then(r => r.data),

  createCustom: (body: TimelineCustomInput) =>
    api.post<TimelineCustomItem>('/timeline/custom-items', body).then(r => r.data),

  updateCustom: (id: number, body: Partial<TimelineCustomInput>) =>
    api.put<TimelineCustomItem>(`/timeline/custom-items/${id}`, body).then(r => r.data),

  removeCustom: (id: number) =>
    api.delete<{ id: number }>(`/timeline/custom-items/${id}`).then(r => r.data),
}
