/**
 * Rooms this phone has opened, newest first, for the room switcher. Kept in
 * the browser only; a lost list is no problem, the links still work.
 */
export interface RememberedRoom {
  code: string
  name: string
  eventName: string
}

const STORAGE_KEY = 'myRooms'
const MAX_ROOMS = 20

export function rememberedRooms(): RememberedRoom[] {
  try {
    const list = JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '[]') as unknown

    return Array.isArray(list) ? (list as RememberedRoom[]) : []
  } catch {
    return []
  }
}

export function rememberRoom(room: RememberedRoom): void {
  try {
    const others = rememberedRooms().filter((known) => known.code !== room.code)

    localStorage.setItem(STORAGE_KEY, JSON.stringify([room, ...others].slice(0, MAX_ROOMS)))
  } catch {
    // Storage blocked (private mode): the switcher just stays empty.
  }
}

export function forgetRoom(code: string): void {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(rememberedRooms().filter((known) => known.code !== code)))
  } catch {
    // Nothing to forget without storage.
  }
}
