import { useEffect, useRef } from 'react'
import type { UploadItem } from '../hooks/useUploads'
import '../upload-errors.css'
import type { Photo } from '../types'

interface PhotoGridProps {
  photos: Photo[]
  uploads: UploadItem[]
  isLoading: boolean
  hasMore: boolean
  onLoadMore: () => void
  onOpen: (index: number) => void
  onDismissUpload: (id: string) => void
}

function UploadTile({ upload, onDismiss }: { upload: UploadItem; onDismiss: (id: string) => void }) {
  const isFailed = upload.status === 'failed'

  if (isFailed) {
    return (
      <div className="tile tile-failed" role="alert">
        <img className="tile-failed-preview" src={upload.previewUrl} alt="" />
        <span>{upload.error}</span>
        <button type="button" className="tile-dismiss" onClick={() => onDismiss(upload.id)}>
          Ukloni
        </button>
      </div>
    )
  }

  const label = upload.status === 'uploading' ? 'Šaljem…' : 'Obrađujem…'
  const percent = Math.round(upload.progress * 100)

  return (
    <div className="tile tile-upload" role="status" aria-label={label}>
      <img src={upload.previewUrl} alt="" />
      <span className="tile-label">{label}</span>
      {upload.status === 'uploading' && (
        <div className="progress" aria-hidden="true">
          <span style={{ width: `${percent}%` }} />
        </div>
      )}
    </div>
  )
}

export function PhotoGrid({
  photos,
  uploads,
  isLoading,
  hasMore,
  onLoadMore,
  onOpen,
  onDismissUpload,
}: PhotoGridProps) {
  const sentinelRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const node = sentinelRef.current

    if (!node || !hasMore) {
      return
    }

    const observer = new IntersectionObserver(
      (entries) => {
        if (entries[0]?.isIntersecting) {
          onLoadMore()
        }
      },
      { rootMargin: '400px' },
    )

    observer.observe(node)

    return () => observer.disconnect()
  }, [hasMore, onLoadMore])

  if (isLoading) {
    return (
      <div className="photo-grid" aria-busy="true">
        {Array.from({ length: 9 }, (_, index) => (
          <div key={index} className="tile tile-skeleton" />
        ))}
      </div>
    )
  }

  if (photos.length === 0 && uploads.length === 0) {
    return <p className="empty-state">Još nema fotografija. Budite prvi!</p>
  }

  return (
    <>
      <div className="photo-grid">
        {uploads.map((upload) => (
          <UploadTile key={upload.id} upload={upload} onDismiss={onDismissUpload} />
        ))}

        {photos.map((photo, index) => (
          <button
            key={photo.uuid}
            type="button"
            className="tile"
            onClick={() => onOpen(index)}
            aria-label="Otvori fotografiju"
          >
            <img
              src={photo.thumb_url}
              alt=""
              loading="lazy"
              decoding="async"
              width={400}
              height={400}
            />
          </button>
        ))}
      </div>

      <div ref={sentinelRef} className="grid-sentinel" aria-hidden="true" />
    </>
  )
}
