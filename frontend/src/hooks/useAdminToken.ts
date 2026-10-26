import { useCallback, useState } from 'react'

const SESSION_KEY = 'photoAdminToken'
const REMEMBER_KEY = 'photoAdminTokenRemembered'

function takeParam(name: string): string | null {
  const url = new URL(window.location.href)
  const value = url.searchParams.get(name)

  if (value) {
    url.searchParams.delete(name)
    window.history.replaceState(null, '', url.pathname + url.search + url.hash)
  }

  return value
}

function readToken(): string | null {
  const fromUrl = takeParam('admin')

  try {
    if (fromUrl) {
      sessionStorage.setItem(SESSION_KEY, fromUrl)

      return fromUrl
    }

    return sessionStorage.getItem(SESSION_KEY) ?? localStorage.getItem(REMEMBER_KEY)
  } catch {
    // Storage can be blocked (private mode); the token then lasts for this page only.
    return fromUrl
  }
}

/**
 * The super admin token, or null.
 *
 * It comes from `?admin=TOKEN` once (then removed from the address bar, so
 * it does not stay in the history) or from the admin page's sign-in. It is
 * kept for this tab (sessionStorage), or on this device when "remember" was
 * chosen (localStorage). Guests never see it.
 */
export function useAdminToken(): [string | null, (token: string | null, remember?: boolean) => void] {
  const [token, setTokenState] = useState<string | null>(readToken)

  const setToken = useCallback((next: string | null, remember = false) => {
    try {
      sessionStorage.removeItem(SESSION_KEY)
      localStorage.removeItem(REMEMBER_KEY)

      if (next) {
        ;(remember ? localStorage : sessionStorage).setItem(remember ? REMEMBER_KEY : SESSION_KEY, next)
      }
    } catch {
      // Without storage the token lasts for this page only.
    }

    setTokenState(next)
  }, [])

  return [token, setToken]
}

/**
 * The room key (event host or guest room admin) for this room, or null.
 *
 * It comes from `?key=...` in the admin link once, then lives on this device
 * (localStorage), because the room's creator must keep it after closing the
 * browser. Removed from the address bar so it is not shared by accident.
 */
export function useRoomKey(code: string): [string | null, () => void] {
  const storageKey = `roomKey:${code}`

  const [key, setKey] = useState<string | null>(() => {
    const fromUrl = takeParam('key')

    try {
      if (fromUrl) {
        localStorage.setItem(storageKey, fromUrl)

        return fromUrl
      }

      return localStorage.getItem(storageKey)
    } catch {
      return fromUrl
    }
  })

  const forget = useCallback(() => {
    try {
      localStorage.removeItem(storageKey)
    } catch {
      // Nothing stored.
    }

    setKey(null)
  }, [storageKey])

  return [key, forget]
}
