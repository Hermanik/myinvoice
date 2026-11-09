import { api } from '@/api/client'

export type DataboxEnvironment = 'production' | 'test'
export type DataboxType = 'FO' | 'PFO' | 'PO' | string
export type SyncState = 'ok' | 'opt_in' | 'blocked_delivers' | 'blocked_no_list' | 'unverified' | 'no_credential' | 'login_failed' | 'email_only'
/** `email` = watched through the notification e-mails of the data box system only: no login, no ISDS call. */
export type ConnectionMode = 'isds' | 'email'
/** `delivered_presumed` = known from a notification e-mail only and past its fiction day: delivered at the latest (the stored state stays "in box"). */
export type MessageState = 'in_box' | 'fiction' | 'delivered' | 'delivered_presumed' | 'undeliverable' | 'erased' | 'rejected' | 'other' | 'unknown'
/** Where a stored message came from: the data box system, a notification e-mail or an uploaded signed ZFO. */
export type MessageSource = 'isds' | 'email' | 'zfo'

export interface DataboxCredential {
  purpose: 'sync' | 'action'
  username: string
  user_type: string | null
  privileges: number | null
  privilege_labels: string[]
  can_list: boolean | null
  can_read: boolean | null
  can_read_personal: boolean | null
  can_send: boolean | null
  can_search: boolean | null
  password_expires_at: string | null
  last_check_at: string | null
  last_check_status: 'ok' | 'bad_credentials' | 'locked' | 'error' | null
  last_check_message: string | null
}

export interface DataboxBox {
  id: number
  label: string | null
  environment: DataboxEnvironment
  connection_mode: ConnectionMode
  db_id: string | null
  db_type: DataboxType | null
  owner_name: string | null
  is_active: boolean
  allow_delivering_sync: boolean
  last_sync_at: string | null
  last_sync_status: 'ok' | 'error' | 'paused' | null
  last_sync_message: string | null
  sync_paused_until: string | null
  sync: { state: SyncState; delivers: boolean }
  credentials: { sync?: DataboxCredential; action?: DataboxCredential }
}

export interface CheckResult { ok: boolean; reason?: string; message?: string }
export interface VerifyResponse extends DataboxBox { check: { sync?: CheckResult; action?: CheckResult } }

export interface SyncReport {
  status: 'ok' | 'refused' | 'paused' | 'error' | 'skipped'
  message: string
  received_new: number
  received_new_tax_office: number
  sent_new: number
  updated: number
  receipts: number
}

export interface CreateBoxBody {
  label?: string
  environment: DataboxEnvironment
  sync: { username: string; password: string }
  action?: { username: string; password: string }
  allow_delivering_sync?: boolean
}

/** A box watched by notification e-mails only; the holder names it, nothing is looked up. */
export interface CreateEmailBoxBody {
  mode: 'email'
  label?: string
  db_id: string
  db_type: 'FO' | 'PFO' | 'PO'
  owner_name?: string
}

export interface EmailImapAccount { id: number; name: string; host: string; username: string; has_password: boolean }

export interface EmailNoticeSettings {
  imap_account_id: number | null
  folder: string
  enabled: boolean
  alert_email: string | null
  last_scan_at: string | null
  last_scan_status: 'ok' | 'error' | null
  last_scan_message: string | null
}

export interface EmailSettingsResponse { settings: EmailNoticeSettings; imap_accounts: EmailImapAccount[] }

export interface EmailScanSummary {
  status: 'ok' | 'error'
  message: string | null
  fetched: number
  created: number
  linked: number
  known_skipped: number
  ignored: number
  rejected: number
  unknown_box: number
  parse_failed: number
  /** Notices whose fiction day was already over: stored as dealt with, no alert (a subset of `created`). */
  historical: number
  auth_deferred: number
  alerts_sent: number
  alert_errors: number
  errors: number
}

export interface TaxOfficeHint {
  code: 'kh_change_17d' | 'kh_file_5d'
  law: string
  due: string | null
  delivered_by: string | null
}

export interface DataboxMessage {
  id: number
  databox_id: number
  direction: 'received' | 'sent'
  /** `email` = announced by a notification e-mail, not (yet) confirmed by the data box system itself; `zfo` = from an uploaded signed file */
  source: MessageSource
  dm_id: string
  sender_box_id: string | null
  sender_name: string | null
  sender_type: string | null
  recipient_box_id: string | null
  recipient_name: string | null
  annotation: string | null
  to_hands: string | null
  sender_ref_number: string | null
  recipient_ref_number: string | null
  personal_delivery: boolean
  allow_subst_delivery: boolean | null
  status: number | null
  attachment_size_kb: number | null
  delivery_time: string | null
  acceptance_time: string | null
  is_tax_office: boolean
  tax_office_kind: 'kh_call' | 'call' | 'other' | null
  first_seen_at: string | null
  acknowledged_at: string | null
  sent_by: number | null
  sent_payload: unknown
  has_zfo: boolean
  has_delivery_zfo: boolean
  has_delivery_info: boolean
  tax_office_hints: TaxOfficeHint[]
  state: MessageState
  /** Set with `state = delivered_presumed`: the day the message was delivered by fiction at the latest. */
  presumed_delivery_date: string | null
}

