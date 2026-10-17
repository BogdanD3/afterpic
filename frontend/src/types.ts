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
}

export interface SinglePhoto {
  data: Photo
}
