import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const {
  addDays, presetRange, matchingPreset, matchesStatus, filterItems, countByCategory, buildRows, dateFormatters,
  statusOf, coveredByOrder, textParams, formatParams, safeLink, isYmd, TIMELINE_CATEGORIES, STANDING_ORDER_TYPES,
} = await import('../src/utils/timeline.ts')

const root = new URL('../src/', import.meta.url)
const apiRoot = new URL('../../api/', import.meta.url)
const read = (path) => readFile(new URL(path, root), 'utf8')
const readApi = (path) => readFile(new URL(path, apiRoot), 'utf8')
const readJson = (path) => read(path).then(JSON.parse)

function leafKeys(tree, prefix = '') {
  return Object.entries(tree).flatMap(([k, v]) =>
    v && typeof v === 'object' && !Array.isArray(v) ? leafKeys(v, `${prefix}${k}.`) : [`${prefix}${k}`])
}

const item = (over) => ({
  key: 'dph:2026-11', type: 'dph', category: 'dph', due_date: '2026-12-28', start_date: null, days_left: 49,
  status: 'upcoming', done_source: null, tracked: true, obligation: 'legal', severity: 'normal', info: false,
  params: {}, notes: [], legal: null, amount: null, amount_estimated: false, links: [], mark: null, ...over,
})

// ── presets and the date range ────────────────────────────────────────────

test('the presets cover the default range and the other three', () => {
  const today = '2026-11-09'
  assert.deepEqual(presetRange('rest_and_next', today), { from: '2026-10-10', to: '2027-12-31' })
  assert.deepEqual(presetRange('next_90', today), { from: '2026-11-09', to: '2027-02-07' })
  assert.deepEqual(presetRange('this_year', today), { from: '2026-01-01', to: '2026-12-31' })
  assert.deepEqual(presetRange('next_year', today), { from: '2027-01-01', to: '2027-12-31' })
})

test('the active preset is recognised from the range, a custom range matches none', () => {
  const today = '2026-11-09'
  assert.equal(matchingPreset('2026-10-10', '2027-12-31', today), 'rest_and_next')
  assert.equal(matchingPreset('2027-01-01', '2027-12-31', today), 'next_year')
  assert.equal(matchingPreset('2026-11-01', '2027-12-31', today), null)
})

test('date arithmetic stays on calendar days across a daylight-saving change', () => {
  assert.equal(addDays('2026-03-28', 2), '2026-03-30')
  assert.equal(addDays('2026-10-24', 2), '2026-10-26')
  assert.equal(addDays('2026-01-01', -1), '2025-12-31')
  assert.ok(isYmd('2026-02-28'))
  assert.ok(!isYmd('2026-02-30') && !isYmd('') && !isYmd('2026-2-3'))
})

// ── filters ───────────────────────────────────────────────────────────────

test('"to do" is everything that is not done, "overdue" only what is past its date', () => {
  const statuses = ['done', 'overdue', 'due_soon', 'upcoming', 'info', 'unverified']
  const by = (f) => statuses.filter(status => matchesStatus({ status }, f))
  assert.deepEqual(by('all'), statuses)
  assert.deepEqual(by('todo'), ['overdue', 'due_soon', 'upcoming', 'info', 'unverified'])
  assert.deepEqual(by('done'), ['done'])
  assert.deepEqual(by('overdue'), ['overdue'])
})

test('category chips combine with the status filter; none selected means all', () => {
  const items = [
    item({ key: 'a', category: 'dph', status: 'done' }),
    item({ key: 'b', category: 'dph', status: 'upcoming' }),
    item({ key: 'c', category: 'insurance', status: 'upcoming' }),
  ]
  assert.deepEqual(filterItems(items, 'all', []).map(i => i.key), ['a', 'b', 'c'])
  assert.deepEqual(filterItems(items, 'todo', ['dph']).map(i => i.key), ['b'])
  assert.deepEqual(filterItems(items, 'all', ['dph', 'insurance']).map(i => i.key), ['a', 'b', 'c'])
  assert.deepEqual(countByCategory(items), { dph: 2, insurance: 1 })
})

