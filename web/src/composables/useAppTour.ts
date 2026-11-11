import { onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Driver } from 'driver.js'
import { onboardingApi, type TourOutcome } from '@/api/onboarding'
import { useAuthStore } from '@/stores/auth'
import {
  MIN_TOUR_STEPS, TOUR_STEPS, audienceFor, escapeHtml, findTourTarget, inDrawer, isDrawerLayout, placementFor, resolveSteps, tourTextKey,
} from '@/utils/tour'

/** The mobile menu slides in for 200 ms (AppLayout); the next step is shown once it has settled. */
const DRAWER_TRANSITION_MS = 260
/** Gives the page a moment to render before the tour starts by itself after login. */
const AUTO_START_DELAY_MS = 700

const sleep = (ms: number) => new Promise<void>(resolve => setTimeout(resolve, ms))

export interface TourHost {
  /** Opens or closes the slide-in menu of the narrow layout. */
  setDrawer: (open: boolean) => void
  isDrawerOpen: () => boolean
}

/**
 * Guided tour of the application: a spotlight on one element at a time with a title, a short text, Back / Next /
 * Skip and the progress. Starts by itself on a user's first login (the server remembers who has seen it) and can be
 * started again from the menu. Built on driver.js (MIT), which is loaded only when a tour actually starts.
 *
 * Finishing the last step records `completed`, closing it any other way (Skip, the cross, Escape) records `skipped`.
 * A tour cut short by the application itself (the session locks, the page is left) records nothing, so it comes
 * back on the next login.
 */
export function useAppTour(host: TourHost) {
  const { t } = useI18n()
  const auth = useAuthStore()
  const running = ref(false)
  let stopRun: (() => void) | null = null
  let disposed = false

  async function start(): Promise<void> {
    if (running.value || disposed) return
    const audience = audienceFor(auth.user?.role)
    const defs = resolveSteps(TOUR_STEPS[audience], findTourTarget)
    if (defs.length < MIN_TOUR_STEPS) return

    running.value = true
    let drawerOpen = host.isDrawerOpen()
    let busy = false
    let outcome: TourOutcome | null = null
    let aborted = false
    let finished = false
    try {
      const [{ driver }] = await Promise.all([
        import('driver.js'),
        import('driver.js/dist/driver.css'),
        import('@/styles/tour.css'),
      ])
      if (disposed) return

      const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches

      /** Shows the menu drawer exactly when the step's element lives in it (narrow layout only). */
      const prepareDrawer = async (el: Element) => {
        const wanted = isDrawerLayout() && inDrawer(el)
        if (wanted === drawerOpen) return
        drawerOpen = wanted
        host.setDrawer(wanted)
        await sleep(DRAWER_TRANSITION_MS)
      }

      /**
       * Tidies up after the tour and records how it ended. Idempotent, and called by us rather than left to the
       * library's `onDestroyed`, which driver.js skips when the tour is closed during the first 400 ms animation.
       */
      const finish = () => {
        if (finished) return
        finished = true
        if (drawerOpen) host.setDrawer(false)
        stopRun = null
        running.value = false
        // Failing to save only means the tour is offered once more on the next login.
        if (!aborted && outcome) void onboardingApi.finishTour(outcome).catch(() => {})
      }
      const endTour = (d: Driver, result: TourOutcome) => {
        outcome ??= result
        d.destroy()
        finish()
      }

      const go = async (drv: Driver, delta: 1 | -1) => {
        if (busy) return
        busy = true
        try {
          // A target that disappeared meanwhile (the window was resized) is stepped over.
          let index = (drv.getActiveIndex() ?? 0) + delta
          while (index >= 0 && index < defs.length && !findTourTarget(defs[index].targets)) index += delta
          if (index < 0) return
          if (index >= defs.length) {
            endTour(drv, 'completed')
            return
          }
          await prepareDrawer(findTourTarget(defs[index].targets)!)
          drv.moveTo(index)
        } finally {
          busy = false
        }
      }

      const drv = driver({
        animate: !reduceMotion,
        smoothScroll: false,
        showProgress: true,
        progressText: '{{current}} / {{total}}',
        nextBtnText: escapeHtml(t('tour.next')),
        prevBtnText: escapeHtml(t('tour.prev')),
        doneBtnText: escapeHtml(t('tour.done')),
        overlayColor: '#0b0814',
        overlayOpacity: 0.62,
        stagePadding: 6,
        stageRadius: 8,
        popoverClass: 'mi-tour',
        // The highlighted item must not be clickable (it would leave the page mid-tour), and a stray click on the
        // dimmed page must not end a tour somebody has just started.
        disableActiveInteraction: true,
        overlayClickBehavior: () => {},
        steps: defs.map(def => ({
          element: () => findTourTarget(def.targets) ?? document.body,
          popover: {
            title: escapeHtml(t(tourTextKey(audience, def.id, 'title'))),
            description: escapeHtml(t(tourTextKey(audience, def.id, 'text'))),
            ...placementFor(def),
          },
        })),
        onNextClick: (_el, _step, { driver: d }) => {
          if (d.isLastStep()) endTour(d, 'completed')
          else void go(d, 1)
        },
        onPrevClick: (_el, _step, { driver: d }) => { void go(d, -1) },
        // The cross and Escape end up here (destroy() itself does not).
        onDestroyStarted: (_el, _step, { driver: d }) => endTour(d, 'skipped'),
        onPopoverRender: (popover, { driver: d }) => {
          const skip = document.createElement('button')
          skip.type = 'button'
          skip.className = 'mi-tour-skip'
          skip.textContent = t('tour.skip')
          skip.addEventListener('click', () => endTour(d, 'skipped'))
          popover.footer.insertBefore(skip, popover.footerButtons)
          popover.closeButton.setAttribute('aria-label', t('tour.close'))
          // The last step has nothing left to skip.
          skip.hidden = d.isLastStep()
        },
        onDestroyed: finish,
      })

      stopRun = () => {
        aborted = true
        drv.destroy()
        finish()
      }
      await prepareDrawer(findTourTarget(defs[0].targets)!)
      drv.drive(0)
    } catch {
      // The tour is a convenience: a failed chunk load or a missing element must never break the application.
      running.value = false
      stopRun = null
    }
  }

  /** Starts the tour by itself when this user has not seen it yet. */
  async function autoStart(): Promise<void> {
    try {
      const state = await onboardingApi.tour()
      if (state.status !== 'pending') return
    } catch {
      return
    }
    await sleep(AUTO_START_DELAY_MS)
    await start()
  }

  /** Ends a running tour without recording an outcome. */
  function abort(): void {
    stopRun?.()
  }

  onBeforeUnmount(() => {
    disposed = true
    abort()
  })

  return { running, autoStart, start, abort }
}
