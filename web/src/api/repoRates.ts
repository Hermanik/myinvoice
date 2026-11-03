import { api } from './client'

export interface RepoRate {
  valid_from: string
  rate_percent: number
  source: 'seed' | 'cnb' | 'manual'
}

export interface RepoRateSnapshot {
  /** Newest first. */
  rates: RepoRate[]
  verified_on: string | null
  /** Whether a delay beginning today could rely on the codebook (same rule as reminders and billing). */
  is_current: boolean
}

export interface RepoRateFetchResult extends RepoRateSnapshot {
  fetch: {
    added: Array<{ valid_from: string; rate_percent: number }>
    conflicts: Array<{ valid_from: string; stored: number; cnb: number }>
  }
}

export const repoRatesApi = {
  list: () => api.get<RepoRateSnapshot>('/codebooks/repo-rates').then(r => r.data),
  create: (data: { valid_from: string; rate_percent: number }) =>
    api.post<RepoRateSnapshot>('/codebooks/repo-rates', data).then(r => r.data),
  remove: (validFrom: string) =>
    api.delete<RepoRateSnapshot>(`/codebooks/repo-rates/${validFrom}`).then(r => r.data),
  fetchFromCnb: () =>
    api.post<RepoRateFetchResult>('/codebooks/repo-rates/fetch').then(r => r.data),
}
