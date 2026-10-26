import { useCallback, useEffect, useRef, useState } from 'react'
import { ApiError, fetchGalleryPage, fetchUpdates, roomPhotosPath } from '../api/photos'
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

/**
 * The photos of one room, kept up to date by polling. `onRoomGone` is called
 * when the server says the room no longer exists (deleted or expired).
 */
export function useGallery(code: string, onRoomGone: () => void) {
  const [photos, setPhotos] = useState<Photo[]>([])
  const [nextPath, setNextPath] = useState<string | null>(null)
  const [isLoading, setIsLoading] = useState(true)
  const [hasError, setHasError] = useState(false)
  // Opaque polling position from the server. It may return a photo again
  // (late finishers overlap), so incoming photos are deduplicated by uuid.
  const cursorRef = useRef<string | null>(null)
  const isLoadingMoreRef = useRef(false)
  const onRoomGoneRef = useRef(onRoomGone)

  useEffect(() => {
    onRoomGoneRef.current = onRoomGone
  })

  useEffect(() => {
    let cancelled = false

    fetchGalleryPage(roomPhotosPath(code))
      .then((page) => {
        if (cancelled || !page) {
          return
        }

        cursorRef.current = page.updates_cursor
        setPhotos(page.data)
        setNextPath(page.links.next)
        setHasError(false)
      })
      .catch((error) => {
        if (cancelled) {
          return
        }

        if (error instanceof ApiError && error.status === 404) {
          onRoomGoneRef.current()
        } else {
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
  }, [code])

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
        const updates = await fetchUpdates(code, cursor)

        if (!updates) {
          return
        }

        cursorRef.current = updates.cursor

        if (updates.data.length === 0 && updates.deleted.length === 0) {
          return
        }

        const deleted = new Set(updates.deleted)

        setPhotos((current) =>
          prependNewest(current, updates.data).filter((photo) => !deleted.has(photo.uuid)),
        )
      } catch (error) {
        if (error instanceof ApiError && error.status === 404) {
          onRoomGoneRef.current()

          return
        }

        // The server no longer accepts the cursor (e.g. a new APP_KEY after a
        // deploy): start over from the first page. Otherwise keep the current
        // gallery; the next tick tries again.
        if (error instanceof ApiError && error.status === 422) {
          const page = await fetchGalleryPage(roomPhotosPath(code)).catch(() => null)

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
  }, [code, isLoading])

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
