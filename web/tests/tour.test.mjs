import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const root = new URL('../src/', import.meta.url)
const read = path => readFile(new URL(path, root), 'utf8')
const json = async path => JSON.parse(await read(path))

const {
  TOUR_STEPS, MIN_TOUR_STEPS, audienceFor, tourTextKey, resolveSteps, findTourTarget, escapeHtml, placementFor,
} = await import('../src/utils/tour.ts')

/** A stand-in for a document: `data-tour` values mapped to whether the element takes space on the screen. */
function fakeRoot(present) {
  return {
    querySelectorAll(selector) {
      const id = selector.match(/^\[data-tour="([a-z0-9-]+)"\]$/)?.[1]
      return (present[id] ?? []).map(rendered => ({ id, getClientRects: () => (rendered ? [{}] : []) }))
    },
  }
}

test('only the accountant gets the long tour, everybody else the short overview', () => {
  assert.equal(audienceFor('accountant'), 'accountant')
  for (const role of ['admin', 'readonly', undefined, null, '']) assert.equal(audienceFor(role), 'general')
  assert.ok(TOUR_STEPS.general.length < TOUR_STEPS.accountant.length)
})

test('the accountant tour explains the application in the agreed order', () => {
  assert.deepEqual(TOUR_STEPS.accountant.map(s => s.id), [
    'dashboard', 'timeline', 'invoices', 'purchase', 'vat_dph', 'vat_kh', 'vat_sh',
    'submissions', 'export', 'databox', 'bank', 'user_menu', 'help',
  ])
})

test('a step is left out when none of its targets is on the screen', () => {
  const find = targets => findTourTarget(targets, fakeRoot({ 'nav-invoices': [true], 'nav-bank': [true] }))
  const steps = resolveSteps(TOUR_STEPS.accountant, find)
  assert.deepEqual(steps.map(s => s.id), ['invoices', 'bank'])
})

test('the dashboard step falls back to the menu item when the page content is not there', () => {
  const dashboard = TOUR_STEPS.accountant[0]
  const withContent = findTourTarget(dashboard.targets, fakeRoot({ 'dashboard-content': [true], 'nav-dashboard': [true] }))
  const withoutContent = findTourTarget(dashboard.targets, fakeRoot({ 'nav-dashboard': [true] }))
  assert.equal(withContent.id, 'dashboard-content')
  assert.equal(withoutContent.id, 'nav-dashboard')
})

test('an element hidden by the layout (a desktop-only link on a phone) does not count, the next one does', () => {
  // The user menu exists twice: in the topbar (hidden below lg) and in the mobile menu.
  const phone = findTourTarget(['user-menu'], fakeRoot({ 'user-menu': [false, true] }))
  assert.equal(phone.getClientRects().length, 1)
  assert.equal(findTourTarget(['user-menu'], fakeRoot({ 'user-menu': [false, false] })), null)
})

test('a target id that is not a plain identifier is never put into a selector', () => {
  assert.equal(findTourTarget(['x"] , body [data-tour="y'], fakeRoot({})), null)
})

test('a role that sees almost nothing does not get a lone bubble', () => {
  assert.ok(MIN_TOUR_STEPS >= 2)
})

test('texts are escaped because the tour library renders them as HTML', () => {
  assert.equal(escapeHtml('<b>"A" & \'B\'</b>'), '&lt;b&gt;&quot;A&quot; &amp; &#39;B&#39;&lt;/b&gt;')
})

for (const lang of ['cs', 'en']) {
  test(`every tour step has a title and a text in ${lang}`, async () => {
    const messages = await json(`i18n/${lang}.json`)
    const lookup = key => key.split('.').reduce((node, part) => node?.[part], messages)
    for (const audience of Object.keys(TOUR_STEPS)) {
      for (const step of TOUR_STEPS[audience]) {
        for (const part of ['title', 'text']) {
          const key = tourTextKey(audience, step.id, part)
          assert.equal(typeof lookup(key), 'string', `${lang}: missing ${key}`)
          assert.ok(lookup(key).length > 3, `${lang}: empty ${key}`)
        }
      }
    }
    for (const key of ['menu_label', 'next', 'prev', 'done', 'skip', 'close']) {
      assert.equal(typeof messages.tour[key], 'string', `${lang}: missing tour.${key}`)
    }
  })
}