// ── the vertical timeline: month headings and the "today" marker ──────────

const kinds = (rows) => rows.map(r => r.kind === 'item' ? r.item.key : r.kind === 'month' ? `M${r.ym}` : 'TODAY')

test('rows group the items by month and put the today marker where today falls', () => {
  const items = [
    item({ key: 'a', due_date: '2026-11-03' }),
    item({ key: 'b', due_date: '2026-11-25' }),
    item({ key: 'c', due_date: '2026-12-28' }),
  ]
  assert.deepEqual(kinds(buildRows(items, '2026-11-09', '2026-10-10', '2027-12-31')), [
    'M2026-11', 'a', 'TODAY', 'b', 'M2026-12', 'c',
  ])
})

test('the marker sits between months when today has no item of its own month', () => {
  const items = [item({ key: 'a', due_date: '2026-10-26' }), item({ key: 'c', due_date: '2026-12-28' })]
  assert.deepEqual(kinds(buildRows(items, '2026-11-09', '2026-10-10', '2027-12-31')), [
    'M2026-10', 'a', 'TODAY', 'M2026-12', 'c',
  ])
})

test('the marker closes the list when everything is already past, and is left out of a range without today', () => {
  const past = [item({ key: 'a', due_date: '2026-10-26' })]
  assert.deepEqual(kinds(buildRows(past, '2026-11-09', '2026-10-10', '2026-12-31')), ['M2026-10', 'a', 'TODAY'])
  assert.deepEqual(kinds(buildRows(past, '2026-11-09', '2027-01-01', '2027-12-31')), ['M2026-10', 'a'])
  assert.deepEqual(buildRows([], '2026-11-09', '2026-10-10', '2026-12-31'), [{ kind: 'today' }])
})

test('an item due today comes after the marker', () => {
  const items = [item({ key: 'a', due_date: '2026-11-09' })]
  assert.deepEqual(kinds(buildRows(items, '2026-11-09', '2026-11-01', '2026-11-30')), ['M2026-11', 'TODAY', 'a'])
})

// ── status after a mark (mirror of the server rule) ───────────────────────

test('statusOf follows the server rule', () => {
  const base = { tracked: true, info: false }
  assert.equal(statusOf({ ...base, days_left: -3 }, true), 'done')
  assert.equal(statusOf({ ...base, days_left: -3 }, false), 'overdue')
  assert.equal(statusOf({ ...base, days_left: 0 }, false), 'due_soon')
  assert.equal(statusOf({ ...base, days_left: 7 }, false), 'due_soon')
  assert.equal(statusOf({ ...base, days_left: 8 }, false), 'upcoming')
  assert.equal(statusOf({ tracked: true, info: true, days_left: -50 }, false), 'info')
  // Without evidence a long-past item is "unverified", not overdue; the 30-day boundary is inclusive of overdue.
  assert.equal(statusOf({ tracked: false, info: false, days_left: -30 }, false), 'overdue')
  assert.equal(statusOf({ tracked: false, info: false, days_left: -31 }, false), 'unverified')
  assert.equal(statusOf({ tracked: true, info: false, days_left: -400 }, false), 'overdue')
})

test('an item a standing order is going to pay is upcoming however close its date, and done once paid', () => {
  const covered = { tracked: true, info: false, params: { paid_on: '2026-12-12' } }
  assert.ok(coveredByOrder(covered))
  assert.ok(!coveredByOrder({ params: { year: 2026 } }) && !coveredByOrder({}))
  assert.equal(statusOf({ ...covered, days_left: 3 }, false), 'upcoming', 'not due_soon: the order pays it');
  assert.equal(statusOf({ ...covered, days_left: 3 }, true), 'done')
  assert.equal(statusOf({ tracked: true, info: false, days_left: 3, params: {} }, false), 'due_soon', 'without an order the rule is the usual one')
})

// ── text parameters ───────────────────────────────────────────────────────

