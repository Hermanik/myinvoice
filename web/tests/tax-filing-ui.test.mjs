import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const root = new URL('../src/', import.meta.url)
const read = (path) => readFile(new URL(path, root), 'utf8')
const readJson = async (path) => JSON.parse(await read(path))

const { submissionPeriodLabel, nowForDatetimeLocal } = await import('../src/utils/taxFiling.ts')

test('archive period label: month, quarter, year', () => {
  assert.equal(submissionPeriodLabel({ period_year: 2027, period_month: 1, period_quarter: null }), '2027-01')
  assert.equal(submissionPeriodLabel({ period_year: 2027, period_month: 12, period_quarter: null }), '2027-12')
  assert.equal(submissionPeriodLabel({ period_year: 2027, period_month: null, period_quarter: 2 }), '2027 Q2')
  assert.equal(submissionPeriodLabel({ period_year: 2026, period_month: null, period_quarter: null }), '2026')
})

test('the filing time defaults to "now" in the local time zone the datetime-local input expects', () => {
  assert.equal(nowForDatetimeLocal(new Date(2027, 1, 24, 9, 5)), '2027-02-24T09:05')
  assert.equal(nowForDatetimeLocal(new Date(2027, 11, 3, 23, 59)), '2027-12-03T23:59')
})

