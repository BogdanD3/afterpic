import type { AdminEvent } from '../types'
import { ApiError, resolveUrl } from './photos'

/** Super admin API. Every call needs the admin token; 401 means a wrong token. */
async function adminRequest<T>(token: string, method: string, path: string, body?: unknown): Promise<T> {
  const response = await fetch(resolveUrl(`/api/admin${path}`), {
    method,
    headers: {
      Accept: 'application/json',
      'X-Admin-Token': token,
      ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
    },
    body: body === undefined ? undefined : JSON.stringify(body),
  })

  if (response.status === 401 || response.status === 404) {
    throw new ApiError(401, 'Pogrešan admin token.')
  }

  if (response.status === 429) {
    throw new ApiError(429, 'Previše pogrešnih pokušaja. Sačekajte minut.')
  }

  if (!response.ok) {
    throw new ApiError(response.status, 'Zahtjev nije uspio. Pokušajte ponovo.')
  }

  return (await response.json()) as T
}

export async function listEvents(token: string): Promise<AdminEvent[]> {
  return (await adminRequest<{ data: AdminEvent[] }>(token, 'GET', '/events')).data
}

export interface NewEvent {
  name: string
  // Days until expiry; 0 never expires; null uses the server default (PHOTOS_EVENT_DAYS).
  days: number | null
  guest_rooms_enabled: boolean
}

export async function createEvent(token: string, event: NewEvent): Promise<AdminEvent> {
  return (await adminRequest<{ data: AdminEvent }>(token, 'POST', '/events', event)).data
}

export interface EventChanges {
  name?: string
  guest_rooms_enabled?: boolean
  uploads_open?: boolean
  days?: number
}

export async function updateEvent(token: string, id: number, changes: EventChanges): Promise<AdminEvent> {
  return (await adminRequest<{ data: AdminEvent }>(token, 'PATCH', `/events/${id}`, changes)).data
}

export async function deleteEvent(token: string, id: number): Promise<void> {
  await adminRequest(token, 'DELETE', `/events/${id}`)
}

export async function deleteRoom(token: string, code: string): Promise<void> {
  await adminRequest(token, 'DELETE', `/rooms/${encodeURIComponent(code)}`)
}

export async function rotateRoomCode(token: string, code: string): Promise<string> {
  return (await adminRequest<{ code: string }>(token, 'POST', `/rooms/${encodeURIComponent(code)}/rotate-code`)).code
}