test('every target of the tour is marked in the application with a data-tour attribute', async () => {
  const layout = await read('components/layout/AppLayout.vue')
  const dashboard = await read('components/dashboard/AccountantDashboard.vue')
  const markers = new Set([
    ...[...layout.matchAll(/tour: '([a-z0-9-]+)'/g)].map(m => m[1]),
    ...[...layout.matchAll(/data-tour="([a-z0-9-]+)"/g)].map(m => m[1]),
    ...[...dashboard.matchAll(/data-tour="([a-z0-9-]+)"/g)].map(m => m[1]),
  ])
  for (const audience of Object.values(TOUR_STEPS)) {
    for (const step of audience) {
      for (const target of step.targets) assert.ok(markers.has(target), `no element carries data-tour="${target}"`)
    }
  }
})

test('the menu items are bound to data-tour and the drawer is marked, so the tour can open it on a phone', async () => {
  const layout = await read('components/layout/AppLayout.vue')
  assert.equal([...layout.matchAll(/:data-tour="item\.tour"/g)].length, 2, 'internal and external menu items')
  assert.match(layout, /<aside\s+data-tour-drawer/)
  assert.match(layout, /isDrawerOpen: \(\) => mobileOpen\.value/)
})

test('the tour can be restarted from the topbar and from the mobile menu, and is dropped when the session locks', async () => {
  const layout = await read('components/layout/AppLayout.vue')
  assert.equal([...layout.matchAll(/@click="tour\.start\(\)"/g)].length, 2)
  assert.match(layout, /data-testid="tour-restart"/)
  assert.match(layout, /tour\.autoStart\(\)/)
  assert.match(layout, /privacyCurtain[\s\S]{0,120}locked => \{ if \(locked\) tour\.abort\(\) \}/)
})

test('the tour records completion on the last step and a skip on every other way out', async () => {
  const tour = await read('composables/useAppTour.ts')
  assert.match(tour, /d\.isLastStep\(\)\) endTour\(d, 'completed'\)/)
  assert.match(tour, /onDestroyStarted: \(_el, _step, \{ driver: d \}\) => endTour\(d, 'skipped'\)/)
  assert.match(tour, /skip\.addEventListener\('click', \(\) => endTour\(d, 'skipped'\)\)/)
  assert.match(tour, /if \(!aborted && outcome\) void onboardingApi\.finishTour\(outcome\)/)
  // driver.js skips onDestroyed when the tour is closed during its first animation, so cleanup is our own, idempotent.
  assert.match(tour, /const finish = \(\) => \{\s*if \(finished\) return/)
  // The library and its stylesheet are loaded on demand, not with the application.
  assert.match(tour, /import\('driver\.js'\)/)
  assert.doesNotMatch(tour, /^import (?!type ).* from 'driver\.js'$/m, 'only a type import is allowed at the top')
})

test('driver.js is pinned to an exact version', async () => {
  const pkg = JSON.parse(await readFile(new URL('../package.json', import.meta.url), 'utf8'))
  assert.match(pkg.dependencies['driver.js'], /^\d+\.\d+\.\d+$/)
})

test('the bubble sits beside a menu item and below the topbar and page elements', () => {
  const byId = Object.fromEntries(TOUR_STEPS.accountant.map(s => [s.id, placementFor(s)]))
  assert.deepEqual(byId.timeline, { side: 'right', align: 'start' })
  assert.deepEqual(byId.dashboard, { side: 'bottom', align: 'start' })
  assert.deepEqual(byId.user_menu, { side: 'bottom', align: 'end' })
  assert.deepEqual(byId.help, { side: 'bottom', align: 'end' })
})