export interface MessageDetail extends DataboxMessage {
  attachments: null | Array<{ index: number; name: string; mime: string | null; meta_type: string | null; size: number }>
  receipt: null | { events: Array<{ time: string; text: string }> }
  open: {
    stored: boolean
    /** The box has no login (watched by e-mail): the message can only be opened in the data box portal. */
    email_only: boolean
    needs_acknowledgement: boolean
    undelivered: boolean
    fiction_date: string | null
    has_capable_login: boolean | null
    blocked_reason: 'delivery_time_unknown' | null
  }
  file: null | { size_bytes: number; sha256: string; stored_at: string }
}

export type ZfoOutcome = 'created' | 'updated' | 'duplicate' | 'rejected'

/** What happened to one uploaded signed file (a message or a delivery receipt downloaded from the portal). */
export interface ZfoImportResult {
  name: string
  outcome: ZfoOutcome
  /** Czech sentence from the server: what was stored, or why the file was refused. */
  reason: string
  kind: 'message' | 'receipt' | null
  dm_id: string | null
  direction: 'received' | 'sent' | null
  databox_id: number | null
  message_id: number | null
  /** Subject of the certificate that sealed the file (integrity only, the chain is not checked). */
  signer: string | null
  filing: null | { submission_id: number | null; status: 'recorded' | 'kept' | 'not_recorded' | 'linked' | 'no_match'; message: string | null }
}

export interface MessageList { items: DataboxMessage[]; total: number }

export interface MessageListParams {
  direction: 'received' | 'sent'
  unread?: boolean
  tax_office?: boolean
  q?: string
  page?: number
  per_page?: number
}

export interface LookupBox {
  db_id: string
  db_type: string | null
  ic: string | null
  name: string
  state: string | null
  is_tax_office: boolean
}

export interface PreviousSend {
  submission_id: number
  message_id: number
  databox_id: number
  dm_id: string | null
  recipient_box_id: string | null
  recipient_name: string | null
  status: number | null
  delivery_time: string | null
  acceptance_time: string | null
  first_seen_at: string | null
  created_at: string | null
  has_delivery_zfo: boolean
  has_delivery_info: boolean
}

export interface PrepareSummary {
  box: { id: number; label: string | null; db_id: string; db_type: string; owner_name: string | null }
  recipient: { box_id: string; name: string | null; verified: boolean; is_tax_office: boolean }
  annotation: string
  to_hands: string | null
  personal_delivery: boolean
  files: Array<{ name: string; mime: string | null; size: number; sha256: string }>
  total_bytes: number
  warnings: string[]
  previous_sends: PreviousSend[]
  requires_resend_confirmation: boolean
  requires_unvalidated_confirmation: boolean
  form: null | { form_code: string; form_name: string; period: string; xml_sha256: string; submission_id: number }
}

export interface PrepareResponse { token: string; expires_at: string; summary: PrepareSummary }

export interface SubmissionPrefill {
  submission: { id: number; form_code: string; form_name: string; period_label: string; validation_status: string }
  recipient: { c_ufo: string | null; name: string | null; box_id: string | null; source: 'xml' | 'supplier' | 'none' }
  annotation: string
  file_name: string
  file_size: number
  boxes: Array<{ id: number; label: string | null; db_id: string; db_type: string; environment: DataboxEnvironment; is_active: boolean; can_send: boolean }>
  recommended_box_id: number | null
  blocked_reason: 'xsd_failed' | 'integrity_failed' | 'duplicate_filing' | null
  warnings: string[]
  previous_sends: PreviousSend[]
}

export interface PrepareSubmissionBody { box_id: number; recipient_box_id?: string; annotation?: string }


/** Same supplier scoping the existing download helpers use. */
export function databoxDownloadUrl(path: string): string {
  const sid = localStorage.getItem('myinvoice.current_supplier_id')
  const params = new URLSearchParams()
  if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
  const extra = path.includes('?') ? '&' : '?'
  const qs = params.toString()
  return `/api/databox${path}${qs ? extra + qs : ''}`
}