test('the archive page offers the filing lifecycle: mark filed (time, channel, reference), receipt upload, download', async () => {
  const page = await read('pages/reports/TaxSubmissions.vue')

  assert.match(page, /taxFilingApi\.markFiled\(/)
  assert.match(page, /taxFilingApi\.uploadReceipt\(/)
  assert.match(page, /taxFilingApi\.receiptUrl\(/, 'the receipt is downloadable')
  assert.match(page, /v-model="fileForm\.filed_at" type="datetime-local"/, 'editable date + time')
  assert.match(page, /fileForm\.filed_at = nowForDatetimeLocal\(\)/, 'defaulting to now')
  for (const channel of ['epo', 'data_box', 'other']) {
    assert.match(page, new RegExp(`<option value="${channel}">`), `channel ${channel}`)
  }
  assert.match(page, /v-model="fileForm\.reference"/, 'optional submission number')
  assert.match(page, /accept="\.p7s,\.pdf,\.zfo"/, 'receipt types: EPO .p7s, PDF, ZFO')
  assert.match(page, /filing_status/, 'a state badge per row')
})

test('writes on the archive page are admin-only', async () => {
  const page = await read('pages/reports/TaxSubmissions.vue')

  assert.match(page, /const isAdmin = computed\(\(\) => auth\.user\?\.role === 'admin'\)/)
  // Every state-changing control sits behind isAdmin.
  assert.match(page, /<template v-if="isAdmin">\s*<button v-if="s\.filing_status === 'generated'"[^>]*data-testid="mark-filed"/)
  assert.match(page, /data-testid="upload-receipt"/)
  assert.match(page, /<template v-if="isAdmin">[\s\S]*data-testid="unfile"/)
  assert.match(page, /:can-write="isAdmin"/, 'the payment card gets the same flag')
  // A filed submission cannot be deleted from the list (the server refuses as well).
  assert.match(page, /v-if="isAdmin && s\.filing_status === 'generated'" type="button" @click="deleteItem/)
})

test('a duplicate filing is refused with the server message, not swallowed', async () => {
  const page = await read('pages/reports/TaxSubmissions.vue')

  assert.match(page, /catch \(e\) \{[^}]*toast\.error\(apiErrorMessage\(e\)\)/s)
  assert.match(page, /Duplicate|duplicate/, 'the handler documents the 409 case')
})

test('the DPH payment card shows amount, due date, account, variable symbol and QR; refund instead of payment', async () => {
  const card = await read('components/reports/VatPaymentCard.vue')

  for (const id of ['vat-payment-amount', 'vat-payment-account', 'vat-payment-vs', 'vat-payment-qr']) {
    assert.match(card, new RegExp(`data-testid="${id}"`), id)
  }
  assert.match(card, /payment\.kind === 'refund'/, 'the excess deduction is a refund')
  assert.match(card, /payment\.kind === 'payment'/)
  assert.match(card, /taxFilingApi\.markPaid\(/)
  assert.match(card, /taxFilingApi\.clearPaid\(/)
  assert.match(card, /v-if="canWrite"/)
})

test('the dashboard feed item links to the payment card of the archive row', async () => {
  const page = await read('pages/reports/TaxSubmissions.vue')

  assert.match(page, /#vat-payment-\(\\d\+\)/, 'the page opens the row named by the hash')
  assert.match(await read('components/reports/VatPaymentCard.vue'), /:id="`vat-payment-\$\{payment\.submission_id\}`"/)
})

test('DPH, KH and SH pages show the consistency check before the XML download', async () => {
  for (const [file, form] of [['DphPriznaniReport', 'dphdp3'], ['KontrolniHlaseniReport', 'dphkh1'], ['SouhrnneHlaseniReport', 'dphshv']]) {
    const page = await read(`pages/reports/${file}.vue`)
    assert.match(page, new RegExp(`<VatPrecheckPanels form="${form}" :year="year" :month="month" :period="effectivePeriod" />`), file)
    // inside the preview branch, i.e. next to the download button's data
    assert.ok(page.indexOf('<VatPrecheckPanels') > page.indexOf('v-else-if="preview"'), `${file}: rendered with the preview`)
  }
  const panels = await read('components/reports/VatPrecheckPanels.vue')
  assert.match(panels, /taxFilingApi\.vatChecks\(/)
  assert.match(panels, /<VatCrossCheckPanel/)
  assert.match(panels, /<ChangedAfterFilingList/)
  assert.match(panels, /<VatPaymentCard/)
  assert.match(panels, /watch\(\(\) => \[props\.form, props\.year, props\.month, props\.period\], load\)/, 'reloads when the period changes')
})

test('the cross-check lists mismatches with amounts on both sides and the documents behind them', async () => {
  const panel = await read('components/reports/VatCrossCheckPanel.vue')

  assert.match(panel, /c\.dph\.base/)
  assert.match(panel, /c\.other\.base/)
  assert.match(panel, /c\.diff\.base/)
  assert.match(panel, /data-testid="cross-check-documents"/)
  assert.match(panel, /:to="documentLink\(\{ source: d\.source, id: d\.invoice_id \}\)"/)
})

test('"documents changed after filing" carries the corrective-filing hint', async () => {
  const list = await read('components/reports/ChangedAfterFilingList.vue')
  const [cs, en] = await Promise.all([readJson('i18n/cs.json'), readJson('i18n/en.json')])

  assert.match(list, /data-testid="changed-after-filing-hint"/)
  assert.match(cs.reports.changes_after_filing.hint, /opravné/)
  assert.match(cs.reports.changes_after_filing.hint, /dodatečné/)
  assert.match(en.reports.changes_after_filing.hint, /corrective/)
  for (const type of ['created', 'issued', 'edited', 'cancelled', 'deleted']) {
    assert.ok(cs.reports.changes_after_filing[`change_${type}`], type)
  }
})

test('the dashboard has the tax calendar with date, days left and a filed/unfiled badge', async () => {
  const dashboard = await read('pages/Dashboard.vue')
  const widget = await read('components/dashboard/TaxCalendarWidget.vue')

  assert.match(dashboard, /<TaxCalendarWidget \/>/)
  assert.match(widget, /taxFilingApi\.taxCalendar\(/)
  assert.match(widget, /formatDate\(i\.due_date\)/)
  assert.match(widget, /i\.days_left/)
  assert.match(widget, /data-testid="tax-calendar-badge"/)
  assert.match(widget, /v-if="shown\.length"/, 'self-gating without deadlines')
  assert.match(widget, /:to="i\.link"/, 'every item links to its page')
})

test('cs and en carry the same keys for every new section', async () => {
  const [cs, en] = await Promise.all([readJson('i18n/cs.json'), readJson('i18n/en.json')])
  const sections = [
    [cs.reports.filing, en.reports.filing], [cs.reports.vat_payment, en.reports.vat_payment],
    [cs.reports.cross_check, en.reports.cross_check], [cs.reports.changes_after_filing, en.reports.changes_after_filing],
    [cs.dashboard.tax_calendar, en.dashboard.tax_calendar],
  ]
  for (const [a, b] of sections) {
    assert.ok(Object.keys(a).length > 3)
    assert.deepEqual(Object.keys(a).sort(), Object.keys(b).sort())
  }
})

test('every cross-check id the server can send has a label', async () => {
  const cs = await readJson('i18n/cs.json')
  const ids = ['output_21', 'output_12', 'rc_supplier', 'rc_recipient_21', 'rc_recipient_12', 'input_21', 'input_12', 'a2', 'eu_goods', 'eu_services', 'eu_triangular']
  for (const id of ids) assert.ok(cs.reports.cross_check[`check_${id}`], id)
  for (const p of ['missing_in_kh', 'extra_in_kh', 'missing_in_sh']) assert.ok(cs.reports.cross_check[`problem_${p}`], p)
  for (const r of ['identified_person', 'not_vat_payer_in_period', 'build_failed']) assert.ok(cs.reports.cross_check[`not_applicable_${r}`], r)
})

test('the held approval shows its own toast', async () => {
  const detail = await read('pages/invoices/InvoiceDetail.vue')
  assert.match(detail, /r\.auto_send\?\.held/)
  assert.match(detail, /invoice\.approval\.approved_held/)
})
