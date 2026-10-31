import { useEffect, useState } from 'react'
import { fetchRoom, fetchRoomRole } from '../api/rooms'
import type { AdminKeys } from '../api/photos'
import { forgetRoom, rememberRoom } from '../lib/myRooms'
import type { Room, RoomRole } from '../types'

export type RoomState = { status: 'loading' } | { status: 'missing' } | { status: 'error' } | { status: 'ready'; room: Room }

/** The room behind the link, and the admin role the held keys give in it. */
export function useRoom(code: string, keys: AdminKeys) {
  const [state, setState] = useState<RoomState>({ status: 'loading' })
  const [role, setRole] = useState<RoomRole>(null)
  const { adminToken, roomKey } = keys

  useEffect(() => {
    let cancelled = false

    fetchRoom(code)
      .then((room) => {
        if (cancelled) {
          return
        }

        if (!room) {
          forgetRoom(code)
          setState({ status: 'missing' })

          return
        }

        setState({ status: 'ready', room })
        rememberRoom({ code: room.code, name: room.name, eventName: room.event.name })
      })
      .catch(() => !cancelled && setState({ status: 'error' }))

    return () => {
      cancelled = true
    }
  }, [code])

  useEffect(() => {
    let cancelled = false

    fetchRoomRole(code, { adminToken, roomKey })
      .then((next) => !cancelled && setRole(next))
      .catch(() => !cancelled && setRole(null))

    return () => {
      cancelled = true
    }
  }, [code, adminToken, roomKey])

  const markMissing = () => {
    forgetRoom(code)
    setState({ status: 'missing' })
  }

  return { state, role, markMissing }
}
