import { useState } from 'react'

const STORAGE_KEY = 'photoAdminToken'

/**
 * The admin token, or null for guests.
 *
 * The admin opens the gallery once as `/?admin=TOKEN`. The token is kept for
 * this browser tab only (sessionStorage) and removed from the address bar, so
 * it does not stay in the history. Guests never see it.
 */
export function useAdminToken(): string | null {
  const [token] = useState<string | null>(() => {
    const url = new URL(window.location.href)
    const fromUrl = url.searchParams.get('admin')

    try {
      if (fromUrl) {
        sessionStorage.setItem(STORAGE_KEY, fromUrl)
        url.searchParams.delete('admin')
        window.history.replaceState(null, '', url.pathname + url.search + url.hash)

        return fromUrl
      }

      return sessionStorage.getItem(STORAGE_KEY)
    } catch {
      // Storage can be blocked (private mode); the token then lasts for this page only.
      return fromUrl
    }
  })

  return token
}