// NOTE: there is intentionally no "send" function here. The only caller of the send
// endpoint is SendConfirmModal.vue, after the user confirmed the prepared summary.
export const databoxApi = {
  listBoxes: () => api.get<DataboxBox[]>('/databox/boxes').then(r => r.data),
  createBox: (body: CreateBoxBody | CreateEmailBoxBody) => api.post<DataboxBox>('/databox/boxes', body).then(r => r.data),
  updateBox: (id: number, body: { label?: string; is_active?: boolean; allow_delivering_sync?: boolean }) =>
    api.put<DataboxBox>(`/databox/boxes/${id}`, body).then(r => r.data),
  deleteBox: (id: number, confirmDeleteMessages = false) =>
    api.delete(`/databox/boxes/${id}`, { params: confirmDeleteMessages ? { confirm_delete_messages: 1 } : undefined }).then(r => r.data),
  verifyBox: (id: number) => api.post<VerifyResponse>(`/databox/boxes/${id}/verify`).then(r => r.data),
  setCredential: (id: number, purpose: 'sync' | 'action', body: { username: string; password: string }) =>
    api.put<DataboxBox>(`/databox/boxes/${id}/credentials/${purpose}`, body).then(r => r.data),
  removeCredential: (id: number, purpose: 'sync' | 'action') =>
    api.delete<DataboxBox>(`/databox/boxes/${id}/credentials/${purpose}`).then(r => r.data),
  syncBox: (id: number) =>
    api.post<{ report: SyncReport; box: DataboxBox }>(`/databox/boxes/${id}/sync`).then(r => r.data),
  /** `id` = null acknowledges the received messages of every box. */
  acknowledgeAll: (id: number | null) =>
    api.post<{ acknowledged: number }>(id === null ? '/databox/acknowledge-all' : `/databox/boxes/${id}/acknowledge-all`).then(r => r.data),
  lookup: (id: number, q: string) =>
    api.get<{ status_code: string | null; boxes: LookupBox[] }>(`/databox/boxes/${id}/lookup`, { params: { q } }).then(r => r.data),

  emailSettings: () => api.get<EmailSettingsResponse>('/databox/email-settings').then(r => r.data),
  saveEmailSettings: (body: Partial<Pick<EmailNoticeSettings, 'imap_account_id' | 'folder' | 'enabled' | 'alert_email'>>) =>
    api.put<EmailSettingsResponse>('/databox/email-settings', body).then(r => r.data),
  scanEmail: () =>
    api.post<EmailSettingsResponse & { summary: EmailScanSummary }>('/databox/email-settings/scan').then(r => r.data),

  /** `boxId` = null lists the messages of every box of the supplier together. */
  listMessages: (boxId: number | null, p: MessageListParams) =>
    api.get<MessageList>(boxId === null ? '/databox/messages' : `/databox/boxes/${boxId}/messages`, {
      params: {
        direction: p.direction,
        unread: p.unread ? 1 : undefined,
        tax_office: p.tax_office ? 1 : undefined,
        q: p.q || undefined,
        page: p.page,
        per_page: p.per_page,
      },
    }).then(r => r.data),
  getMessage: (id: number) => api.get<MessageDetail>(`/databox/messages/${id}`).then(r => r.data),
  /** `acknowledge` must only be true after the user confirmed the delivery warning. */
  openMessage: (id: number, acknowledge = false) =>
    api.post<MessageDetail>(`/databox/messages/${id}/open`, { acknowledge_delivery: acknowledge }).then(r => r.data),
  acknowledge: (id: number) => api.post(`/databox/messages/${id}/acknowledge`).then(r => r.data),
  fetchReceipt: (id: number) => api.post<MessageDetail>(`/databox/messages/${id}/receipt`).then(r => r.data),
  attachmentUrl: (id: number, index: number) => databoxDownloadUrl(`/messages/${id}/attachments/${index}`),
  zfoUrl: (id: number, kind?: 'delivery_zfo') => databoxDownloadUrl(`/messages/${id}/zfo${kind ? `?kind=${kind}` : ''}`),

  // Multipart: the shared instance defaults to JSON, so override it like the other upload clients do;
  // axios drops the header for FormData and the browser adds the boundary.
  /** Signed ZFO files from the portal; one file per request keeps the upload under the server's request size limit. */
  importZfo: (form: FormData) =>
    api.post<{ results: ZfoImportResult[] }>('/databox/import-zfo', form, { headers: { 'Content-Type': 'multipart/form-data' } }).then(r => r.data),

  prepareMessage: (form: FormData) =>
    api.post<PrepareResponse>('/databox/messages/prepare', form, { headers: { 'Content-Type': 'multipart/form-data' } }).then(r => r.data),

  submissionPrefill: (id: number) => api.get<SubmissionPrefill>(`/databox/submissions/${id}/prefill`).then(r => r.data),
  prepareSubmission: (id: number, body: PrepareSubmissionBody) =>
    api.post<PrepareResponse>(`/databox/submissions/${id}/prepare`, body).then(r => r.data),
  submissionLinks: (ids: number[]) =>
    api.get<Record<string, PreviousSend[]>>('/databox/submissions/links', { params: { ids: ids.join(',') } }).then(r => r.data),
}
