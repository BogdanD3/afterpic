import { useCallback, useEffect, useRef, useState } from 'react'
import { fetchPhoto, fetchPhotoStatus, isRetryableUploadError, uploadPhoto, validateFile } from '../api/photos'
import { preparePhoto } from '../api/preparePhoto'
import type { Photo } from '../types'
import { POLL_INTERVAL_MS } from './useGallery'

export type UploadStatus = 'preparing' | 'uploading' | 'processing' | 'failed'

export interface UploadItem {
  id: string
  // Set once the photo is prepared, so a huge original is never shown as a preview.
  previewUrl?: string
  status: UploadStatus
  progress: number
  uuid?: string
  error?: string
  // When the server accepted the photo. Processing time is counted from here,
  // so a slow upload or a long queue before it does not count against it.
  processingSince?: number
}

// Waits before the 2nd and 3rd attempt, so a phone with a weak signal gets a
// moment to reconnect instead of failing three times in a row.
const RETRY_DELAYS_MS = [2000, 5000]
const PROCESSING_TIMEOUT_MS = 5 * 60 * 1000

function wait(ms: number): Promise<void> {
  return new Promise((resolve) => window.setTimeout(resolve, ms))
}

function errorMessage(error: unknown): string {
  return error instanceof Error ? error.message : 'Nepoznata greška.'
}

export function useUploads(onProcessed: (photo: Photo) => void) {
  const [items, setItems] = useState<UploadItem[]>([])
  const itemsRef = useRef(items)
  const onProcessedRef = useRef(onProcessed)
  const queueRef = useRef<Promise<void>>(Promise.resolve())
  const prepareQueueRef = useRef<Promise<unknown>>(Promise.resolve())

  useEffect(() => {
    itemsRef.current = items
    onProcessedRef.current = onProcessed
  })

  const updateItem = useCallback((id: string, changes: Partial<UploadItem>) => {
    setItems((current) => current.map((item) => (item.id === id ? { ...item, ...changes } : item)))
  }, [])

  const removeItem = useCallback((id: string) => {
    setItems((current) => {
      current
        .filter((item) => item.id === id && item.previewUrl)
        .forEach((item) => URL.revokeObjectURL(item.previewUrl as string))

      return current.filter((item) => item.id !== id)
    })
  }, [])

  const sendFile = useCallback(
    async (id: string, file: File) => {
      for (let attempt = 0; ; attempt++) {
        try {
          // The item id is the upload id, so every retry of this photo is recognised.
          const { uuid } = await uploadPhoto(file, id, (progress) => updateItem(id, { progress }))

          updateItem(id, { status: 'processing', uuid, progress: 1, processingSince: Date.now() })

          return
        } catch (error) {
          const delay = RETRY_DELAYS_MS[attempt]

          if (!isRetryableUploadError(error) || delay === undefined) {
            updateItem(id, { status: 'failed', error: errorMessage(error) })

            return
          }

          updateItem(id, { progress: 0 })
          await wait(delay)
        }
      }
    },
    [updateItem],
  )

  const enqueue = useCallback(
    (files: File[]) => {
      for (const file of files) {
        const id = crypto.randomUUID()

        const item: UploadItem = {
          id,
          status: 'preparing',
          progress: 0,
        }

        setItems((current) => [item, ...current])

        // Photos are prepared one at a time (a 200 MP photo needs a lot of
        // memory to decode), while the previous one may still be uploading.
        const prepared = prepareQueueRef.current.then(async () => {
          const ready = await preparePhoto(file)
          const validationError = validateFile(ready)

          updateItem(id, {
            previewUrl: URL.createObjectURL(ready),
            status: validationError ? 'failed' : 'uploading',
            error: validationError ?? undefined,
          })

          return validationError ? null : ready
        })

        prepareQueueRef.current = prepared

        // Uploads run one after another so a slow mobile connection is not flooded.
        queueRef.current = queueRef.current.then(async () => {
          const ready = await prepared

          if (ready) {
            await sendFile(id, ready)
          }
        })
      }
    },
    [sendFile, updateItem],
  )

  // Uploaded photos show up in the gallery through polling, but the owner's
  // own upload is checked directly so it appears even if polling misses it.
  useEffect(() => {
    const timer = window.setInterval(async () => {
      const processing = itemsRef.current.filter((item) => item.status === 'processing' && item.uuid)

      for (const item of processing) {
        if (Date.now() - (item.processingSince ?? Date.now()) > PROCESSING_TIMEOUT_MS) {
          updateItem(item.id, { status: 'failed', error: 'Obrada je trajala predugo.' })

          continue
        }

        try {
          const status = await fetchPhotoStatus(item.uuid as string)

          if (status === null) {
            // The upload was accepted, so a missing photo means the admin deleted it.
            updateItem(item.id, { status: 'failed', error: 'Fotografija je uklonjena.' })
          } else if (status === 'failed') {
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
