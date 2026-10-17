import { useEffect, useRef, useState } from 'react'
import { canSharePhotos, downloadPhoto, sharePhoto } from '../api/photos'
import '../lightbox-download.css'
import type { Photo } from '../types'

function DownloadButtons({ uuid }: { uuid: string }) {
  const [shareState, setShareState] = useState<'idle' | 'busy' | 'error'>('idle')

  const handleShare = async () => {
    setShareState('busy')

    try {
      await sharePhoto(uuid)
      setShareState('idle')
    } catch {
      setShareState('error')
    }
  }

  return (
    <div className="lightbox-actions">
      <button type="button" className="lightbox-download" onClick={() => downloadPhoto(uuid)}>
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path
            d="M12 4v11m0 0-4-4m4 4 4-4M5 19h14"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
          />
        </svg>
        <span>Preuzmi</span>
      </button>

      {canSharePhotos() && (
        <button
          type="button"
          className={`lightbox-download${shareState === 'error' ? ' is-error' : ''}`}
          onClick={handleShare}
          disabled={shareState === 'busy'}
        >
          <span>
            {shareState === 'busy' ? 'Učitavam…' : shareState === 'error' ? 'Nije uspjelo' : 'Podijeli'}
          </span>
        </button>
      )}
    </div>
  )
}

interface LightboxProps {
  photos: Photo[]
  index: number
  onClose: () => void
  onNavigate: (index: number) => void
}

const SWIPE_THRESHOLD_PX = 50

export function Lightbox({ photos, index, onClose, onNavigate }: LightboxProps) {
  const touchStartX = useRef<number | null>(null)
  const photo = photos[index]
  const hasPrevious = index > 0
  const hasNext = index < photos.length - 1

  useEffect(() => {
    const handleKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        onClose()
      } else if (event.key === 'ArrowLeft' && hasPrevious) {
        onNavigate(index - 1)
      } else if (event.key === 'ArrowRight' && hasNext) {
        onNavigate(index + 1)
      }
    }

    window.addEventListener('keydown', handleKey)

    return () => window.removeEventListener('keydown', handleKey)
  }, [hasNext, hasPrevious, index, onClose, onNavigate])

  if (!photo) {
    return null
  }

  return (
    <div
      className="lightbox"
      role="dialog"
      aria-modal="true"
      aria-label="Fotografija"
      onTouchStart={(event) => {
        touchStartX.current = event.touches[0]?.clientX ?? null
      }}
      onTouchEnd={(event) => {
        if (touchStartX.current === null) {
          return
        }

        const delta = (event.changedTouches[0]?.clientX ?? 0) - touchStartX.current
        touchStartX.current = null

        if (delta < -SWIPE_THRESHOLD_PX && hasNext) {
          onNavigate(index + 1)
        } else if (delta > SWIPE_THRESHOLD_PX && hasPrevious) {
          onNavigate(index - 1)
        }
      }}
    >
      <button type="button" className="lightbox-close" onClick={onClose} aria-label="Zatvori">
        ×
      </button>

      {hasPrevious && (
        <button
          type="button"
          className="lightbox-nav prev"
          onClick={() => onNavigate(index - 1)}
          aria-label="Prethodna"
        >
          ‹
        </button>
      )}

      <img className="lightbox-photo" src={photo.web_url} alt="" onClick={onClose} />

      <DownloadButtons key={photo.uuid} uuid={photo.uuid} />

      {hasNext && (
        <button
          type="button"
          className="lightbox-nav next"
          onClick={() => onNavigate(index + 1)}
          aria-label="Sljedeća"
        >
          ›
        </button>
      )}
    </div>
  )
}
