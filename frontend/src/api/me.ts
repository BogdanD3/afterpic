import { ApiError, resolveUrl } from './photos'

/** The name this phone shows under its photos, or null. */
export async function fetchMyName(): Promise<string | null> {
  const response = await fetch(resolveUrl('/api/me'), { credentials: 'include', headers: { Accept: 'application/json' } })

  return response.ok ? ((await response.json()) as { name: string | null }).name : null
}

/** Set (or clear with '') the name; it applies to this phone's earlier photos too. */
export async function saveMyName(name: string): Promise<string | null> {
  const response = await fetch(resolveUrl('/api/me/name'), {
    method: 'PUT',
    credentials: 'include',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    body: JSON.stringify({ name }),
  })

  if (!response.ok) {
    throw new ApiError(response.status, 'Ime nije sačuvano. Pokušajte ponovo.')
  }

  return ((await response.json()) as { name: string | null }).name
}

const PROMPTED_KEY = 'namePrompted'

/** Whether this phone was already asked for a name (asked once, never again). */
export function wasAskedForName(): boolean {
  try {
    return localStorage.getItem(PROMPTED_KEY) === '1'
  } catch {
    return true
  }
}

export function markAskedForName(): void {
  try {
    localStorage.setItem(PROMPTED_KEY, '1')
  } catch {
    // Without storage the prompt may come again next time; harmless.
  }
}
