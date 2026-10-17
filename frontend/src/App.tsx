import { useCallback, useState } from 'react'
import { CameraButton } from './components/CameraButton'
import { Lightbox } from './components/Lightbox'
import { PhotoGrid } from './components/PhotoGrid'
import { useGallery } from './hooks/useGallery'
import { useUploads } from './hooks/useUploads'

export default function App() {
  const gallery = useGallery()
  const uploads = useUploads(gallery.addPhoto)
  const [openIndex, setOpenIndex] = useState<number | null>(null)

  const closeLightbox = useCallback(() => setOpenIndex(null), [])

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
        />
      )}
    </div>
  )
}
