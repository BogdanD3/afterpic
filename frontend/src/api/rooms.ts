import type { Room, RoomRole } from '../types'
import { adminHeaders, ApiError, getJson, resolveUrl, type AdminKeys } from './photos'

function roomPath(code: string): string {
  return `/api/rooms/${encodeURIComponent(code)}`
}

/** The room behind a link, or null when it does not exist (or was deleted). */
export async function fetchRoom(code: string): Promise<Room | null> {
  const body = await getJson<{ data: Room }>(roomPath(code))

  return body?.data ?? null
}

/** The role the held keys give in this room. A wrong key gives null. */
export async function fetchRoomRole(code: string, keys: AdminKeys): Promise<RoomRole> {
  const headers = adminHeaders(keys)

  if (Object.keys(headers).length === 0) {
    return null
  }

  const response = await fetch(resolveUrl(`${roomPath(code)}/access`), {
    headers: { Accept: 'application/json', ...headers },
  })

  if (!response.ok) {
    return null
  }

  return ((await response.json()) as { role: RoomRole }).role
}

export interface CreatedRoom {
  room: Room
  adminKey: string
}

/** A guest creates a room for their group inside the event of `code`. */
export async function createGuestRoom(code: string, name: string): Promise<CreatedRoom> {
  const response = await fetch(resolveUrl(`${roomPath(code)}/rooms`), {
    method: 'POST',
    credentials: 'include',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    body: JSON.stringify({ name }),
  })

  if (response.status === 429) {
    throw new ApiError(429, 'Napravili ste previše soba danas. Pokušajte sutra.')
  }

  if (response.status === 403) {
    throw new ApiError(403, 'Na ovom događaju nije moguće praviti nove sobe.')
  }

  if (!response.ok) {
    throw new ApiError(response.status, 'Soba nije napravljena. Pokušajte ponovo.')
  }

  const body = (await response.json()) as { data: Room; admin_key: string }

  return { room: body.data, adminKey: body.admin_key }
}

/**
 * A ten-minute link to the ZIP of all the room's photos (host, room admin
 * or super admin). The browser opens it itself, so it carries a signature,
 * not the key.
 */
export async function requestDownloadLink(code: string, keys: AdminKeys): Promise<string> {
  const response = await fetch(resolveUrl(`${roomPath(code)}/download-link`), {
    method: 'POST',
    headers: { Accept: 'application/json', ...adminHeaders(keys) },
  })

  if (!response.ok) {
    throw new ApiError(response.status, 'Preuzimanje nije uspjelo. Pokušajte ponovo.')
  }

  return resolveUrl(((await response.json()) as { url: string }).url)
}

/** The host or room admin asks the organiser to delete the room (from the main room: the event). */
export async function requestRoomDeletion(code: string, keys: AdminKeys, reason: string): Promise<void> {
  const response = await fetch(resolveUrl(`${roomPath(code)}/deletion-request`), {
    method: 'POST',
    credentials: 'include',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...adminHeaders(keys) },
    body: JSON.stringify({ reason }),
  })

  if (!response.ok) {
    throw new ApiError(response.status, response.status === 429 ? 'Previše zahtjeva. Pokušajte kasnije.' : 'Zahtjev nije poslan.')
  }
}
