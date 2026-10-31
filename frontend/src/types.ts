export interface Photo {
  uuid: string
  thumb_url: string
  web_url: string
  width: number
  height: number
  created_at: string
  // The name the uploader chose, if any.
  uploader_name: string | null
}

export interface GalleryPage {
  data: Photo[]
  links: { next: string | null }
  updates_cursor: string
}

export interface UpdatesPage {
  data: Photo[]
  cursor: string
  has_more: boolean
  // Uuids of photos deleted since the last poll, to remove from the gallery.
  deleted: string[]
}

export interface SinglePhoto {
  data: Photo
}

export interface RoomEvent {
  name: string
  main_room_code: string | null
  guest_rooms_enabled: boolean
  // False once the event expired or the admin closed uploads.
  uploads_open: boolean
  expires_at: string | null
  deletes_at: string | null
}

export interface Room {
  code: string
  name: string
  is_main: boolean
  event: RoomEvent
}

/** What a key gives in a room: super admin, event host, guest room admin, or nothing. */
export type RoomRole = 'super' | 'host' | 'room' | null

export interface AdminRoom {
  code: string
  name: string
  is_main: boolean
  admin_key: string | null
  photos_count: number
  created_at: string
}

export interface AdminEvent {
  id: number
  name: string
  guest_rooms_enabled: boolean
  uploads_open: boolean
  accepts_uploads: boolean
  expires_at: string | null
  deletes_at: string | null
  created_at: string
  host_key: string
  photos_count: number
  rooms: AdminRoom[]
}

export interface AdminDevice {
  id: string
  name: string | null
  // A short description such as "iPhone · Safari".
  client: string | null
  blocked: boolean
  last_seen_at: string | null
  photos_count: number | null
}
