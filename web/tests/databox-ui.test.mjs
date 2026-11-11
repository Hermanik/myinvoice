import assert from 'node:assert/strict'
import { readFile, readdir } from 'node:fs/promises'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'
import test from 'node:test'

const root = new URL('../src/', import.meta.url)
const read = (path) => readFile(new URL(path, root), 'utf8')

async function walk(dir) {
  const out = []
  for (const e of await readdir(dir, { withFileTypes: true })) {
    const p = join(dir, e.name)
    if (e.isDirectory()) out.push(...await walk(p))
    else if (/\.(vue|ts)$/.test(e.name)) out.push(p)
  }
  return out
}
const allSources = async () => {
  const files = await walk(fileURLToPath(root))
  return Promise.all(files.map(async f => ({ file: f.slice(fileURLToPath(root).length), src: await readFile(f, 'utf8') })))
}
const settingsFiles = ['components/settings/DataboxSettings.vue', 'components/databox/BoxCard.vue', 'components/databox/AddBoxForm.vue',
  'components/databox/CredentialForm.vue', 'components/databox/CredentialRow.vue', 'components/databox/IsdsHelp.vue', 'components/databox/PermissionIndicator.vue',
  'components/databox/EmailNoticeSettings.vue']

test('the databox route is for admin and accountant, the sidebar entry is gated by showDatabox', async () => {
  const router = await read('router/index.ts')
  assert.match(router, /path: 'databox',\s+name: 'databox',[^\n]*meta: \{ requiresWrite: true \}/)
  assert.doesNotMatch(router, /path: 'databox',[^\n]*adminOnly/)
  const layout = await read('components/layout/AppLayout.vue')
  assert.match(layout, /showDatabox \? \[\{ to: '\/databox', label: t\('nav\.databox'\)/)
  const settings = await read('pages/admin/Settings.vue')
  assert.match(settings, /<DataboxSettings\s*\/>/)
  assert.match(await read('components/settings/DataboxSettings.vue'), /<section id="databox"/)
})

test('cs and en carry identical, non-empty databox key sets', async () => {
  const [cs, en] = await Promise.all([read('i18n/cs.json'), read('i18n/en.json')].map(p => p.then(JSON.parse)))
  const keys = (o, p = '') => Object.entries(o).flatMap(([k, v]) =>
    v && typeof v === 'object' && !Array.isArray(v) ? keys(v, `${p}${k}.`) : [`${p}${k}`])
  const csKeys = keys(cs.databox).sort()
  assert.ok(csKeys.length > 100)
  assert.deepEqual(csKeys, keys(en.databox).sort())
  assert.ok(cs.nav.databox && en.nav.databox)
  for (const k of ['send_databox', 'databox_delivered', 'databox_sent']) assert.ok(cs.reports.submissions[k] && en.reports.submissions[k])
  assert.equal(cs.databox.help.steps.length, en.databox.help.steps.length)
  assert.equal(cs.databox.help.levels.length, en.databox.help.levels.length)
})

test('only SendConfirmModal posts to the send endpoint', async () => {
  const sources = await allSources()
  const senders = sources.filter(s => /\/databox\/messages\/send\b/.test(s.src)).map(s => s.file)
  assert.deepEqual(senders, ['components/databox/SendConfirmModal.vue'])
  const confirmed = sources.filter(s => /confirmed:\s*true/.test(s.src)).map(s => s.file)
  assert.deepEqual(confirmed, ['components/databox/SendConfirmModal.vue'])
  const modal = await read('components/databox/SendConfirmModal.vue')
  assert.match(modal, /async function send\(\)/)
  assert.match(modal, /@click="send"/)
  assert.doesNotMatch(modal, /onMounted|setTimeout|setInterval/, 'never sends on its own')
  assert.match(modal, /:disabled="!canSend"/)
  assert.match(modal, /requires_resend_confirmation/)
  assert.match(modal, /summary\.value\.requires_unvalidated_confirmation \|\| unvalidatedConfirmed\.value/, 'a never validated filing needs its own checkbox')
  assert.match(modal, /body\.unvalidated_confirmed = true/)
})

test('acknowledge_delivery is only sent from the confirmation path of the open flow', async () => {
  const sources = await allSources()
  const withAck = sources.filter(s => s.src.includes('acknowledge_delivery')).map(s => s.file)
  assert.deepEqual(withAck, ['api/databox.ts'])
  const callers = sources.filter(s => /openMessage\(/.test(s.src) && s.file !== 'api/databox.ts')
  assert.deepEqual(callers.map(s => s.file), ['components/databox/MessageDetail.vue'])
  const detail = callers[0].src
  assert.equal([...detail.matchAll(/doOpen\(true\)/g)].length, 1)
  assert.match(detail, /const confirmOpen = \(\) => doOpen\(true\)/)
  assert.match(detail, /@confirm="confirmOpen"/)
  assert.match(detail, /doOpen\(false\)/)
  const modal = await read('components/databox/OpenConfirmModal.vue')
  assert.match(modal, /:disabled="!understood \|\| busy"/)
})

test('password inputs are masked and not autofilled; settings never touch web storage', async () => {
  for (const f of settingsFiles) {
    const src = await read(f)
    assert.doesNotMatch(src, /localStorage|sessionStorage/, `${f} must not use web storage`)
    assert.doesNotMatch(src, /console\.(log|debug|info)/, `${f} must not log`)
    for (const m of src.matchAll(/<input[^>]*v-model="[^"]*[Pp]ass[^"]*"[^>]*>/g)) {
      assert.match(m[0], /type="password"/)
      assert.match(m[0], /autocomplete="new-password"/)
    }
  }
  const form = await read('components/databox/AddBoxForm.vue')
  assert.match(form, /syncPass\.value = ''/)
  const api = await read('api/databox.ts')
  assert.match(api, /localStorage\.getItem\('myinvoice\.current_supplier_id'\)/, 'only the supplier id is read for download URLs')
})

test('the delivering-sync toggle asks for confirmation and multipart prepare uses FormData', async () => {
  const card = await read('components/databox/BoxCard.vue')
  assert.match(card, /window\.confirm\(t\('databox\.delivering\.confirm'\)\)/)
  assert.match(card, /confirm_required/)
  const compose = await read('components/databox/ComposeModal.vue')
  assert.match(compose, /new FormData\(\)/)
  assert.match(compose, /databoxApi\.prepareMessage\(fd\)/)
})

test('TaxSubmissions offers the data box send only to admins, inside the filing controls', async () => {
  const src = await read('pages/reports/TaxSubmissions.vue')
  // One coherent page: the send button lives in the admin block next to "mark as filed", once; so does the chip.
  assert.match(src, /<template v-if="isAdmin">\s*<button v-if="s\.filing_status === 'generated'"[\s\S]*?@click="sendingId = s\.id" data-test="databox-send"/)
  assert.equal([...src.matchAll(/data-test="databox-send"/g)].length, 1)
  assert.equal([...src.matchAll(/data-test="databox-chip"/g)].length, 1)
  assert.match(src, /<router-link v-if="isAdmin && lastLink\(s\.id\)"/)
  assert.match(src, /<SubmissionSendModal v-if="isAdmin && sendingId !== null"/)
  assert.match(src, /@sent="onDataboxSent"/)
  assert.match(src, /if \(!isAdmin\.value \|\| items\.value\.length === 0\) return/)
  // The filing line and the chip do not both print the message number.
  assert.match(src, /showReference\(s\)/)
  const modal = await read('components/databox/SubmissionSendModal.vue')
  assert.match(modal, /blocked_reason != null/)
  assert.match(modal, /prepareSubmission\(/)
  assert.match(modal, /<SendConfirmModal/)
  const confirm = await read('components/databox/SendConfirmModal.vue')
  assert.match(confirm, /data-test="filing-outcome"/)
})

test('the e-mail notice mode: settings section, add-box choice, badge and portal link instead of the open button', async () => {
  const settings = await read('components/settings/DataboxSettings.vue')
  assert.match(settings, /<EmailNoticeSettings\s*\/>/)

  const section = await read('components/databox/EmailNoticeSettings.vue')
  assert.match(section, /databoxApi\.emailSettings\(\)/)
  assert.match(section, /databoxApi\.saveEmailSettings\(body\(\)\)/)
  assert.match(section, /databoxApi\.scanEmail\(\)/)
  assert.match(section, /data-test="email-scan-now"/)
  assert.match(section, /v-model="enabled" type="checkbox"/)
  assert.doesNotMatch(section, /type="password"/, 'the IMAP password stays in the bank notice settings')

  // Adding a box: the e-mail choice sends no logins and hides every password field.
  const form = await read('components/databox/AddBoxForm.vue')
  assert.match(form, /value="email"/)
  assert.match(form, /mode: 'email',\s+label:/)
  assert.match(form, /if \(mode\.value === 'email'\) return submitEmail\(\)/)
  assert.equal([...form.matchAll(/<fieldset v-if="mode === 'isds'"/g)].length, 2, 'both login blocks are ISDS only')
  const emailSubmit = form.slice(form.indexOf('function submitEmail'), form.indexOf('const canSubmit'))
  assert.doesNotMatch(emailSubmit, /syncUser|syncPass|actionPass/, 'no login goes into an e-mail box')

  // A box watched by e-mail has no login actions.
  const card = await read('components/databox/BoxCard.vue')
  assert.match(card, /const emailOnly = computed\(\(\) => props\.box\.connection_mode === 'email'\)/)
  assert.match(card, /<button v-if="!emailOnly" type="button" :class="btn" :disabled="busy" @click="verify">/)
  assert.match(card, /<button v-if="!emailOnly" type="button" :class="btn" :disabled="busy \|\| !box\.is_active" @click="syncNow">/)

  // Messages: badge in the list and the detail, portal link instead of "Otevřít a stáhnout".
  const page = await read('pages/databox/DataboxPage.vue')
  assert.match(page, /v-if="m\.source === 'email'"[^>]*data-test="source-email-badge"/)
  assert.match(page, /<ComposeModal v-if="composing" :boxes="sendableBoxes"/, 'nothing can be sent from a box without a login')
  const detail = await read('components/databox/MessageDetail.vue')
  assert.match(detail, /v-if="detail\.source === 'email'"[^>]*data-test="source-email-badge"/)
  assert.match(detail, /const canOpen = computed\(\(\) => isReceived\.value && !emailOnly\.value &&/)
  assert.match(detail, /const PORTAL_URL = 'https:\/\/www\.datovka\.gov\.cz'/)
  assert.match(detail, /<a :href="PORTAL_URL"[^>]*data-test="portal-link"/)
  assert.match(detail, /<div v-if="emailOnly"[^>]*data-test="email-only-open"/)
})

test('the e-mail notice texts exist in both languages', async () => {
  const [cs, en] = await Promise.all([read('i18n/cs.json'), read('i18n/en.json')].map(p => p.then(JSON.parse)))
  for (const d of [cs.databox, en.databox]) {
    for (const k of ['title', 'intro', 'account', 'folder', 'alert', 'enabled', 'scan_now', 'summary', 'box_id', 'box_type']) assert.ok(d.email[k], `email.${k}`)
    assert.ok(d.source.email && d.mode.email && d.mode.email_badge && d.open.portal && d.open.email_only && d.errors.email_only)
    assert.ok(d.indicator.email_only && d.indicator.email_only_title)
  }
  assert.match(cs.databox.source.email, /e-mailov/)
})

test('the ZFO upload: button on the page, drag and drop modal, one request per file, result per file, badge', async () => {
  const page = await read('pages/databox/DataboxPage.vue')
  assert.match(page, /<button v-if="boxes\.length > 0"[^>]*data-test="zfo-import-button" @click="importingZfo = true"/)
  assert.match(page, /<ZfoImportModal v-if="importingZfo" @close="importingZfo = false" @imported="loadMessages"/)
  assert.match(page, /v-else-if="m\.source === 'zfo'"[^>]*data-test="source-zfo-badge"/)
  const detail = await read('components/databox/MessageDetail.vue')
  assert.match(detail, /v-else-if="detail\.source === 'zfo'"[^>]*data-test="source-zfo-badge"/)
  assert.match(detail, /<div v-if="detail\.receipt\?\.events\?\.length">/, 'the receipt events show for received messages too')

  const modal = await read('components/databox/ZfoImportModal.vue')
  assert.match(modal, /data-test="zfo-dropzone"/)
  assert.match(modal, /@drop\.prevent="onDrop"/)
  assert.match(modal, /<input ref="picker" type="file" multiple/)
  // One file per request, appended as files[] like the compose form does, and a failure of one file does not stop the others.
  assert.match(modal, /fd\.append\('files\[\]', f\)/)
  assert.equal([...modal.matchAll(/databoxApi\.importZfo\(/g)].length, 1)
  assert.match(modal, /catch \(e\) \{\s+results\.value\.push\(failed\(f\.name, databoxErrorMessage\(e, t, te\)\)\)/)
  assert.match(modal, /data-test="zfo-result"/)
  assert.doesNotMatch(modal, /onMounted|setTimeout|setInterval/, 'uploads only on the click')
  assert.match(modal, /:disabled="!canUpload"/)

  const api = await read('api/databox.ts')
  assert.match(api, /importZfo: \(form: FormData\) =>\s+api\.post<\{ results: ZfoImportResult\[\] \}>\('\/databox\/import-zfo'/)
  const sources = await allSources()
  assert.deepEqual(sources.filter(s => /importZfo\(/.test(s.src) && s.file !== 'api/databox.ts').map(s => s.file), ['components/databox/ZfoImportModal.vue'])
})

test('the presumed delivery state: label with the day, same colour as a delivered message, explanation and hint in the detail', async () => {
  const util = await read('utils/databox.ts')
  assert.match(util, /case 'delivered_presumed':\s+case 'delivered': return 'bg-success-50/)
  assert.match(util, /return t\(`databox\.state\.\$\{m\.state\}`, \{ date: formatDate\(m\.presumed_delivery_date\) \}\)/)
  for (const file of ['pages/databox/DataboxPage.vue', 'components/databox/MessageDetail.vue']) {
    const src = await read(file)
    assert.match(src, /stateLabel\((m|detail), t\)/, file)
    assert.doesNotMatch(src, /t\(`databox\.state\.\$\{/, `${file} must label the state through stateLabel() (the presumed state carries a date)`)
  }
  const detail = await read('components/databox/MessageDetail.vue')
  assert.match(detail, /stateHelp\(detail, t\)/)
  assert.match(detail, /v-if="detail\.state === 'delivered_presumed'"[^>]*data-test="presumed-help"/)
})

test('the ZFO and presumed delivery texts exist in both languages', async () => {
  const [cs, en] = await Promise.all([read('i18n/cs.json'), read('i18n/en.json')].map(p => p.then(JSON.parse)))
  for (const d of [cs.databox, en.databox]) {
    for (const k of ['button', 'title', 'help_where', 'help_scope', 'help_delivers', 'help_integrity', 'drop', 'drop_hint', 'too_big', 'too_many', 'upload', 'uploading', 'results', 'signer', 'summary', 'presumed_hint']) assert.ok(d.zfo[k], `zfo.${k}`)
    for (const o of ['created', 'updated', 'duplicate', 'rejected']) assert.ok(d.zfo.outcome[o], `zfo.outcome.${o}`)
    for (const k of ['message', 'receipt']) assert.ok(d.zfo.kind[k], `zfo.kind.${k}`)
    assert.ok(d.source.zfo && d.state.delivered_presumed && d.state_help.delivered_presumed)
    assert.match(d.state.delivered_presumed, /\{date\}/)
    assert.match(d.state_help.delivered_presumed, /\{date\}/)
    assert.match(d.email.summary, /\{historical\}/)
  }
  assert.equal(cs.databox.source.zfo, 'ze ZFO')
  assert.match(cs.databox.zfo.help_where, /Stáhnout zprávu/)
  assert.match(cs.databox.zfo.help_where, /Stáhnout doručenku/)
  assert.match(cs.databox.zfo.help_delivers, /doručí/)
})
