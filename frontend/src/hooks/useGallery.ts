import { useCallback, useEffect, useRef, useState } from 'react'
import { ApiError, fetchGalleryPage, fetchUpdates } from '../api/photos'
import type { Photo } from '../types'

export const POLL_INTERVAL_MS = 5000

function prependNewest(current: Photo[], incoming: Photo[]): Photo[] {
  const known = new Set(current.map((photo) => photo.uuid))
  const fresh = incoming.filter((photo) => !known.has(photo.uuid))

  return fresh.length > 0 ? [...[...fresh].reverse(), ...current] : current
}

function appendOldest(current: Photo[], incoming: Photo[]): Photo[] {
  const known = new Set(current.map((photo) => photo.uuid))

  return [...current, ...incoming.filter((photo) => !known.has(photo.uuid))]
}

export function useGallery() {
  const [photos, setPhotos] = useState<Photo[]>([])
  const [nextPath, setNextPath] = useState<string | null>(null)
  const [isLoading, setIsLoading] = useState(true)
  const [hasError, setHasError] = useState(false)
  // Opaque polling position from the server. It may return a photo again
  // (late finishers overlap), so incoming photos are deduplicated by uuid.
  const cursorRef = useRef<string | null>(null)
  const isLoadingMoreRef = useRef(false)

  useEffect(() => {
    let cancelled = false

    fetchGalleryPage()
      .then((page) => {
        if (cancelled || !page) {
          return
        }

        cursorRef.current = page.updates_cursor
        setPhotos(page.data)
        setNextPath(page.links.next)
        setHasError(false)
      })
      .catch(() => {
        if (!cancelled) {
          setHasError(true)
        }
      })
      .finally(() => {
        if (!cancelled) {
          setIsLoading(false)
        }
      })

    return () => {
      cancelled = true
    }
  }, [])

  // Polling starts only after the first page is loaded, so cursorRef is set.
  useEffect(() => {
    if (isLoading) {
      return
    }

    const poll = async () => {
      const cursor = cursorRef.current

      if (document.hidden || !cursor) {
        return
      }

      try {
        const updates = await fetchUpdates(cursor)

        if (!updates) {
          return
        }

        cursorRef.current = updates.cursor

        if (updates.data.length === 0) {
          return
        }

        setPhotos((current) => prependNewest(current, updates.data))
      } catch (error) {
        // The server no longer accepts the cursor (e.g. a new APP_KEY after a
        // deploy): start over from the first page. Otherwise keep the current
        // gallery; the next tick tries again.
        if (error instanceof ApiError && error.status === 422) {
          const page = await fetchGalleryPage().catch(() => null)

          if (page) {
            cursorRef.current = page.updates_cursor
            setPhotos((current) => prependNewest(current, [...page.data].reverse()))
          }
        }
      }
    }

    const timer = window.setInterval(poll, POLL_INTERVAL_MS)
    document.addEventListener('visibilitychange', poll)

    return () => {
      window.clearInterval(timer)
      document.removeEventListener('visibilitychange', poll)
    }
  }, [isLoading])

  const loadMore = useCallback(async () => {
    if (!nextPath || isLoadingMoreRef.current) {
      return
    }

    isLoadingMoreRef.current = true

    try {
      const page = await fetchGalleryPage(nextPath)

      if (page) {
        setPhotos((current) => appendOldest(current, page.data))
        setNextPath(page.links.next)
      }
    } catch {
      setHasError(true)
    } finally {
      isLoadingMoreRef.current = false
    }
  }, [nextPath])

  const addPhoto = useCallback((photo: Photo) => {
    setPhotos((current) => prependNewest(current, [photo]))
  }, [])

  const removePhoto = useCallback((uuid: string) => {
    setPhotos((current) => current.filter((photo) => photo.uuid !== uuid))
  }, [])

  return {
    photos,
    isLoading,
    hasError,
    hasMore: nextPath !== null,
    loadMore,
    addPhoto,
    removePhoto,
  }
}
