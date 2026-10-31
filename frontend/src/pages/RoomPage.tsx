import { useCallback, useState } from 'react'
import { wasAskedForName } from '../api/me'
import { deletePhoto, requestPhotoRemoval } from '../api/photos'
import { CameraButton } from '../components/CameraButton'
import { InviteDialog } from '../components/InviteDialog'
import { Lightbox } from '../components/Lightbox'
import { ManageDialog } from '../components/ManageDialog'
import { NameDialog } from '../components/NameDialog'
import { NewRoomDialog } from '../components/NewRoomDialog'
import { PhotoGrid } from '../components/PhotoGrid'
import { ReasonDialog } from '../components/ReasonDialog'
import { RoomSwitcher } from '../components/RoomSwitcher'
import { useAdminToken, useRoomKey } from '../hooks/useAdminToken'
import { useGallery } from '../hooks/useGallery'
import { useRoom } from '../hooks/useRoom'
import { useUploads } from '../hooks/useUploads'
import { formatDate } from '../lib/links'
import type { Room, RoomRole } from '../types'
import '../room.css'

const ROLE_LABELS: Record<Exclude<RoomRole, null>, string> = {
  super: 'Admin',
  host: 'Domaćin',
  room: 'Admin sobe',
}

type OpenDialog = 'invite' | 'new-room' | 'rooms' | 'name' | 'manage' | null

export function RoomPage({ code }: { code: string }) {
  const [adminToken] = useAdminToken()
  const [roomKey] = useRoomKey(code)
  const { state, role, markMissing } = useRoom(code, { adminToken, roomKey })

  if (state.status === 'loading') {
    return <div className="room-loading" aria-busy="true" />
  }

  if (state.status === 'missing') {
    return (
      <main className="message-page">
        <h1>Ova galerija ne postoji</h1>
        <p>Link je pogrešan, ili je galerija istekla ili obrisana.</p>
      </main>
    )
  }

  if (state.status === 'error') {
    return (
      <main className="message-page">
        <h1>Galerija se ne može učitati</h1>
        <p>Provjerite internet vezu i pokušajte ponovo.</p>
        <button type="button" className="button" onClick={() => window.location.reload()}>
          Pokušaj ponovo
        </button>
      </main>
    )
  }

  return <RoomGallery room={state.room} role={role} adminToken={adminToken} roomKey={roomKey} onRoomGone={markMissing} />
}

interface RoomGalleryProps {
  room: Room
  role: RoomRole
  adminToken: string | null
  roomKey: string | null
  onRoomGone: () => void
}

function RoomGallery({ room, role, adminToken, roomKey, onRoomGone }: RoomGalleryProps) {
  const gallery = useGallery(room.code, onRoomGone)
  const { photos, removePhoto } = gallery
  const uploads = useUploads(room.code, gallery.addPhoto)
  const [dialog, setDialog] = useState<OpenDialog>(null)
  const [removalUuid, setRemovalUuid] = useState<string | null>(null)
  const closeDialog = useCallback(() => setDialog(null), [])
  const { event } = room

  // The open photo is kept by uuid, so it stays the same photo when others
  // are added or deleted, and the lightbox closes when it is deleted itself.
  const [openUuid, setOpenUuid] = useState<string | null>(null)
  const openIndex = openUuid === null ? -1 : photos.findIndex((photo) => photo.uuid === openUuid)

  const closeLightbox = useCallback(() => setOpenUuid(null), [])

  const openPhotoAt = useCallback((index: number) => setOpenUuid(photos[index]?.uuid ?? null), [photos])

  const handleDelete = useCallback(
    async (uuid: string) => {
      const deletedIndex = photos.findIndex((photo) => photo.uuid === uuid)

      await deletePhoto(uuid, { adminToken, roomKey })
      removePhoto(uuid)

      // The next photo moves into the deleted one's place; step back only at the end.
      const remaining = photos.filter((photo) => photo.uuid !== uuid)

      setOpenUuid(remaining[Math.min(deletedIndex, remaining.length - 1)]?.uuid ?? null)
    },
    [adminToken, photos, removePhoto, roomKey],
  )

  // The upload starts right away; the optional name is asked once, next to it.
  const takePhotos = useCallback(
    (files: File[]) => {
      uploads.enqueue(files)

      if (!wasAskedForName()) {
        setDialog('name')
      }
    },
    [uploads],
  )

  const canCreateRooms = event.guest_rooms_enabled && event.uploads_open && event.main_room_code !== null

  return (
    <div className="app">
      <header className="room-header">
        <div className="room-title">
          <h1>{room.name}</h1>
          {!room.is_main && <p>{event.name}</p>}
        </div>
        <div className="room-actions">
          {role && (
            <button type="button" className="role-badge" onClick={() => setDialog('manage')}>
              {ROLE_LABELS[role]} · Upravljanje
            </button>
          )}
          <button type="button" className="chip" onClick={() => setDialog('invite')}>
            Pozovi
          </button>
          <button type="button" className="chip" onClick={() => setDialog('rooms')}>
            Sobe
          </button>
          {canCreateRooms && (
            <button type="button" className="chip chip-accent" onClick={() => setDialog('new-room')}>
              + Nova soba
            </button>
          )}
        </div>
      </header>

      {!event.uploads_open && (
        <p className="banner">
          Slanje fotografija je zatvoreno.
          {event.deletes_at && ` Galeriju možete pregledati i preuzeti do ${formatDate(event.deletes_at)}.`}
        </p>
      )}

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

        <p className="privacy-note">
          Fotografije u ovoj galeriji vidi svako ko ima link. Želite da se neka fotografija ukloni? Otvorite je i dodirnite
          „Ukloni?”.
        </p>
      </main>

      {event.uploads_open && <CameraButton onFiles={takePhotos} />}

      {openIndex !== -1 && (
        <Lightbox
          photos={gallery.photos}
          index={openIndex}
          onClose={closeLightbox}
          onNavigate={openPhotoAt}
          onDelete={role ? handleDelete : undefined}
          onRequestRemoval={setRemovalUuid}
          moderation={role === 'super' && adminToken ? { adminToken, roomCode: room.code } : undefined}
        />
      )}

      {dialog === 'invite' && <InviteDialog room={room} onClose={closeDialog} />}
      {dialog === 'rooms' && <RoomSwitcher current={room} onClose={closeDialog} onChangeName={() => setDialog('name')} />}
      {dialog === 'name' && <NameDialog onClose={closeDialog} />}
      {dialog === 'manage' && role && (
        <ManageDialog room={room} role={role} keys={{ adminToken, roomKey }} onClose={closeDialog} />
      )}
      {removalUuid && (
        <ReasonDialog
          title="Zatraži uklanjanje fotografije"
          text="Organizator će pogledati zahtjev i ukloniti fotografiju. Napišite zašto (npr. „Na slici sam ja”)."
          onSend={(reason) => requestPhotoRemoval(removalUuid, reason)}
          onClose={() => setRemovalUuid(null)}
        />
      )}
      {dialog === 'new-room' && event.main_room_code && (
        <NewRoomDialog eventRoomCode={event.main_room_code} onClose={closeDialog} />
      )}
    </div>
  )
}
