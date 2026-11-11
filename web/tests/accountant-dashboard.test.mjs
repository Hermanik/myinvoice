import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const root = new URL('../src/', import.meta.url)
const read = path => readFile(new URL(path, root), 'utf8')

const { deadlineTone, periodQuery, monthKey } = await import('../src/utils/accountantDashboard.ts')
const { periodFromQuery } = await import('../src/utils/reportPeriod.ts')

test('the deadline is calm until the last week, amber then, red after it', () => {
  const open = [{ status: 'unfiled' }, { status: 'filed' }]
  assert.equal(deadlineTone(14, open), 'normal')
  assert.equal(deadlineTone(7, open), 'soon')
  assert.equal(deadlineTone(0, open), 'soon')
  assert.equal(deadlineTone(-1, open), 'overdue')
})

test('nothing is chased once every return of the period is filed, even after the deadline', () => {
  assert.equal(deadlineTone(-30, [{ status: 'filed' }, { status: 'confirmed' }]), 'done')
  assert.equal(deadlineTone(-30, []), 'overdue', 'no return known yet is not "done"')
})

test('links carry the period as year and month, the month key as YYYY-MM', () => {
  assert.equal(periodQuery(2026, 10), 'year=2026&month=10')
  assert.equal(monthKey(2026, 3), '2026-03')
  assert.equal(monthKey(2026, 12), '2026-12')
})

test('a report page opens on the linked period, otherwise on the given date', () => {
  const now = new Date(2026, 10, 11)
  assert.deepEqual(periodFromQuery({ year: '2026', month: '10' }, now), { year: 2026, month: 10 })
  assert.deepEqual(periodFromQuery({}, now), { year: 2026, month: 11 })
  assert.deepEqual(periodFromQuery({ year: '2026' }, now), { year: 2026, month: 11 }, 'both parts or none')
  for (const bad of [{ year: '2026', month: '13' }, { year: '2026', month: '0' }, { year: '1999', month: '5' }, { year: 'x', month: '5' }, { year: '2026', month: '1.5' }]) {
    assert.deepEqual(periodFromQuery(bad, now), { year: 2026, month: 11 }, JSON.stringify(bad))
  }
  assert.deepEqual(periodFromQuery({ year: ['2026'], month: ['10'] }, now), { year: 2026, month: 11 }, 'repeated parameters are not a period')
})

test('the export page defaults to the previous month and takes a linked period over it', async () => {
  const page = await read('pages/reports/MonthlyExportReport.vue')
  assert.match(page, /periodFromQuery\(useRoute\(\)\.query, prevMonth\)/)
})

test('all four tax report pages honour the linked period', async () => {
  for (const name of ['DphPriznaniReport', 'KontrolniHlaseniReport', 'DphBookReport', 'SouhrnneHlaseniReport']) {
    const page = await read(`pages/reports/${name}.vue`)
    assert.match(page, /periodFromQuery\(route\.query\)/, name)
    assert.match(page, /ref\(initialPeriod\.year\)/, name)
  }
})

test('the dashboard shows the accountant view only to the accountant and leaves the owner view as it was', async () => {
  const page = await read('pages/Dashboard.vue')
  assert.match(page, /const isAccountant = computed\(\(\) => auth\.user\?\.role === 'accountant'\)/)
  assert.match(page, /<AccountantDashboard v-else-if="isAccountant" \/>/)
  // The supplier gate stays first, and the owner's summary is not even requested for the accountant.
  assert.ok(page.indexOf('v-if="!hasSupplier"') < page.indexOf('<AccountantDashboard'))
  assert.match(page, /if \(isAccountant\.value\) \{\s*loading\.value = false\s*return\s*\}/)
  assert.match(page, /<template v-else>\s*<div v-if="loading"/)
})

test('the accountant dashboard loads one aggregated endpoint and offers the monthly documents', async () => {
  const view = await read('components/dashboard/AccountantDashboard.vue')
  assert.match(view, /dashboardApi\.accountant\(\)/)
  assert.match(view, /\/reports\/monthly-export\?\$\{periodQuery\(/)
  assert.match(view, /data-tour="dashboard-content"/)
  const api = await read('api/dashboard.ts')
  assert.match(api, /api\.get<AccountantSummary>\('\/dashboard\/accountant'\)/)
})

test('every widget has a sentence for the empty state', async () => {
  const messages = JSON.parse(await read('i18n/cs.json')).accountant_dashboard
  assert.equal(typeof messages.vat.not_payer, 'string')
  assert.equal(typeof messages.vat.not_yet_payer, 'string')
  assert.equal(typeof messages.obligations.empty, 'string')
  assert.equal(typeof messages.no_documents, 'string')
  assert.equal(typeof messages.issued.ok, 'string')
  assert.equal(typeof messages.purchase.ok, 'string')
  const card = await read('components/dashboard/AccountantMonthsCard.vue')
  assert.match(card, /data-testid="months-empty"/)
  const obligations = await read('components/dashboard/AccountantObligationsCard.vue')
  assert.match(obligations, /data-testid="accountant-obligations-empty"/)
})

test('the cs and en dashboard texts have the same keys', async () => {
  const keys = (node, prefix = '') => Object.entries(node).flatMap(([k, v]) =>
    typeof v === 'object' && v !== null ? keys(v, `${prefix}${k}.`) : [`${prefix}${k}`])
  for (const block of ['tour', 'accountant_dashboard']) {
    const cs = JSON.parse(await read('i18n/cs.json'))[block]
    const en = JSON.parse(await read('i18n/en.json'))[block]
    assert.deepEqual(keys(en).sort(), keys(cs).sort(), block)
  }
})