const MONTHS = ['leden', 'únor', 'březen', 'duben', 'květen', 'červen', 'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec']
const fmt = {
  date: (s) => `D(${s})`,
  money: (n) => `${n} Kč`,
  month: (ym) => `${MONTHS[Number(ym.slice(5, 7)) - 1]} ${ym.slice(0, 4)}`,
  quarter: (q, y) => `${q}. čtvrtletí ${y}`,
  claim: (c) => `claim:${c}`,
}

test('period, month and claim texts are derived from the params', () => {
  const monthly = textParams(item({ params: { period: 'monthly', year: 2026, month: 11, quarter: null } }), fmt)
  assert.equal(monthly.period_text, 'listopad 2026')
  const quarterly = textParams(item({ params: { period: 'quarterly', year: 2026, month: null, quarter: 4 } }), fmt)
  assert.equal(quarterly.period_text, '4. čtvrtletí 2026')

  const advance = textParams(item({ params: { period: '2027-02', year: 2027, month: 2 } }), fmt)
  assert.equal(advance.month_text, 'únor 2027')
  assert.equal(textParams(item({ params: { period: '2026-10', count: 0 } }), fmt).month_text, 'říjen 2026')
  assert.equal(textParams(item({ params: { year: 2026, month: 11, band: 'band1' } }), fmt).band_no, '1')

  const docs = textParams(item({ params: { year: 2026, claims: ['mortgage', 'children'] } }), fmt)
  assert.equal(docs.claims_text, 'claim:mortgage, claim:children')
  assert.equal(docs.next_year, 2027)
})

test('dates and amounts are formatted, other values are left alone', () => {
  const p = textParams(item({ due_date: '2027-01-07', params: {
    exceeded_on: '2026-11-30', payer_from: '2027-01-01', limit: 2000000, turnover: 2100000.5, year: 2026, share: 25, form: 'dppdp9',
  } }), fmt)
  assert.equal(p.exceeded_on, 'D(2026-11-30)')
  assert.equal(p.payer_from, 'D(2027-01-01)')
  assert.equal(p.limit, '2000000 Kč')
  assert.equal(p.turnover, '2100000.5 Kč')
  assert.equal(p.share, 25, 'a share is not money')
  assert.equal(p.year, 2026, 'neither is a year')
  assert.equal(p.due, 'D(2027-01-07)')
  assert.equal(p.form_label, 'DPPO')
})

test('the amounts of the estimates and the standing orders are formatted as money, also in the params of a note', () => {
  const p = formatParams({ income: 2250000, paid: 2100000, unpaid: 150000, paid_amount: 9984, diff: 822, paid_on: '2026-10-12', year: 2026, base_year: 2026, working_day: 3, estimate: { year: 2026 } }, fmt)

  assert.deepEqual([p.income, p.paid, p.unpaid, p.paid_amount, p.diff], ['2250000 Kč', '2100000 Kč', '150000 Kč', '9984 Kč', '822 Kč'])
  assert.equal(p.paid_on, 'D(2026-10-12)')
  assert.deepEqual([p.year, p.base_year, p.working_day], [2026, 2026, 3], 'years and counts are not money')
  assert.deepEqual(p.estimate, { year: 2026 }, 'a nested estimate is left to its own component')
})

test('date formatters are built once per language and use the calendar day in UTC', () => {
  assert.equal(dateFormatters('cs'), dateFormatters('cs'))
  assert.notEqual(dateFormatters('cs'), dateFormatters('en'))
  assert.equal(dateFormatters('cs').dayMonth.format(new Date('2026-10-12T00:00:00Z')), '12. 10.', 'the day of a standing order payment')
  const d = new Date('2026-12-28T00:00:00Z')
  assert.match(dateFormatters('en').weekday.format(d), /Monday/)
  assert.match(dateFormatters('cs').weekday.format(d), /pondělí/)
  assert.deepEqual([...TIMELINE_CATEGORIES], ['registration', 'dph', 'income_tax', 'pausal', 'insurance', 'invoicing', 'custom'])
})

