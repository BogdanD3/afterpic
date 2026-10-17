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

function resolveUrl(path: string): string {
  return `${API_BASE}${path}`
}

async function getJson<T>(path: string): Promise<T | null> {
  const response = await fetch(resolveUrl(path), { headers: { Accept: 'application/json' } })

  if (response.status === 404 || response.status === 304) {
    return null
  }

  if (!response.ok) {
    throw new ApiError(response.status, 'Učitavanje nije uspjelo.')
  }

  return (await response.json()) as T
}

/**
 * `path` is the first page or a `links.next` path from the previous page
 * (the backend sends paths without host, so they work behind any proxy).
 */
export function fetchGalleryPage(path = '/api/photos'): Promise<GalleryPage | null> {
  return getJson<GalleryPage>(path)
}

/**
 * `cursor` is opaque: the first one comes from the gallery page, every next
 * one from the previous updates answer.
 */
export function fetchUpdates(cursor: string): Promise<UpdatesPage | null> {
  return getJson<UpdatesPage>(`/api/photos/updates?cursor=${encodeURIComponent(cursor)}`)
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
    return 'Previše slika u kratkom roku. Pokušajte kasnije.'
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

/**
 * Upload one photo. XMLHttpRequest is used instead of fetch because fetch
 * does not report upload progress.
 */
export function uploadPhoto(file: File, onProgress: (fraction: number) => void): Promise<UploadResult> {
  return new Promise((resolve, reject) => {
    const request = new XMLHttpRequest()

    request.open('POST', resolveUrl('/api/photos'))
    request.setRequestHeader('Accept', 'application/json')

    request.upload.onprogress = (event) => {
      if (event.lengthComputable) {
        onProgress(event.loaded / event.total)
      }
    }

    request.onload = () => {
      const body = parseBody(request.responseText)

      if (request.status === 202) {
        resolve(body as UploadResult)

        return
      }

      reject(new ApiError(request.status, uploadErrorMessage(request.status, body)))
    }

    request.onerror = () => {
      reject(new ApiError(0, 'Greška na mreži. Pokušajte ponovo.'))
    }

    const form = new FormData()
    form.append('photo', file)
    request.send(form)
  })
}
