import { useState, type FormEvent } from 'react'
import { createGuestRoom, type CreatedRoom } from '../api/rooms'
import { roomAdminUrl, roomUrl } from '../lib/links'
import { CopyButton } from './CopyButton'
import { Dialog } from './Dialog'
import { QrCode } from './QrCode'

/**
 * A guest makes a room for their group. Afterwards they see the invite
 * (QR code and link) and their admin link, which they must keep: it is the
 * only way to manage the room.
 */
export function NewRoomDialog({ eventRoomCode, onClose }: { eventRoomCode: string; onClose: () => void }) {
  const [name, setName] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [created, setCreated] = useState<CreatedRoom | null>(null)

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setBusy(true)
    setError(null)

    try {
      setCreated(await createGuestRoom(eventRoomCode, name.trim()))
    } catch (failure) {
      setError(failure instanceof Error ? failure.message : 'Soba nije napravljena.')
    } finally {
      setBusy(false)
    }
  }

  if (created) {
    const inviteUrl = roomUrl(created.room.code)
    const adminUrl = roomAdminUrl(created.room.code, created.adminKey)

    return (
      <Dialog title={`Soba „${created.room.name}” je spremna`} onClose={onClose}>
        <p className="dialog-text">Pokažite kod ekipi ili im pošaljite link. Samo ko ima link vidi ovu sobu.</p>
        <div className="qr-box">
          <QrCode value={inviteUrl} />
        </div>
        <p className="link-text">{inviteUrl}</p>
        <div className="dialog-actions">
          <CopyButton text={inviteUrl} label="Kopiraj link za ekipu" />
        </div>

        <div className="admin-link-box">
          <strong>Vaš admin link</strong>
          <p>Sačuvajte ga (npr. pošaljite sebi poruku). Samo s njim možete brisati fotografije u ovoj sobi. Ne dijelite ga.</p>
          <CopyButton text={adminUrl} label="Kopiraj admin link" />
        </div>

        <div className="dialog-actions">
          <a className="button" href={adminUrl}>
            Otvori sobu
          </a>
        </div>
      </Dialog>
    )
  }

  return (
    <Dialog title="Nova soba" onClose={onClose}>
      <form onSubmit={submit} className="form">
        <label className="field">
          <span>Naziv sobe</span>
          <input
            value={name}
            onChange={(event) => setName(event.target.value)}
            maxLength={60}
            placeholder="npr. Ekipa sa posla"
            required
            autoFocus
          />
        </label>
        <p className="dialog-text">Fotografije iz ove sobe vide samo oni kojima pošaljete link.</p>
        {error && <p className="form-error">{error}</p>}
        <div className="dialog-actions">
          <button type="submit" className="button" disabled={busy || name.trim() === ''}>
            {busy ? 'Pravim…' : 'Napravi sobu'}
          </button>
        </div>
      </form>
    </Dialog>
  )
}
