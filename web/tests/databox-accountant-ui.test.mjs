import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const root = new URL('../src/', import.meta.url)
const read = path => readFile(new URL(path, root), 'utf8')
const json = async path => JSON.parse(await read(path))

const { TOUR_STEPS, resolveSteps, findTourTarget } = await import('../src/utils/tour.ts')

/** A stand-in for a document: `data-tour` values mapped to whether the element takes space on the screen. */
function fakeRoot(present) {
  return {
    querySelectorAll(selector) {
      const id = selector.match(/^\[data-tour="([a-z0-9-]+)"\]$/)?.[1]
      return (present[id] ?? []).map(rendered => ({ id, getClientRects: () => (rendered ? [{}] : []) }))
    },
  }
}

const keys = (o, p = '') => Object.entries(o).flatMap(([k, v]) =>
  v && typeof v === 'object' && !Array.isArray(v) ? keys(v, `${p}${k}.`) : [`${p}${k}`])

test('the menu item for the accountant needs a box the owner opened to her, the admin always has it', async () => {
  const layout = await read('components/layout/AppLayout.vue')
  assert.match(layout, /const showDatabox = isAdmin \|\| \(auth\.user\?\.role === 'accountant' && accountantHasDatabox\.value\)/)
  assert.match(layout, /showDatabox \? \[\{ to: '\/databox', label: t\('nav\.databox'\), icon: ICONS\.email, tour: 'nav-databox' \}\]/)
  // The list the server returns for the accountant is already limited to her boxes: any box means the item.
  assert.match(layout, /databoxApi\.listReadableBoxes\(\)/)
  assert.match(layout, /accountantHasDatabox\.value = boxes\.length > 0/)
  // A readonly user never asks (the server would refuse), and a fast supplier switch cannot overwrite a newer answer.
  assert.match(layout, /if \(auth\.user\?\.role !== 'accountant'\) \{\s+accountantHasDatabox\.value = false\s+return/)
  assert.match(layout, /requestId === databoxMenuRequest/)
  assert.match(layout, /\(\) => \[auth\.user\?\.role, supplierStore\.currentSupplierId\] as const,/)
})

test('the guided tour waits for the data box menu item and the accountant tour has the step for it', async () => {
  const layout = await read('components/layout/AppLayout.vue')
  assert.match(layout, /menuReady: async \(\) => \{\s+await databoxMenuLoad\s+await nextTick\(\)/)
  const tour = await read('composables/useAppTour.ts')
  assert.match(tour, /menuReady\?: \(\) => Promise<void>/)
  assert.ok(tour.indexOf('host.menuReady') < tour.indexOf('resolveSteps(TOUR_STEPS[audience]'), 'the steps are picked after the menu is ready')

  const step = TOUR_STEPS.accountant.find(s => s.id === 'databox')
  assert.deepEqual(step.targets, ['nav-databox'])
  const find = present => targets => findTourTarget(targets, fakeRoot(present))
  const withBox = resolveSteps(TOUR_STEPS.accountant, find({ 'nav-databox': [true], 'nav-bank': [true] }))
  assert.deepEqual(withBox.map(s => s.id), ['databox', 'bank'])
  const withoutBox = resolveSteps(TOUR_STEPS.accountant, find({ 'nav-bank': [true] }))
  assert.deepEqual(withoutBox.map(s => s.id), ['bank'], 'no visible box, no menu item, no step')
})

test('the data box page is read-only for everybody but the admin', async () => {
  const page = await read('pages/databox/DataboxPage.vue')
  assert.match(page, /const readOnly = computed\(\(\) => auth\.user\?\.role !== 'admin'\)/)
  assert.match(page, /databoxApi\.listReadableBoxes\(\)/)
  // Import, compose and "mark all as handled" are admin actions; so is the link to the settings.
  assert.match(page, /<div v-if="!readOnly" class="flex flex-wrap items-center gap-2">\s*<button v-if="boxes\.length > 0"[^>]*data-test="zfo-import-button"/)
  assert.match(page, /<button v-if="sendableBoxes\.some\(b => b\.is_active\)"[^>]*@click="composing = true"/)
  assert.match(page, /<button v-if="!readOnly && direction === 'received'"[^>]*@click="acknowledgeAll"/)
  assert.match(page, /<template v-if="readOnly">\{\{ t\('databox\.page\.no_boxes_accountant'\) \}\}<\/template>/)
  assert.match(page, /:read-only="readOnly"/)
  assert.match(page, /data-test="read-only-note"/)
})

test('the message detail offers the accountant no way to open, fetch a receipt or mark as handled', async () => {
  const detail = await read('components/databox/MessageDetail.vue')
  assert.match(detail, /defineProps<\{ messageId: number; readOnly\?: boolean \}>\(\)/)
  assert.match(detail, /<div v-if="isReceived && !readOnly" class="space-y-2">/, 'the open block, its settings link and portal link')
  assert.match(detail, /<button v-if="!isReceived && !readOnly"[^>]*@click="loadReceipt">/)
  assert.match(detail, /<button v-if="isReceived && !readOnly && !detail\.acknowledged_at"[^>]*@click="acknowledge">/)
  assert.match(detail, /v-if="readOnly && !detail\.open\.stored"[^>]*data-test="not-opened-read-only"/)
  // Stored files stay downloadable.
  assert.match(detail, /databoxApi\.attachmentUrl\(detail\.id, a\.index\)/)
  assert.match(detail, /<a v-if="detail\.has_zfo" :href="databoxApi\.zfoUrl\(detail\.id\)"/)
})

test('only the settings card of a box (admin page) changes who sees it, after a confirmation', async () => {
  const card = await read('components/databox/BoxCard.vue')
  assert.match(card, /if \(enable && !window\.confirm\(t\('databox\.accountant\.confirm'\)\)\) return/)
  assert.match(card, /databoxApi\.updateBox\(props\.box\.id, \{ accountant_visible: enable \}\)/)
  assert.match(card, /<input type="checkbox"[^>]*:checked="box\.accountant_visible"[^>]*data-test="accountant-visible" @click\.prevent="toggleAccountant"/)
  assert.match(card, /t\('databox\.accountant\.label'\)/)
  assert.equal((await json('i18n/cs.json')).databox.accountant.label, 'Zobrazit účetní')
  const sources = await Promise.all(['pages/databox/DataboxPage.vue', 'components/databox/MessageDetail.vue', 'components/layout/AppLayout.vue'].map(read))
  for (const src of sources) assert.doesNotMatch(src, /accountant_visible/, 'the flag is only changed from the settings card')
  const settings = await read('pages/admin/Settings.vue')
  assert.match(settings, /<DataboxSettings\s*\/>/)
  assert.match(await read('router/index.ts'), /path: 'admin\/settings',[^\n]*adminOnly: true/)
})

test('the accountant dashboard shows the data box card only for an opened box', async () => {
  const view = await read('components/dashboard/AccountantDashboard.vue')
  assert.match(view, /<div v-if="summary\.databox" class="grid[^"]*">\s*<AccountantDataboxCard :databox="summary\.databox" \/>/)
  const card = await read('components/dashboard/AccountantDataboxCard.vue')
  assert.match(card, /data-testid="accountant-databox"/)
  assert.match(card, /t\('accountant_dashboard\.databox\.unhandled', \{ count: databox\.unhandled \}\)/)
  assert.match(card, /v-for="m in databox\.latest"/)
  assert.match(card, /<RouterLink to="\/databox"/)
  assert.match(card, /query: \{ box: String\(m\.databox_id\), message: String\(m\.id\) \}/)
  const api = await read('api/dashboard.ts')
  assert.match(api, /databox: AccountantDatabox \| null/)
})

test('the MyÚčto promotion is for the admin only and its modal is gone', async () => {
  const layout = await read('components/layout/AppLayout.vue')
  assert.equal([...layout.matchAll(/support\.myucto_link/g)].length, 1, 'one promotion button')
  assert.match(layout, /<RouterLink v-if="auth\.user\?\.role === 'admin'" to="\/admin\/upgrade"[\s\S]*?support\.myucto_link/)
  assert.doesNotMatch(layout, /myuctoOpen|MODÁL: MyÚčto|v-else type="button"/)
  assert.doesNotMatch(layout, /support\.myucto_(title|intro|better|paid|highlights|github|cta|ask_admin)/)
  // The admin menu entry stays, and it is inside the admin-only section.
  assert.match(layout, /if \(isAdmin\) \{[\s\S]*?to: '\/admin\/upgrade'[\s\S]*?\n  \}/)
  // Every other mention of the upgrade lives on an admin-only page.
  const router = await read('router/index.ts')
  assert.match(router, /path: 'admin\/upgrade',[^\n]*adminOnly: true/)
})

test('cs and en carry the same new texts, none of the dead promotion texts is left', async () => {
  const [cs, en] = await Promise.all([json('i18n/cs.json'), json('i18n/en.json')])
  assert.deepEqual(keys(cs.databox.accountant).sort(), keys(en.databox.accountant).sort())
  assert.deepEqual(keys(cs.accountant_dashboard.databox).sort(), keys(en.accountant_dashboard.databox).sort())
  for (const k of ['no_boxes_accountant', 'read_only']) assert.ok(cs.databox.page[k] && en.databox.page[k], `page.${k}`)
  assert.ok(cs.databox.detail.not_opened_read_only && en.databox.detail.not_opened_read_only)
  for (const k of ['title', 'unhandled', 'none', 'latest', 'empty', 'open']) assert.ok(cs.accountant_dashboard.databox[k] && en.accountant_dashboard.databox[k], k)
  assert.match(cs.accountant_dashboard.databox.unhandled, /\{count\}/)
  assert.deepEqual(Object.keys(cs.support), Object.keys(en.support))
  assert.ok(cs.support.myucto_link && en.support.myucto_link, 'the admin button keeps its text')
  assert.equal(Object.keys(cs.support).filter(k => k.startsWith('myucto_')).length, 1)
})
