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
  const [openIndex, setOpenIndex] = useState<number | null>(null)

  const closeLightbox = useCallback(() => setOpenIndex(null), [])

  const handleDelete = useCallback(
    async (uuid: string) => {
      if (adminToken === null) {
        return
      }

      const deletedIndex = photos.findIndex((photo) => photo.uuid === uuid)

      await deletePhoto(uuid, adminToken)
      removePhoto(uuid)

      // The next photo moves into the deleted one's place; step back only at the end.
      const remaining = photos.length - 1

      if (remaining === 0) {
        setOpenIndex(null)
      } else if (deletedIndex !== -1) {
        setOpenIndex(Math.min(deletedIndex, remaining - 1))
      }
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
          onOpen={setOpenIndex}
          onDismissUpload={uploads.removeItem}
        />
      </main>

      <CameraButton onFiles={uploads.enqueue} />

      {openIndex !== null && (
        <Lightbox
          photos={gallery.photos}
          index={openIndex}
          onClose={closeLightbox}
          onNavigate={setOpenIndex}
          onDelete={adminToken ? handleDelete : undefined}
        />
      )}
    </div>
  )
}
