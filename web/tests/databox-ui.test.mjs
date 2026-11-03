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
  'components/databox/CredentialForm.vue', 'components/databox/CredentialRow.vue', 'components/databox/IsdsHelp.vue', 'components/databox/PermissionIndicator.vue']

test('the databox route is admin only and the sidebar entry is gated by isAdmin', async () => {
  const router = await read('router/index.ts')
  assert.match(router, /path: 'databox',\s+name: 'databox',[^\n]*meta: \{ adminOnly: true \}/)
  const layout = await read('components/layout/AppLayout.vue')
  assert.match(layout, /isAdmin \? \[\{ to: '\/databox', label: t\('nav\.databox'\)/)
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
