import { rememberedRooms } from '../lib/myRooms'
import type { Room } from '../types'
import { Dialog } from './Dialog'

/** Rooms this phone has opened, so switching back is one tap. */
export function RoomSwitcher({ current, onClose }: { current: Room; onClose: () => void }) {
  const mainCode = current.event.main_room_code
  const rooms = rememberedRooms().filter((room) => room.code !== current.code)

  return (
    <Dialog title="Moje sobe" onClose={onClose}>
      <ul className="room-list">
        {!current.is_main && mainCode && !rooms.some((room) => room.code === mainCode) && (
          <li>
            <a href={`/r/${mainCode}`}>
              <strong>{current.event.name}</strong>
              <span>Glavna galerija</span>
            </a>
          </li>
        )}
        {rooms.map((room) => (
          <li key={room.code}>
            <a href={`/r/${room.code}`}>
              <strong>{room.name}</strong>
              {room.name !== room.eventName && <span>{room.eventName}</span>}
            </a>
          </li>
        ))}
      </ul>
      {rooms.length === 0 && (current.is_main || !mainCode) && (
        <p className="dialog-text">Ovdje će se pojaviti sobe koje otvorite na ovom telefonu.</p>
      )}
    </Dialog>
  )
}
