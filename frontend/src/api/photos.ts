import type { GalleryPage, Photo, SinglePhoto, UpdatesPage } from '../types'

const API_BASE = import.meta.env.VITE_API_URL ?? ''

export const MAX_UPLOAD_BYTES = 50 * 1024 * 1024

const SUPPORTED_TYPES = ['image/jpeg', 'image/png', 'image/webp']
const HEIC_TYPES = ['image/heic', 'image/heif']

export class ApiError extends Error {
  readonly status: number

  constructor(status: number, message: string) {
    super(message)
    this.status = status
  }
}

/**
 * Returns a user-facing message (in Bosnian) if the file cannot be uploaded, otherwise null.
 */
export function validateFile(file: File): string | null {
  if (file.size > MAX_UPLOAD_BYTES) {
    return 'Fotografija je veća od 50 MB.'
  }

  if (HEIC_TYPES.includes(file.type)) {
    return 'HEIC fotografije nisu podržane. Promijenite format kamere na JPEG.'
  }

  if (file.type && !SUPPORTED_TYPES.includes(file.type)) {
    return 'Podržani su samo JPEG, PNG i WebP formati.'
  }

  return null
}

export function resolveUrl(path: string): string {
  return `${API_BASE}${path}`
}

/**
 * GET as JSON. 304 gives null. 404 gives null too, unless `notFound` is
 * 'throw': for a room's gallery a 404 means the room is gone, which the page
 * must show instead of quietly showing nothing.
 */
export async function getJson<T>(path: string, notFound: 'null' | 'throw' = 'null'): Promise<T | null> {
  const response = await fetch(resolveUrl(path), { headers: { Accept: 'application/json' } })

  if (response.status === 304 || (response.status === 404 && notFound === 'null')) {
    return null
  }

  if (!response.ok) {
    throw new ApiError(response.status, 'Učitavanje nije uspjelo.')
  }

  return (await response.json()) as T
}

export function roomPhotosPath(code: string): string {
  return `/api/rooms/${encodeURIComponent(code)}/photos`
}

/**
 * `path` is the room's first page or a `links.next` path from the previous
 * page (the backend sends paths without host, so they work behind any
 * proxy). Throws ApiError 404 when the room no longer exists.
 */
export function fetchGalleryPage(path: string): Promise<GalleryPage | null> {
  return getJson<GalleryPage>(path, 'throw')
}

/**
 * `cursor` is opaque: the first one comes from the gallery page, every next
 * one from the previous updates answer. Null when nothing changed (304).
 */
export function fetchUpdates(code: string, cursor: string): Promise<UpdatesPage | null> {
  return getJson<UpdatesPage>(`${roomPhotosPath(code)}/updates?cursor=${encodeURIComponent(cursor)}`, 'throw')
}

export async function fetchPhoto(uuid: string): Promise<Photo | null> {
  const body = await getJson<SinglePhoto>(`/api/photos/${uuid}`)

  return body?.data ?? null
}

export type PhotoStatus = 'pending' | 'processed' | 'failed'

/**
 * Processing status of an uploaded photo, or null when the server does not know it.
 */
export async function fetchPhotoStatus(uuid: string): Promise<PhotoStatus | null> {
  const body = await getJson<{ status: PhotoStatus }>(`/api/photos/${uuid}/status`)

  return body?.status ?? null
}

/** The keys a page holds: the super admin token and the room's key (host or room admin). */
export interface AdminKeys {
  adminToken: string | null
  roomKey: string | null
}

export function adminHeaders(keys: AdminKeys): Record<string, string> {
  const headers: Record<string, string> = {}

  if (keys.adminToken) {
    headers['X-Admin-Token'] = keys.adminToken
  }

  if (keys.roomKey) {
    headers['X-Room-Key'] = keys.roomKey
  }

  return headers
}

/**
 * Delete a photo (row and files) as super admin, event host or room admin.
 * Throws ApiError when refused.
 */
export async function deletePhoto(uuid: string, keys: AdminKeys): Promise<void> {
  const response = await fetch(resolveUrl(`/api/photos/${uuid}`), {
    method: 'DELETE',
    headers: adminHeaders(keys),
  })

  if (!response.ok) {
    throw new ApiError(response.status, 'Brisanje nije uspjelo.')
  }
}

export function downloadUrl(uuid: string): string {
  return resolveUrl(`/api/photos/${uuid}/download`)
}

function extensionFor(mimeType: string): string {
  const subtype = mimeType.split('/')[1] ?? 'jpeg'

  return subtype === 'jpeg' ? 'jpg' : subtype
}

/**
 * Normal browser download of the original, full-quality photo. The server
 * answers with Content-Disposition: attachment, so the browser saves the file
 * (Downloads folder) instead of opening it.
 */
export function downloadPhoto(uuid: string): void {
  const link = document.createElement('a')
  link.href = downloadUrl(uuid)
  link.download = ''
  document.body.appendChild(link)
  link.click()
  link.remove()
}

/**
 * True when the browser can share files (phones). The share sheet offers
 * "Save Image", which puts the photo straight into the photo library.
 */
export function canSharePhotos(): boolean {
  return typeof navigator.share === 'function' && typeof navigator.canShare === 'function'
}

export type ShareOutcome = 'shared' | 'cancelled'

/**
 * Open the share sheet with the original photo.
 *
 * Throws ApiError when the server refuses the download, and rethrows other
 * share errors. Closing the sheet is not an error ('cancelled').
 */
