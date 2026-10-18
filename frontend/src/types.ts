export interface Photo {
  uuid: string
  thumb_url: string
  web_url: string
  width: number
  height: number
  created_at: string
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
