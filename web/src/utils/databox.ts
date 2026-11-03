import { apiErrorMessage } from '@/api/errors'
import type { MessageState } from '@/api/databox'

export function formatDateTime(s: string | null | undefined): string {
  if (!s) return '—'
  const d = new Date(s.replace(' ', 'T'))
  return Number.isNaN(d.getTime()) ? s : d.toLocaleString()
}

export function stateBadgeClass(state: MessageState): string {
  switch (state) {
    case 'in_box': return 'bg-warning-50 text-warning-600 border-warning-500/40'
    case 'fiction':
    case 'delivered': return 'bg-success-50 text-success-600 border-success-500/40'
    case 'undeliverable':
    case 'rejected': return 'bg-danger-50 text-danger-500 border-danger-500/40'
    default: return 'bg-neutral-100 text-neutral-600 border-neutral-200'
  }
}

export function boxTitle(b: { label: string | null; owner_name?: string | null; db_id: string | null }): string {
  return b.label || b.owner_name || b.db_id || '—'
}

/** Days until an ISO date (negative when past); null for an empty date. */
export function daysUntil(date: string | null | undefined): number | null {
  if (!date) return null
  const d = new Date(date.replace(' ', 'T'))
  if (Number.isNaN(d.getTime())) return null
  return Math.ceil((d.getTime() - Date.now()) / 86_400_000)
}

/** Error code from the `{error:{code}}` envelope. */
export function errorCode(e: any): string | undefined {
  return e?.response?.data?.error?.code
}

/** Friendly text for known backend codes (databox.errors.<code>), falling back to the server message. */
export function databoxErrorMessage(e: any, t: (k: string) => string, te: (k: string) => boolean): string {
  const code = errorCode(e)
  const key = code ? `databox.errors.${code}` : ''
  if (code && te(key)) return t(key)
  return apiErrorMessage(e, t('common.error'))
}

/** Czech sentence for a warning code of the send summary (databox.warnings.<code>), the raw code when unknown. */
export function warningText(code: string, t: (k: string) => string, te: (k: string) => boolean): string {
  return te(`databox.warnings.${code}`) ? t(`databox.warnings.${code}`) : code
}
