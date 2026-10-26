import type { AdminDevice, AdminEvent } from '../types'
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

export async function listEventDevices(token: string, eventId: number): Promise<AdminDevice[]> {
  return (await adminRequest<{ data: AdminDevice[] }>(token, 'GET', `/devices?event_id=${eventId}`)).data
}

export async function fetchPhotoDevice(token: string, uuid: string): Promise<AdminDevice | null> {
  return (await adminRequest<{ data: AdminDevice | null }>(token, 'GET', `/photos/${uuid}/device`)).data
}

/** Delete every photo of a phone in one event, named by its id or by one of its room codes. */
export async function deleteDevicePhotos(
  token: string,
  deviceId: string,
  scope: { eventId: number } | { roomCode: string },
): Promise<number> {
  const query = 'eventId' in scope ? `event_id=${scope.eventId}` : `room=${encodeURIComponent(scope.roomCode)}`

  return (await adminRequest<{ deleted: number }>(token, 'DELETE', `/devices/${deviceId}/photos?${query}`)).deleted
}

export async function setDeviceBlocked(token: string, deviceId: string, blocked: boolean): Promise<AdminDevice> {
  return (await adminRequest<{ data: AdminDevice }>(token, blocked ? 'POST' : 'DELETE', `/devices/${deviceId}/block`)).data
}

export interface AdminStats {
  events_active: number
  events_expired: number
  photos: number
  photos_today: number
  devices: number
  open_requests: number
  disk: { total_bytes: number; free_bytes: number; used_percent: number | null }
}

export async function fetchStats(token: string): Promise<AdminStats> {
  return adminRequest<AdminStats>(token, 'GET', '/stats')
}

export interface AdminRequest {
  id: number
  type: 'remove_photo' | 'delete_room'
  status: 'open' | 'approved' | 'rejected'
  reason: string
  room_name: string
  event_name: string
  // Null once the room is gone.
  room_code: string | null
  is_main_room: boolean
  photo: { uuid: string; thumb_url: string } | null
  from: string | null
  created_at: string
  resolved_at: string | null
}

export async function listRequests(token: string, onlyOpen: boolean): Promise<{ data: AdminRequest[]; open_count: number }> {
  return adminRequest(token, 'GET', `/requests?status=${onlyOpen ? 'open' : 'all'}`)
}

export async function resolveRequest(token: string, id: number, decision: 'approve' | 'reject'): Promise<void> {
  await adminRequest(token, 'POST', `/requests/${id}/${decision}`)
}
