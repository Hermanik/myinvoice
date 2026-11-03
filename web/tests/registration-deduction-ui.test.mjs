import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const root = new URL('../src/', import.meta.url)
const read = (path) => readFile(new URL(path, root), 'utf8')
const readJson = (path) => read(path).then(JSON.parse)

function leafKeys(tree, prefix = '') {
  return Object.entries(tree).flatMap(([k, v]) =>
    v && typeof v === 'object' ? leafKeys(v, `${prefix}${k}.`) : [`${prefix}${k}`])
}

test('the registration deduction page has a route and a nav entry in the taxes section', async () => {
  const router = await read('router/index.ts')
  const layout = await read('components/layout/AppLayout.vue')

  assert.match(router, /path: 'tax\/registration-deductions',\s*name: 'tax-registration-deductions'/)
  assert.match(router, /pages\/tax\/RegistrationDeductions\.vue/)
  assert.match(router, /path: 'tax',\s+name: 'tax-optimizer'/, 'the tax optimizer route stays')
  assert.match(layout, /to: '\/tax\/registration-deductions', label: t\('nav\.registration_deduction'\)/)
})

test('the API module calls the registration deduction endpoints', async () => {
  const api = await read('api/registrationDeductions.ts')

  assert.match(api, /get<RegistrationDeductionOverview>\('\/tax\/registration-deductions'\)/)
  assert.doesNotMatch(api, /reversal-suggestions/, 'the suggestions come with the overview')
  assert.match(api, /suggestions\?: RegistrationDeductionDraft\[\]/)
  assert.match(api, /post<RegistrationDeductionItem>\('\/tax\/registration-deductions'/)
  assert.match(api, /put<RegistrationDeductionItem>\(`\/tax\/registration-deductions\/\$\{id\}`/)
  assert.match(api, /delete<\{ id: number \}>\(`\/tax\/registration-deductions\/\$\{id\}`/)
})

test('the page handles orphaned items, filed returns and deregistration suggestions', async () => {
  const page = await read('pages/tax/RegistrationDeductions.vue')

  assert.match(page, /data-testid="regded-orphaned"/)
  assert.match(page, /data-testid="regded-filed-warning"/)
  assert.match(page, /to="\/reports\/dph"/)
  assert.match(page, /to="\/admin\/settings"/)
  assert.match(page, /event\.suggestions/)
  assert.doesNotMatch(page, /registrationDeductionsApi\.suggestions\(/)
  assert.match(page, /purchaseInvoicesApi\.listGrouped\(/, 'the invoice is picked from a remote search')
  assert.match(page, /<SearchableSelect[^>]*\bremote\b/)
  assert.doesNotMatch(page, /v-model\.number="form\.purchase_invoice_id"/)
  assert.match(page, /kind: 'deregistration'/)
  assert.match(page, /auth\.isAdmin/, 'writes are admin-only')
})

test('the VAT status section links to the agenda and shows a hint after adding a registration', async () => {
  const section = await read('components/settings/VatStatusSettings.vue')
  const api = await read('api/settings.ts')

  assert.match(api, /deduction_event: 'registration' \| 'deregistration' \| null/)
  assert.match(section, /v-if="row\.deduction_event"/)
  assert.match(section, /data-testid="registration-deduction-hint"/)
  assert.match(section, /to="\/tax\/registration-deductions"/)
})

test('the DPH page sends form, d_zjist and reason for the supplementary return', async () => {
  const page = await read('pages/reports/DphPriznaniReport.vue')
  const api = await read('api/reports.ts')

  assert.match(page, /<option value="D">/)
  assert.match(page, /reportsApi\.dphDownloadUrl\([^)]*formParams\.value/)
  assert.match(page, /no_filed_return/)
  assert.match(page, /nothing_changed/)
  assert.match(api, /form,\s*\n?\s*\.\.\.\(dZjist \? \{ d_zjist: dZjist \} : \{\}\)/)
  assert.match(api, /reason/)
  assert.match(api, /dphPreview: .*formParams/)
})

test('the KH page sends c_jed_vyzvy and vyzva_odp for an answer to a call', async () => {
  const page = await read('pages/reports/KontrolniHlaseniReport.vue')
  const api = await read('api/reports.ts')

  assert.match(api, /c_jed_vyzvy/)
  assert.match(api, /vyzva_odp/)
  assert.match(page, /vyzvaOdp: 'P'/)
  assert.match(page, /vyzvaOdp: 'B'/)
  assert.match(api, /interface KhFormParams/)
  assert.match(page, /callMode\.value === 'change' \? 'N'/)
  assert.match(page, /summary\.call\?\.answer != null/, 'a quick answer is detected by its call answer')
  assert.match(page, /isQuickAnswer/, 'sections A/B are hidden for a quick answer')
})

test('the submission archive labels the supplementary and call-answer forms', async () => {
  const page = await read('pages/reports/TaxSubmissions.vue')
  const cs = await readJson('i18n/cs.json')

  assert.match(page, /submissionFormLabel\(s\.submission_form\)/)
  assert.ok(cs.reports.submissions.sform_D && cs.reports.submissions.sform_V)
})

test('cs and en carry the same new keys', async () => {
  const [cs, en] = await Promise.all([readJson('i18n/cs.json'), readJson('i18n/en.json')])
  const pick = (tree) => ({
    regded: tree.regded,
    supplementary: tree.reports.supplementary,
    call: tree.reports.kh.call,
    sform: [tree.reports.submissions.sform_D, tree.reports.submissions.sform_V],
    vatStatus: tree.settings.vat_status,
    nav: tree.nav.registration_deduction,
  })

  assert.deepEqual(leafKeys(pick(cs)).sort(), leafKeys(pick(en)).sort())
  assert.ok(cs.nav.registration_deduction && en.nav.registration_deduction)
  assert.ok(cs.regded.title && en.regded.title)
})
