import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const root = new URL('../src/', import.meta.url)
const read = (path) => readFile(new URL(path, root), 'utf8')

test('the settings page replaces the VAT checkboxes with the dated history section', async () => {
  const settings = await read('pages/admin/Settings.vue')

  assert.match(settings, /<VatStatusSettings\b/, 'section component is rendered')
  assert.doesNotMatch(settings, /v-model="supplier\.is_vat_payer"/, 'the global checkbox is gone')
  assert.doesNotMatch(settings, /v-model="supplier\.is_identified"/, 'the identified-person checkbox is gone')
  // The supplier form must not post the legacy flags (they would be translated into a new history row on every save).
  assert.doesNotMatch(settings, /is_vat_payer:\s*supplier\.value\.is_vat_payer/)
  assert.doesNotMatch(settings, /is_identified:\s*supplier\.value\.is_identified/)
})

test('the section offers add and delete with a toast and refreshes the supplier store', async () => {
  const section = await read('components/settings/VatStatusSettings.vue')

  assert.match(section, /settingsApi\.addVatStatus\(/)
  assert.match(section, /settingsApi\.deleteVatStatus\(/)
  assert.match(section, /toast\.success\(t\('settings\.vat_status\.added'\)\)/)
  assert.match(section, /toast\.success\(t\('settings\.vat_status\.deleted'\)\)/)
  assert.match(section, /emit\('changed'/, 'the parent syncs the supplier store')
  assert.match(section, /v-if="row\.can_delete"/, 'the earliest row has no delete button')
  assert.match(section, /badge_future/, 'future rows are marked')
})

test('the invoice editor and the recurring form decide the VAT UI by the status on the date, not by the global flag', async () => {
  for (const file of ['pages/invoices/InvoiceEditor.vue', 'pages/recurring/RecurringForm.vue', 'pages/invoices/InvoiceDetail.vue', 'components/modals/WorkReportModal.vue']) {
    const source = await read(file)
    assert.match(source, /supplierStore\.vatFlagsAt\(/, `${file} resolves the status from the dated history`)
    assert.doesNotMatch(source, /currentSupplier\?\.is_vat_payer/, `${file} must not read today's flag`)
    assert.doesNotMatch(source, /currentSupplier\?\.is_identified/, `${file} must not read today's flag`)
  }
})

test('cs and en carry the same VAT status strings', async () => {
  const [cs, en] = await Promise.all([read('i18n/cs.json'), read('i18n/en.json')].map(p => p.then(JSON.parse)))

  assert.deepEqual(Object.keys(cs.settings.vat_status).sort(), Object.keys(en.settings.vat_status).sort())
  assert.ok(cs.settings.vat_status.title && en.settings.vat_status.title)
  assert.ok(cs.invoice.vat_status_rates_adjusted && en.invoice.vat_status_rates_adjusted)
})

test('the daily VAT cache job has a frequency label in both languages', async () => {
  const [cs, en] = await Promise.all([read('i18n/cs.json'), read('i18n/en.json')].map(p => p.then(JSON.parse)))
  const find = (tree) => {
    const stack = [tree]
    while (stack.length) {
      const node = stack.pop()
      if (node && typeof node === 'object') {
        if ('freq_daily_0005' in node) return node.freq_daily_0005
        stack.push(...Object.values(node))
      }
    }
    return undefined
  }
  assert.ok(find(cs), 'cs label')
  assert.ok(find(en), 'en label')
})
