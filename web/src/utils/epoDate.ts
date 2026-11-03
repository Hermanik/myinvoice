/** `YYYY-MM-DD` (an <input type="date"> value) as the EPO date format `DD.MM.YYYY`. */
export function isoToEpoDate(iso: string): string {
  const [y, m, d] = iso.split('-')
  return `${d}.${m}.${y}`
}
