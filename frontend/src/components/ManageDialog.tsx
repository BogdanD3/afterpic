import { useState } from 'react'
import type { AdminKeys } from '../api/photos'
import { requestDownloadLink, requestRoomDeletion } from '../api/rooms'
import { formatDate } from '../lib/links'
import type { Room, RoomRole } from '../types'
import { Dialog } from './Dialog'
import { ReasonDialog } from './ReasonDialog'

interface ManageDialogProps {
  room: Room
  role: Exclude<RoomRole, null>
  keys: AdminKeys
  onClose: () => void
}

/**
 * For the host, a guest room's admin and the super admin: download every
 * photo, and ask the organiser to delete the room (the super admin deletes
 * directly on /admin instead).
 */
export function ManageDialog({ room, role, keys, onClose }: ManageDialogProps) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [askingDeletion, setAskingDeletion] = useState(false)
  const { event } = room
  const deletesWholeEvent = room.is_main

  const downloadAll = async () => {
    setBusy(true)
    setError(null)

    try {
      window.location.href = await requestDownloadLink(room.code, keys)
    } catch (failure) {
      setError(failure instanceof Error ? failure.message : 'Preuzimanje nije uspjelo.')
    } finally {
      setBusy(false)
    }
  }

  if (askingDeletion) {
    return (
      <ReasonDialog
        title={deletesWholeEvent ? 'Zatraži brisanje događaja' : 'Zatraži brisanje sobe'}
        text={
          deletesWholeEvent
            ? 'Organizator će obrisati cijeli događaj, sa svim sobama i fotografijama. Preuzmite fotografije prije toga.'
            : 'Organizator će obrisati ovu sobu i sve njene fotografije.'
        }
        onSend={(reason) => requestRoomDeletion(room.code, keys, reason)}
        onClose={onClose}
      />
    )
  }

  return (
    <Dialog title="Upravljanje" onClose={onClose}>
      <p className="dialog-text">
        {event.deletes_at
          ? `Galerija se automatski briše ${formatDate(event.deletes_at)}. Preuzmite fotografije prije toga.`
          : 'Ova galerija nema datum isteka.'}
      </p>
      <p className="dialog-text">Fotografiju brišete tako što je otvorite i dodirnete „Obriši”.</p>
      {error && <p className="form-error">{error}</p>}
      <div className="dialog-actions">
        <button type="button" className="button" onClick={downloadAll} disabled={busy}>
          {busy ? 'Pripremam…' : 'Preuzmi sve fotografije (ZIP)'}
        </button>
        {role !== 'super' && (
          <button type="button" className="button button-secondary" onClick={() => setAskingDeletion(true)}>
            {deletesWholeEvent ? 'Zatraži brisanje događaja' : 'Zatraži brisanje sobe'}
          </button>
        )}
        {role === 'super' && (
          <a className="button button-secondary" href="/admin">
            Otvori admin stranicu
          </a>
        )}
      </div>
    </Dialog>
  )
}
