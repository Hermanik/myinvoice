/**
 * Guided tour ("Prohlídka aplikace"): which steps each audience gets and how they find their targets.
 *
 * A step points at an element through a stable `data-tour="…"` attribute, never a CSS class, so restyling the menu
 * cannot silently break the tour. A step whose target is not on the screen (a menu item the role may not see, an
 * element hidden on a phone) is left out. The texts live in the i18n catalogue under `tour.<audience>.<step>`.
 */

export type TourAudience = 'accountant' | 'general'

export interface TourStepDef {
  id: string
  /** `data-tour` values in order of preference; the first one that is on the screen is highlighted. */
  targets: readonly string[]
}

/** The accountant is new to the application, so this tour walks through everything the role works with. */
const ACCOUNTANT_TOUR: readonly TourStepDef[] = [
  { id: 'dashboard', targets: ['dashboard-content', 'nav-dashboard'] },
  { id: 'timeline', targets: ['nav-timeline'] },
  { id: 'invoices', targets: ['nav-invoices'] },
  { id: 'purchase', targets: ['nav-purchase-invoices'] },
  { id: 'vat_dph', targets: ['nav-reports-dph'] },
  { id: 'vat_kh', targets: ['nav-reports-kh'] },
  { id: 'vat_sh', targets: ['nav-reports-shv'] },
  { id: 'submissions', targets: ['nav-submissions'] },
  { id: 'export', targets: ['nav-monthly-export'] },
  { id: 'databox', targets: ['nav-databox'] },
  { id: 'bank', targets: ['nav-bank'] },
  { id: 'user_menu', targets: ['user-menu'] },
  { id: 'help', targets: ['help'] },
]

/** Admin and read-only users know their own business, so they get the short overview. */
const GENERAL_TOUR: readonly TourStepDef[] = [
  { id: 'dashboard', targets: ['dashboard-content', 'nav-dashboard'] },
  { id: 'invoices', targets: ['nav-invoices'] },
  { id: 'purchase', targets: ['nav-purchase-invoices'] },
  { id: 'bank', targets: ['nav-bank'] },
  { id: 'taxes', targets: ['nav-reports-dph'] },
  { id: 'timeline', targets: ['nav-timeline'] },
  { id: 'settings', targets: ['nav-settings'] },
  { id: 'user_menu', targets: ['user-menu'] },
  { id: 'help', targets: ['help'] },
]

export const TOUR_STEPS: Record<TourAudience, readonly TourStepDef[]> = {
  accountant: ACCOUNTANT_TOUR,
  general: GENERAL_TOUR,
}

export function audienceFor(role: string | null | undefined): TourAudience {
  return role === 'accountant' ? 'accountant' : 'general'
}

/** i18n key of a step text. */
export function tourTextKey(audience: TourAudience, stepId: string, part: 'title' | 'text'): string {
  return `tour.${audience}.${stepId}.${part}`
}

/** A tour is worth starting only when a few steps survive, otherwise it is just a lone bubble. */
export const MIN_TOUR_STEPS = 2

/** Where the bubble sits relative to the highlighted element: beside a menu item, below a topbar or page element. */
export function placementFor(def: TourStepDef): { side: 'right' | 'bottom'; align: 'start' | 'end' } {
  const first = def.targets[0]
  if (first.startsWith('nav-')) return { side: 'right', align: 'start' }
  return { side: 'bottom', align: first === 'dashboard-content' ? 'start' : 'end' }
}

export type TargetFinder = (targets: readonly string[]) => Element | null

/** The steps whose target is on the screen, in the order of the tour. */
export function resolveSteps(defs: readonly TourStepDef[], find: TargetFinder): TourStepDef[] {
  return defs.filter(d => find(d.targets) !== null)
}

const TARGET_ID = /^[a-z0-9-]+$/

/** An element counts as present only when it takes space: `display: none` (a desktop-only link on a phone) does not. */
function isRendered(el: Element): boolean {
  return el.getClientRects().length > 0
}

/** The first rendered element of the first target that has one. */
export function findTourTarget(targets: readonly string[], root: ParentNode = document): HTMLElement | null {
  for (const id of targets) {
    if (!TARGET_ID.test(id)) continue
    for (const el of root.querySelectorAll<HTMLElement>(`[data-tour="${id}"]`)) {
      if (isRendered(el)) return el
    }
  }
  return null
}

/** The menu is a slide-in drawer below the `lg` breakpoint (1024 px), so its items are off screen until it opens. */
export function isDrawerLayout(): boolean {
  return !window.matchMedia('(min-width: 1024px)').matches
}

/** Is the element inside the slide-in menu? */
export function inDrawer(el: Element): boolean {
  return el.closest('[data-tour-drawer]') !== null
}

/** Titles and texts are shown as HTML by the tour library, so the translations are escaped first. */
export function escapeHtml(text: string): string {
  return text.replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch] as string)
}
