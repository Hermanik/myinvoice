import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const { toggleFavorite, pickFavorites, createFavoritesSync } = await import('../src/utils/navFavorites.ts')

function deferred() {
  let resolve, reject
  const promise = new Promise((res, rej) => { resolve = res; reject = rej })
  return { promise, resolve, reject }
}

/** In-memory stand-in for the auth store + API: records saves, resolves them on demand. */
function harness(initial) {
  let state = initial
  const calls = []
  const toggle = createFavoritesSync({
    read: () => state,
    write: (items) => { state = items },
    save: (items) => {
      const d = deferred()
      calls.push({ items, ...d })
      return d.promise
    },
  })
  return { toggle, calls, state: () => state }
}

const tick = () => new Promise(resolve => setImmediate(resolve))

test('toggleFavorite appends a new path and removes a starred one', () => {
  assert.deepEqual(toggleFavorite(['/invoices'], '/stats'), ['/invoices', '/stats'])
  assert.deepEqual(toggleFavorite(['/invoices', '/stats'], '/invoices'), ['/stats'])
})

test('pickFavorites keeps menu order and drops paths missing from the menu', () => {
  const items = [
    { to: '/', label: 'Přehled' },
    { to: '/invoices', label: 'Vydané faktury' },
    { to: '/stats', label: 'Tržby' },
  ]
  const picked = pickFavorites(items, ['/stats', '/gone', '/invoices'])
  assert.deepEqual(picked.map(i => i.label), ['Vydané faktury', 'Tržby'])
})

test('a successful save adopts the list returned by the server', async () => {
  const h = harness(['/invoices'])
  const done = h.toggle('/stats')
  assert.deepEqual(h.state(), ['/invoices', '/stats'], 'optimistic update is immediate')
  await tick()
  h.calls[0].resolve(['/invoices', '/stats'])
  await done
  assert.deepEqual(h.state(), ['/invoices', '/stats'])
})

test('a failed save restores the last confirmed list and reports the error', async () => {
  const h = harness(['/invoices'])
  const done = h.toggle('/stats')
  await tick()
  h.calls[0].reject(new Error('offline'))
  await assert.rejects(done, /offline/)
  assert.deepEqual(h.state(), ['/invoices'])
})

test('rapid toggles are saved one after another in click order', async () => {
  const h = harness([])
  const first = h.toggle('/invoices')
  const second = h.toggle('/stats')
  await tick()
  assert.equal(h.calls.length, 1, 'second save waits for the first one')
  assert.deepEqual(h.calls[0].items, ['/invoices'])

  h.calls[0].resolve(['/invoices'])
  await first
  await tick()
  assert.equal(h.calls.length, 2)
  assert.deepEqual(h.calls[1].items, ['/invoices', '/stats'])
  assert.deepEqual(h.state(), ['/invoices', '/stats'], 'earlier response does not undo the later click')

  h.calls[1].resolve(['/invoices', '/stats'])
  await second
  assert.deepEqual(h.state(), ['/invoices', '/stats'])
})

test('a failure superseded by a later successful save is swallowed', async () => {
  const h = harness([])
  const first = h.toggle('/invoices')
  const second = h.toggle('/stats')
  await tick()
  h.calls[0].reject(new Error('timeout'))
  await first
  await tick()
  h.calls[1].resolve(['/invoices', '/stats'])
  await second
  assert.deepEqual(h.state(), ['/invoices', '/stats'])
})

test('when the latest save fails the list falls back to what the server confirmed last', async () => {
  const h = harness([])
  const first = h.toggle('/invoices')
  const second = h.toggle('/stats')
  await tick()
  h.calls[0].resolve(['/invoices'])
  await first
  await tick()
  h.calls[1].reject(new Error('offline'))
  await assert.rejects(second, /offline/)
  assert.deepEqual(h.state(), ['/invoices'])
})

test('sidebar renders favorites as a section and keeps search on the plain menu', async () => {
  const layout = await readFile(new URL('../src/components/layout/AppLayout.vue', import.meta.url), 'utf8')
  assert.match(layout, /v-for="\(section, si\) in displaySections"/)
  assert.match(layout, /<GlobalSearch :menu-items="flatNavItems"/)
  assert.match(layout, /flatNavItems = computed\(\(\) =>\s*navSections\.value\.flatMap/)
  assert.match(layout, /:aria-pressed="isFavorite\(item\)"/)
  // A hidden star must not swallow taps on touch screens.
  assert.match(layout, /function starClasses[\s\S]*?pointer-events-none/)
})
