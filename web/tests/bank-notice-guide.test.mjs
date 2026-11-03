import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const root = new URL('../src/', import.meta.url)
const read = (path) => readFile(new URL(path, root), 'utf8')

const { detectGuideBank, detectGuideMailProvider, detectGuideMailProviderFromHosts } =
  await import('../src/utils/bankNoticeGuide.ts')

test('the guide component renders four steps and a collapsible body', async () => {
  const src = await read('components/bank/BankNoticeGuide.vue')

  for (const n of [1, 2, 3, 4]) {
    assert.match(src, new RegExp(`data-testid="guide-step-${n}"`), `step ${n} is rendered`)
    assert.match(src, new RegExp(`bank_notice_guide\\.step${n}_title`), `step ${n} has a title`)
  }
  assert.match(src, /data-testid="guide-toggle"[^>]*:aria-expanded="open"/, 'main toggle exposes its state')
  assert.match(src, /<div v-if="open" [^>]*data-testid="guide-body"/, 'body is collapsed by default')
  assert.match(src, /const open = ref\(false\)/)
  assert.match(src, /data-testid="guide-airbank-body"/, 'Air Bank section is toggleable')
})

test('the guide is mounted on the bank notices tab with prefill inputs', async () => {
  const page = await read('pages/admin/BankAccounts.vue')

  assert.match(page, /<BankNoticeGuide :provider-refs="guideProviderRefs" :hosts="guideHosts" \/>/)
  assert.match(page, /guideHosts = computed/)
})

test('prefill detection: Air Bank mapping and Seznam host', () => {
  assert.equal(detectGuideBank(['system:airbank']), 'airbank')
  assert.equal(detectGuideBank([null, 'system:fio']), 'other')
  assert.equal(detectGuideMailProvider('imap.seznam.cz'), 'seznam')
  assert.equal(detectGuideMailProvider('IMAP.Gmail.com'), 'gmail')
  assert.equal(detectGuideMailProvider('outlook.office365.com'), 'microsoft')
  assert.equal(detectGuideMailProvider('mail.example.com'), 'generic')
  assert.equal(detectGuideMailProvider(''), null)
  assert.equal(detectGuideMailProviderFromHosts([null, 'imap.seznam.cz']), 'seznam')
  assert.equal(detectGuideMailProviderFromHosts([]), null)
})

test('cs and en carry the same guide keys and every key used by the component exists', async () => {
  const [cs, en] = await Promise.all([read('i18n/cs.json'), read('i18n/en.json')].map(p => p.then(JSON.parse)))
  assert.deepEqual(Object.keys(cs.bank_notice_guide).sort(), Object.keys(en.bank_notice_guide).sort())

  const src = await read('components/bank/BankNoticeGuide.vue')
  const used = new Set([...src.matchAll(/'((?:ab|s3|v|ts|mp)_[a-z0-9_]+)'/g)].map(m => m[1]))
  for (const m of src.matchAll(/bank_notice_guide\.([a-z0-9_]+)/g)) used.add(m[1])
  assert.ok(used.size > 30)
  for (const key of used) assert.ok(cs.bank_notice_guide[key], `cs.${key}`)
})
