/** Appends the path, or removes it when it is already starred. */
export function toggleFavorite(favorites: readonly string[], path: string): string[] {
  return favorites.includes(path) ? favorites.filter(p => p !== path) : [...favorites, path]
}

/**
 * Starred items in menu order. Paths the user can no longer see (role,
 * supplier type) simply drop out because they are not in `items`.
 */
export function pickFavorites<T extends { to: string }>(items: readonly T[], favorites: readonly string[]): T[] {
  const starred = new Set(favorites)
  return items.filter(item => starred.has(item.to))
}

/**
 * Optimistic toggle with saves serialised in click order. Only the latest
 * request decides what the UI ends up showing: success adopts the server's
 * list, failure falls back to the last list the server confirmed. A failure
 * superseded by a later click is swallowed — that later save carries its change.
 */
export function createFavoritesSync(options: {
  read: () => string[]
  write: (items: string[]) => void
  save: (items: string[]) => Promise<string[]>
}): (path: string) => Promise<void> {
  let queue: Promise<unknown> = Promise.resolve()
  let latest = 0
  let pending = 0
  let confirmed: string[] = []

  return async function toggle(path: string): Promise<void> {
    const current = options.read()
    if (pending === 0) confirmed = current
    const next = toggleFavorite(current, path)
    options.write(next)

    const id = ++latest
    pending++
    const request = queue.then(() => options.save(next))
    queue = request.catch(() => undefined)
    try {
      const saved = await request
      confirmed = saved
      if (id === latest) options.write(saved)
    } catch (error) {
      if (id !== latest) return
      options.write(confirmed)
      throw error
    } finally {
      pending--
    }
  }
}
