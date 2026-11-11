import { api } from './client'

export type TourStatus = 'pending' | 'completed' | 'skipped'
/** How a tour run ended - the only values the server accepts. */
export type TourOutcome = Exclude<TourStatus, 'pending'>

export interface TourState {
  status: TourStatus
  /** ISO UTC time the tour was finished or skipped; null while pending. */
  finished_at: string | null
}

export const onboardingApi = {
  tour: () => api.get<TourState>('/onboarding/tour').then(r => r.data),
  finishTour: (status: TourOutcome) => api.put<TourState>('/onboarding/tour', { status }).then(r => r.data),
}