test('a custom link is shown only as an in-app path or an http(s) address', () => {
  assert.deepEqual(safeLink('/clients'), { kind: 'internal', href: '/clients' })
  assert.deepEqual(safeLink('https://example.com/a?b=1'), { kind: 'external', href: 'https://example.com/a?b=1' })
  for (const bad of ['javascript:alert(1)', '//evil.example', '/\\evil.example', 'ftp://x', 'data:text/html,x', '', null, undefined, 'clients']) {
    assert.equal(safeLink(bad), null, String(bad))
  }
})

// ── wiring: route, menu, API module, page ─────────────────────────────────

test('the timeline has a route for admin and accountant and a menu entry in the taxes section', async () => {
  const router = await read('router/index.ts')
  const layout = await read('components/layout/AppLayout.vue')

  assert.match(router, /path: 'timeline',\s+name: 'timeline',\s+component: \(\) => import\('@\/pages\/timeline\/TimelinePage\.vue'\), meta: \{ requiresWrite: true/)
  assert.match(layout, /canWrite \? \[\{ to: '\/timeline', label: t\('nav\.timeline'\), icon: ICONS\.timeline, tour: 'nav-timeline' \}\] : \[\]/)
  // The entry sits in the taxes section, before the reports, so it can be starred like any other item.
  const taxes = layout.slice(layout.indexOf("t('nav.section_taxes')"))
  assert.ok(taxes.indexOf("to: '/timeline'") < taxes.indexOf("to: '/reports/dph'"))
  assert.match(layout, /function canStar[\s\S]*?!item\.external && item\.to !== '\/'/)
})

test('the API module calls the timeline endpoints', async () => {
  const api = await read('api/timeline.ts')

  assert.match(api, /get<Timeline>\('\/timeline', \{ params: \{ from, to \} \}\)/)
  assert.match(api, /put<\{ key: string; mark: TimelineMark \| null \}>\('\/timeline\/marks', \{ key, done, note \}\)/)
  assert.match(api, /delete<\{ key: string; mark: null \}>\('\/timeline\/marks', \{ params: \{ key \} \}\)/)
  assert.match(api, /post<TimelineCustomItem>\('\/timeline\/custom-items', body\)/)
  assert.match(api, /put<TimelineCustomItem>\(`\/timeline\/custom-items\/\$\{id\}`/)
  assert.match(api, /delete<\{ id: number \}>\(`\/timeline\/custom-items\/\$\{id\}`/)
  assert.match(api, /get<\{ items: StandingOrder\[\] \}>\('\/timeline\/standing-orders'\)/)
  assert.match(api, /post<StandingOrder>\('\/timeline\/standing-orders', body\)/)
  assert.match(api, /put<StandingOrder>\(`\/timeline\/standing-orders\/\$\{id\}`, body\)/)
  assert.match(api, /delete<\{ id: number \}>\(`\/timeline\/standing-orders\/\$\{id\}`/)
  assert.match(api, /put<TimelineSettings>\('\/timeline\/settings', body\)/)
})

test('the page loads once per range change and offers presets, filters, the today marker and the custom task form', async () => {
  const page = await read('pages/timeline/TimelinePage.vue')

  assert.match(page, /watch\(\[from, to\], \(\) => \{ void load\(\) \}\)/, 'one request per range change')
  assert.equal((page.match(/timelineApi\.get\(/g) ?? []).length, 1)
  assert.match(page, /if \(mine !== seq\) return/, 'a late answer of an older range is dropped')
  for (const id of ['timeline-range-from', 'timeline-range-to', 'timeline-add', 'timeline-today', 'timeline-form', 'timeline-counts']) {
    assert.match(page, new RegExp(`data-testid="${id}"`), id)
  }
  assert.match(page, /'timeline-preset-' \+ p/)
  assert.match(page, /'timeline-status-' \+ f/)
  assert.match(page, /'timeline-category-' \+ c/)
  assert.match(page, /RANGE_PRESETS/)
  assert.match(page, /buildRows\(visible\.value, today\.value, from\.value, to\.value\)/)
  // The server's `today` is the reference of the marker, not the browser clock.
  assert.match(page, /today\.value = res\.today/)
})

test('manual marks go through the API and automatic evidence wins over them', async () => {
  const page = await read('pages/timeline/TimelinePage.vue')
  const card = await read('components/timeline/TimelineItemCard.vue')

  assert.match(page, /timelineApi\.setMark\(key, done, note\)/)
  assert.match(page, /timelineApi\.clearMark\(key\)/)
  assert.match(page, /timelineApi\.updateCustom\(item\.custom\.id, \{ done \}\)/)
  assert.match(page, /if \(i\.done_source !== 'auto' && i\.done_source !== 'standing_order'\)/, 'a standing order that paid the item is evidence too')
  assert.match(card, /<span v-if="autoDone"[^>]*data-testid="timeline-done-auto"/)
  assert.match(card, /data-testid="timeline-done-auto"/)
  assert.match(card, /data-testid="timeline-done-checkbox"/)
  assert.match(card, /safeLink\(custom\.value\?\.url\)/)
  assert.match(card, /rel="noopener noreferrer"/)
})

test('the page opens the regular payments and shows the estimates of the years without the flat-tax regime', async () => {
  const page = await read('pages/timeline/TimelinePage.vue')
  const modal = await read('components/timeline/StandingOrdersModal.vue')
  const card = await read('components/timeline/TimelineItemCard.vue')

  assert.match(page, /data-testid="timeline-orders"/)
  assert.match(page, /<StandingOrdersModal v-if="ordersOpen && data" :settings="data\.settings"[^>]*@changed="load\(true\)"/, 'a change reloads the timeline')
  assert.match(page, /<TimelineEstimate v-for="e in data\?\.estimates \?\? \[\]"[^>]*compact/, 'the summary block at the top')
  // The card shows the full estimate on the lost flat-tax item, and "paid by standing order" instead of a checkbox prompt.
  assert.match(card, /item\.type === 'flat_tax_lost'/)
  assert.match(card, /<span v-else-if="paidByOrder"[^>]*data-testid="timeline-done-order"/)
  assert.match(card, /t\('timeline\.paid_by_order', \{ date: paidOn \}\)/)
  assert.match(card, /<label v-else-if="!ordered \|\| isDone"/, 'nothing to tick on an item an order is going to pay')
  // The modal: the four payment types, the CRUD calls and the settings of the invoicing deadline.
  for (const call of ['listOrders', 'createOrder', 'updateOrder', 'removeOrder', 'saveSettings']) {
    assert.match(modal, new RegExp(`timelineApi\\.${call}\\(`), call)
  }
  for (const id of ['timeline-orders-modal', 'timeline-order-form', 'timeline-order-save', 'timeline-settings-due', 'timeline-settings-save']) {
    assert.match(modal, new RegExp(`data-testid="${id}"`), id)
  }
  assert.match(modal, /v-for="type in STANDING_ORDER_TYPES"/)
  assert.match(modal, /day < 1 \|\| day > 31/)
})

test('the estimate shows the three components with the surcharge and the due date, and the cash to reserve', async () => {
  const box = await read('components/timeline/TimelineEstimate.vue')
  const card = await read('components/timeline/TimelineItemCard.vue')

  assert.match(box, /const ROWS = \['income_tax', 'social', 'health'\] as const/)
  assert.match(box, /Math\.max\(0, props\.estimate\[row\]\.surcharge\)/, 'an overpayment is not an amount to reserve')
  assert.match(box, /estimate\[row\]\.surcharge < 0 \? t\('timeline\.estimate_box\.overpaid'/)
  for (const id of ['timeline-estimate-income', 'timeline-estimate-surcharge', 'timeline-estimate-reserve', 'timeline-estimate-standing']) {
    assert.match(box, new RegExp(`data-testid="${id}"`), id)
  }
  // The text variant of an invoicing item follows the deadline rule of the settings.
  assert.match(card, /params\.reason \?\? props\.item\.params\.frequency \?\? props\.item\.params\.rule/)
})

// ── catalogue ─────────────────────────────────────────────────────────────

test('the standing order types and the settings have a Czech and an English text', async () => {
  const [cs, en] = await Promise.all([readJson('i18n/cs.json'), readJson('i18n/en.json')])
  for (const catalogue of [cs, en]) {
    assert.deepEqual(Object.keys(catalogue.timeline.order_types).sort(), [...STANDING_ORDER_TYPES].sort())
    assert.ok(catalogue.timeline.item.invoicing.what_month_end && catalogue.timeline.item.invoicing.what_working_day_n)
  }
  assert.deepEqual(
    [cs.timeline.order_types.pausal, cs.timeline.order_types.insurance_social, cs.timeline.order_types.insurance_health, cs.timeline.order_types.income_tax_advance],
    ['Paušální daň', 'Sociální pojištění – záloha', 'Zdravotní pojištění – záloha', 'Záloha na daň z příjmů'],
  )
  assert.match(cs.timeline.note.invoicing_vat_payer, /do 15 dnů ode dne uskutečnění plnění \(§ 28 odst\. 4 ZDPH\)/)
  assert.match(cs.timeline.note.pausal_regime_lost, /^Paušální režim za \{year\} neplatí – zálohy plaťte dál, v přiznání a přehledech se započtou/)
})

test('cs and en carry the same timeline keys', async () => {
  const [cs, en] = await Promise.all([readJson('i18n/cs.json'), readJson('i18n/en.json')])
  assert.deepEqual(leafKeys(cs.timeline).sort(), leafKeys(en.timeline).sort())
  assert.ok(cs.nav.timeline && en.nav.timeline)
  assert.equal(cs.nav.timeline, 'Harmonogram')
})

test('the catalogue has a title for every item type the API documents, and no title the API never sends', async () => {
  const [cs, spec] = await Promise.all([readJson('i18n/cs.json'), readApi('openapi.yaml')])
  const typeEnum = spec.match(/TimelineItem:[\s\S]*?type:\s*\n\s*type: string[\s\S]*?enum: \[([^\]]*)\]/)
  assert.ok(typeEnum, 'the OpenAPI item type enum')
  const types = typeEnum[1].split(',').map(s => s.trim()).filter(Boolean).filter(t => t !== 'custom')
  assert.ok(types.length >= 20)
  assert.deepEqual(Object.keys(cs.timeline.item).sort(), [...types].sort())
  for (const type of types) {
    assert.ok(cs.timeline.item[type].title, `${type} has a title`)
    const hasWhat = cs.timeline.item[type].what || Object.keys(cs.timeline.item[type]).some(k => k.startsWith('what_'))
    assert.ok(hasWhat, `${type} says what to do`)
  }
})

test('every note code and link label the server can send has a text', async () => {
  const cs = await readJson('i18n/cs.json')
  const sources = await Promise.all(['TimelineItem', 'ObligationTimelineService', 'InsuranceItems', 'IncomeTaxItems', 'VatRegistrationItems', 'StandingOrders', 'TaxYearEstimates']
    .map(f => readApi(`src/Service/Timeline/${f}.php`)))
  const php = sources.join('\n')

  for (const code of Object.keys(cs.timeline.note)) {
    assert.ok(php.includes(`'${code}'`), `note ${code} is sent by the server`)
  }
  const labels = readApiLinkLabels(sources[0])
  assert.ok(labels.length >= 9)
  for (const label of labels) {
    assert.ok(cs.timeline.link[label], `link ${label} has a text`)
  }
  assert.deepEqual(Object.keys(cs.timeline.link).sort(), [...labels].sort())
})

/** The label codes of TimelineItem::LINKS (`'dph' => ...,`). */
function readApiLinkLabels(php) {
  const block = php.match(/private const LINKS = \[([\s\S]*?)\n    \];/)
  assert.ok(block, 'TimelineItem::LINKS')
  return [...block[1].matchAll(/^\s*'([a-z_]+)'\s*=>/gm)].map(m => m[1])
}
