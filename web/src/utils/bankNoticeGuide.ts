// Helpers for the bank e-mail notice setup guide: decide which parts of the guide to highlight
// from what the user has already configured.

export type GuideBank = 'airbank' | 'other'
export type GuideMailProvider = 'seznam' | 'gmail' | 'microsoft' | 'generic'

/** Air Bank counts as "selected" when any mapping uses its system provider. */
export function detectGuideBank(providerRefs: Array<string | null | undefined>): GuideBank {
  return providerRefs.some(ref => (ref ?? '').toLowerCase() === 'system:airbank') ? 'airbank' : 'other'
}

/** Guess the mail provider from an IMAP host name (case-insensitive substring match). */
export function detectGuideMailProvider(host: string | null | undefined): GuideMailProvider | null {
  const h = (host ?? '').trim().toLowerCase()
  if (h === '') return null
  if (h.includes('seznam')) return 'seznam'
  if (h.includes('gmail') || h.includes('googlemail')) return 'gmail'
  if (h.includes('office365') || h.includes('outlook')) return 'microsoft'
  return 'generic'
}

/** Pick the first recognised provider among the configured hosts (a draft host wins when listed first). */
export function detectGuideMailProviderFromHosts(hosts: Array<string | null | undefined>): GuideMailProvider | null {
  for (const host of hosts) {
    const p = detectGuideMailProvider(host)
    if (p !== null && p !== 'generic') return p
  }
  return hosts.some(h => (h ?? '').trim() !== '') ? 'generic' : null
}