export async function sharePhoto(uuid: string): Promise<ShareOutcome> {
  const response = await fetch(downloadUrl(uuid))

  if (!response.ok) {
    throw new ApiError(response.status, 'Preuzimanje nije uspjelo.')
  }

  const blob = await response.blob()
  const file = new File([blob], `foto-${uuid.slice(0, 8)}.${extensionFor(blob.type)}`, { type: blob.type })

  if (!navigator.canShare({ files: [file] })) {
    throw new Error('Dijeljenje nije podržano.')
  }

  try {
    await navigator.share({ files: [file] })

    return 'shared'
  } catch (error) {
    if (error instanceof DOMException && error.name === 'AbortError') {
      return 'cancelled'
    }

    throw error
  }
}

export interface UploadResult {
  uuid: string
}

interface ErrorBody {
  message?: string
}

function parseBody(text: string): unknown {
  try {
    return JSON.parse(text)
  } catch {
    return null
  }
}

interface RateLimitBody extends ErrorBody {
  retry_after?: number
}

interface ValidationBody extends ErrorBody {
  errors?: { photo?: string[] }
}

/**
 * Maps the server's (English) validation message for the photo field to a
 * clear message for the guest.
 */
function validationMessage(body: unknown): string {
  const reason = (body as ValidationBody | null)?.errors?.photo?.[0] ?? ''

  if (reason.includes('larger than')) {
    return 'Fotografija je prevelika (maks. 50 MB).'
  }

  if (reason.includes('too many pixels')) {
    return 'Fotografija ima previše piksela. Smanjite rezoluciju kamere i pokušajte ponovo.'
  }

  if (reason.includes('HEIC')) {
    return 'HEIC fotografije nisu podržane. Promijenite format kamere na JPEG.'
  }

  if (reason.includes('could not be read')) {
    return 'Ovo nije ispravna fotografija ili je oštećena.'
  }

  return 'Fotografija nije prihvaćena. Podržani su JPEG, PNG i WebP do 50 MB.'
}

function uploadErrorMessage(status: number, body: unknown): string {
  if (status === 429) {
    const seconds = (body as RateLimitBody | null)?.retry_after

    return seconds
      ? `Previše slika u kratkom roku. Pokušajte ponovo za ${Math.ceil(seconds / 60)} min.`
      : 'Previše slika u kratkom roku. Pokušajte kasnije.'
  }

  if (status === 403) {
    return 'Slanje fotografija je zatvoreno.'
  }

  if (status === 404) {
    return 'Ova galerija više ne postoji.'
  }

  if (status === 413) {
    return 'Fotografija je prevelika (maks. 50 MB).'
  }

  if (status === 422) {
    return validationMessage(body)
  }

  if (status >= 500) {
    return 'Server trenutno ne može primiti fotografiju. Javite organizatoru.'
  }

  const message = (body as ErrorBody | null)?.message

  return message ?? 'Slanje nije uspjelo. Pokušajte ponovo.'
}

/** An upload that makes no progress for this long is cut off and retried. */
const UPLOAD_STALL_TIMEOUT_MS = 30_000

/**
 * True for errors worth retrying: no connection (0) and a busy or restarting
 * server or proxy (502-504). Safe to retry because the upload id makes the
 * server return the first photo instead of storing a second one.
 */
export function isRetryableUploadError(error: unknown): boolean {
  return error instanceof ApiError && (error.status === 0 || (error.status >= 502 && error.status <= 504))
}

/**
 * Upload one photo. XMLHttpRequest is used instead of fetch because fetch
 * does not report upload progress.
 *
 * `uploadId` is the same for every attempt of one photo. When a response is
 * lost after the server stored the photo, the retry gets that photo back
 * instead of creating a duplicate.
 */
export function uploadPhoto(
  roomCode: string,
  file: File,
  uploadId: string,
  onProgress: (fraction: number) => void,
): Promise<UploadResult> {
  return new Promise((resolve, reject) => {
    const request = new XMLHttpRequest()
    let stallTimer: number | undefined

    // A phone that loses signal mid-upload can leave the request hanging
    // forever, which would block every photo queued after it.
    const restartStallTimer = () => {
      window.clearTimeout(stallTimer)
      stallTimer = window.setTimeout(() => request.abort(), UPLOAD_STALL_TIMEOUT_MS)
    }

    request.open('POST', resolveUrl(roomPhotosPath(roomCode)))
    // Sends the device cookie the upload limits count by, also when the API is on another origin.
    request.withCredentials = true
    request.setRequestHeader('Accept', 'application/json')

    request.upload.onprogress = (event) => {
      restartStallTimer()

      if (event.lengthComputable) {
        onProgress(event.loaded / event.total)
      }
    }

    request.onload = () => {
      window.clearTimeout(stallTimer)
      const body = parseBody(request.responseText)

      if (request.status === 202) {
        resolve(body as UploadResult)

        return
      }

      reject(new ApiError(request.status, uploadErrorMessage(request.status, body)))
    }

    request.onerror = () => {
      window.clearTimeout(stallTimer)
      reject(new ApiError(0, 'Greška na mreži. Pokušajte ponovo.'))
    }

    request.onabort = () => {
      reject(new ApiError(0, 'Veza je prekinuta. Pokušajte ponovo.'))
    }

    const form = new FormData()
    form.append('photo', file)
    form.append('upload_id', uploadId)
    restartStallTimer()
    request.send(form)
  })
}
