import { useCallback, useState } from 'react'
import { deletePhoto } from './api/photos'
import { CameraButton } from './components/CameraButton'
import { Lightbox } from './components/Lightbox'
import { PhotoGrid } from './components/PhotoGrid'
import { useAdminToken } from './hooks/useAdminToken'
import { useGallery } from './hooks/useGallery'
import { useUploads } from './hooks/useUploads'

export default function App() {
  const gallery = useGallery()
  const { photos, removePhoto } = gallery
  const uploads = useUploads(gallery.addPhoto)
  const adminToken = useAdminToken()
  // The open photo is kept by uuid, so it stays the same photo when others
  // are added or deleted, and the lightbox closes when it is deleted itself.
  const [openUuid, setOpenUuid] = useState<string | null>(null)
  const openIndex = openUuid === null ? -1 : photos.findIndex((photo) => photo.uuid === openUuid)

  const closeLightbox = useCallback(() => setOpenUuid(null), [])

  const openPhotoAt = useCallback((index: number) => setOpenUuid(photos[index]?.uuid ?? null), [photos])

  const handleDelete = useCallback(
    async (uuid: string) => {
      if (adminToken === null) {
        return
      }

      const deletedIndex = photos.findIndex((photo) => photo.uuid === uuid)

      await deletePhoto(uuid, adminToken)
      removePhoto(uuid)

      // The next photo moves into the deleted one's place; step back only at the end.
      const remaining = photos.filter((photo) => photo.uuid !== uuid)

      setOpenUuid(remaining[Math.min(deletedIndex, remaining.length - 1)]?.uuid ?? null)
    },
    [adminToken, photos, removePhoto],
  )

  return (
    <div className="app">
      <main className="gallery-page">
        {gallery.hasError && (
          <p className="notice" role="alert">
            Galerija se trenutno ne može učitati.
          </p>
        )}

        <PhotoGrid
          photos={gallery.photos}
          uploads={uploads.items}
          isLoading={gallery.isLoading}
          hasMore={gallery.hasMore}
          onLoadMore={gallery.loadMore}
          onOpen={openPhotoAt}
          onDismissUpload={uploads.removeItem}
        />
      </main>

      <CameraButton onFiles={uploads.enqueue} />

      {openIndex !== -1 && (
        <Lightbox
          photos={gallery.photos}
          index={openIndex}
          onClose={closeLightbox}
          onNavigate={openPhotoAt}
          onDelete={adminToken ? handleDelete : undefined}
        />
      )}
    </div>
  )
}
