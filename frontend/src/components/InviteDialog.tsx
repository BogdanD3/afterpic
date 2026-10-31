import { roomUrl } from '../lib/links'
import type { Room } from '../types'
import { CopyButton } from './CopyButton'
import { Dialog } from './Dialog'
import { QrCode } from './QrCode'

/** Anyone in the room can invite others: show the QR code or send the link. */
export function InviteDialog({ room, onClose }: { room: Room; onClose: () => void }) {
  const url = roomUrl(room.code)

  return (
    <Dialog title={`Pozovi u: ${room.name}`} onClose={onClose}>
      <p className="dialog-text">Neka skeniraju kod ili im pošaljite link. Ko ima link, vidi i dodaje fotografije.</p>
      <div className="qr-box">
        <QrCode value={url} />
      </div>
      <p className="link-text">{url}</p>
      <div className="dialog-actions">
        <CopyButton text={url} label="Kopiraj link" />
      </div>
    </Dialog>
  )
}
