import assert from 'node:assert/strict'
import test from 'node:test'

const { vatStatusAt, vatFlagsAt, documentVatDate } = await import('../src/utils/vatStatus.ts')

// The owner's case: non-payer since forever, VAT payer from 2027-01-01.
const registration = [
  { valid_from: '2000-01-01', status: 'non_payer' },
  { valid_from: '2027-01-01', status: 'payer' },
]

test('status the day before, on and after a change', () => {
  assert.equal(vatStatusAt(registration, '2026-12-31'), 'non_payer')
  assert.equal(vatStatusAt(registration, '2027-01-01'), 'payer')
  assert.equal(vatStatusAt(registration, '2027-01-02'), 'payer')
})

test('a future row does not apply today', () => {
  assert.equal(vatStatusAt(registration, '2026-11-03'), 'non_payer')
})

test('a date before the first row takes the first status, rows may arrive unsorted', () => {
  assert.equal(vatStatusAt([{ valid_from: '2000-01-01', status: 'payer' }], '1995-05-05'), 'payer')
  assert.equal(vatStatusAt([...registration].reverse(), '2026-12-31'), 'non_payer')
})

test('legacy flags are derived from the status', () => {
  assert.deepEqual(vatFlagsAt(registration, '2027-03-01'), { is_vat_payer: true, is_identified: false })
  assert.deepEqual(
    vatFlagsAt([{ valid_from: '2000-01-01', status: 'identified' }], '2026-01-01'),
    { is_vat_payer: false, is_identified: true },
  )
})

test('an empty or missing history counts as a payer', () => {
  assert.equal(vatStatusAt([], '2026-01-01'), 'payer')
  assert.equal(vatStatusAt(undefined, '2026-01-01'), 'payer')
})

test('the document date is DUZP, then the issue date, then today', () => {
  assert.equal(documentVatDate('2026-12-31', '2027-01-05', '2027-02-01'), '2026-12-31')
  assert.equal(documentVatDate(null, '2027-01-05', '2027-02-01'), '2027-01-05')
  assert.equal(documentVatDate('', '', '2027-02-01'), '2027-02-01')
})

test('an invoice for December issued in January is still a non-payer invoice', () => {
  const duzp = documentVatDate('2026-12-31', '2027-01-05', '2027-01-05')
  assert.equal(vatFlagsAt(registration, duzp).is_vat_payer, false)
})

test('an empty history falls back to the supplier\'s cached flags instead of assuming a payer', () => {
  assert.equal(vatStatusAt([], '2026-12-31', { is_vat_payer: false, is_identified: false }), 'non_payer')
  assert.equal(vatStatusAt(undefined, '2026-12-31', { is_vat_payer: false, is_identified: true }), 'identified')
  assert.equal(vatStatusAt([], '2026-12-31', { is_vat_payer: true, is_identified: false }), 'payer')
  assert.deepEqual(
    vatFlagsAt([], '2026-12-31', { is_vat_payer: false, is_identified: false }),
    { is_vat_payer: false, is_identified: false },
  )
  // A real history wins over the fallback.
  assert.equal(vatStatusAt(registration, '2027-02-01', { is_vat_payer: false, is_identified: false }), 'payer')
})

