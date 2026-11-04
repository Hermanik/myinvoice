import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const root = new URL('../src/', import.meta.url)
const read = (path) => readFile(new URL(path, root), 'utf8')

test('processed e-mails list shows how the sender was authenticated', async () => {
  const page = await read('pages/admin/BankAccounts.vue')
  const api = await read('api/settings.ts')

  assert.match(api, /auth_method\?: string \| null/)
  assert.match(api, /auth_detail\?: string \| null/)
  assert.match(page, /v-if="m\.auth_detail"/, 'every record that was checked shows how')
  assert.match(page, /deferred: scanSummary\.auth_deferred \?\? 0/, 'temporary DNS failures have their own counter')
  assert.match(page, /t\('bank_accounts\.auth_detail', \{ method: authMethodLabel\(m\.auth_method\), detail: m\.auth_detail \}\)/)
})

test('cs and en explain the local DKIM check and the re-processing path', async () => {
  const [cs, en] = await Promise.all([read('i18n/cs.json'), read('i18n/en.json')].map(p => p.then(JSON.parse)))

  for (const [lang, d] of [['cs', cs], ['en', en]]) {
    const ba = d.bank_accounts
    assert.match(ba.require_email_auth_hint, /Authentication-Results/, `${lang}: A-R path still described`)
    assert.match(ba.require_email_auth_hint, /Seznam/, `${lang}: names the provider without A-R`)
    assert.match(ba.email_auth_serv_id_hint, /Authentication-Results/, `${lang}: authserv-id help`)
    assert.match(ba.email_auth_serv_id_hint, /DKIM/, `${lang}: empty = always verify DKIM`)
    assert.match(ba.scan_summary, /\{deferred\}/, `${lang}: summary shows the deferred counter`)
    assert.match(ba.require_email_auth_hint, /DKIM/, `${lang}: says the app checks DKIM itself`)
    assert.match(ba.messages_subtitle, /security_rejected/, `${lang}: delete = re-process for rejected mail`)
    for (const key of ['auth_detail', 'auth_method_authentication_results', 'auth_method_dkim_local']) {
      assert.ok(ba[key], `${lang}.bank_accounts.${key}`)
    }
    assert.match(ba.auth_detail, /\{method\}.*\{detail\}/)
    assert.match(d.bank_notice_guide.mp_seznam_auth, /DKIM/)
    assert.match(d.bank_notice_guide.ts_7, /security_rejected/)
  }
})

test('the guide lists the Seznam authenticity note and the security_rejected troubleshooting entry', async () => {
  const guide = await read('components/bank/BankNoticeGuide.vue')
  assert.match(guide, /lines: \['mp_seznam_values', 'mp_seznam_app', 'mp_seznam_imap', 'mp_seznam_auth'\]/)
  assert.match(guide, /const troubles = \[[^\]]*'ts_7'[^\]]*\]/)
})
