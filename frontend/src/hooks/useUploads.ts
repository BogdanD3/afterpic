import { useCallback, useEffect, useRef, useState } from 'react'
import { ApiError, fetchPhoto, fetchPhotoStatus, uploadPhoto, validateFile } from '../api/photos'
import type { Photo } from '../types'
import { POLL_INTERVAL_MS } from './useGallery'

export type UploadStatus = 'uploading' | 'processing' | 'failed'

export interface UploadItem {
  id: string
  previewUrl: string
  status: UploadStatus
  progress: number
  uuid?: string
  error?: string
  startedAt: number
}

const MAX_NETWORK_ATTEMPTS = 3
const PROCESSING_TIMEOUT_MS = 3 * 60 * 1000

function errorMessage(error: unknown): string {
  return error instanceof Error ? error.message : 'Nepoznata greška.'
}

export function useUploads(onProcessed: (photo: Photo) => void) {
  const [items, setItems] = useState<UploadItem[]>([])
  const itemsRef = useRef(items)
  const onProcessedRef = useRef(onProcessed)
  const queueRef = useRef<Promise<void>>(Promise.resolve())

  useEffect(() => {
    itemsRef.current = items
    onProcessedRef.current = onProcessed
  })

  const updateItem = useCallback((id: string, changes: Partial<UploadItem>) => {
    setItems((current) => current.map((item) => (item.id === id ? { ...item, ...changes } : item)))
  }, [])

  const removeItem = useCallback((id: string) => {
    setItems((current) => {
      current.filter((item) => item.id === id).forEach((item) => URL.revokeObjectURL(item.previewUrl))

      return current.filter((item) => item.id !== id)
    })
  }, [])

  const sendFile = useCallback(
    async (id: string, file: File) => {
      for (let attempt = 1; attempt <= MAX_NETWORK_ATTEMPTS; attempt++) {
        try {
          const { uuid } = await uploadPhoto(file, (progress) => updateItem(id, { progress }))

          updateItem(id, { status: 'processing', uuid, progress: 1 })

          return
        } catch (error) {
          const isNetworkError = error instanceof ApiError && error.status === 0
          const canRetry = isNetworkError && attempt < MAX_NETWORK_ATTEMPTS

          if (!canRetry) {
            updateItem(id, { status: 'failed', error: errorMessage(error) })

            return
          }
        }
      }
    },
    [updateItem],
  )

  const enqueue = useCallback(
    (files: File[]) => {
      for (const file of files) {
        const id = crypto.randomUUID()
        const validationError = validateFile(file)

        const item: UploadItem = {
          id,
          previewUrl: URL.createObjectURL(file),
          status: validationError ? 'failed' : 'uploading',
          progress: 0,
          error: validationError ?? undefined,
          startedAt: Date.now(),
        }

        setItems((current) => [item, ...current])

        if (!validationError) {
          // Uploads run one after another so a slow mobile connection is not flooded.
          queueRef.current = queueRef.current.then(() => sendFile(id, file))
        }
      }
    },
    [sendFile],
  )

  // Uploaded photos show up in the gallery through polling, but the owner's
  // own upload is checked directly so it appears even if polling misses it.
  useEffect(() => {
    const timer = window.setInterval(async () => {
      const processing = itemsRef.current.filter((item) => item.status === 'processing' && item.uuid)

      for (const item of processing) {
        if (Date.now() - item.startedAt > PROCESSING_TIMEOUT_MS) {
          updateItem(item.id, { status: 'failed', error: 'Obrada je trajala predugo.' })

          continue
        }

        try {
          const status = await fetchPhotoStatus(item.uuid as string)

          if (status === 'failed') {
            updateItem(item.id, { status: 'failed', error: 'Obrada fotografije nije uspjela. Pokušajte ponovo.' })
          } else if (status === 'processed') {
            const photo = await fetchPhoto(item.uuid as string)

            if (photo) {
              onProcessedRef.current(photo)
              removeItem(item.id)
            }
          }
        } catch {
          // Try again on the next tick.
        }
      }
    }, POLL_INTERVAL_MS)

    return () => window.clearInterval(timer)
  }, [removeItem, updateItem])

  return { items, enqueue, removeItem }
}
