import { useEffect, useState } from 'react'
import { deleteDevicePhotos, fetchPhotoDevice, setDeviceBlocked } from '../api/admin'
import type { AdminDevice } from '../types'

interface PhotoModerationProps {
  uuid: string
  adminToken: string
  roomCode: string
  onDone: () => void
}

/**
 * Super admin only, in the full-screen photo: which phone sent it, and in
 * one tap remove everything that phone sent to this event and block it.
 */
export function PhotoModeration({ uuid, adminToken, roomCode, onDone }: PhotoModerationProps) {
  const [device, setDevice] = useState<AdminDevice | null | undefined>(undefined)
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false

    fetchPhotoDevice(adminToken, uuid)
      .then((found) => !cancelled && setDevice(found))
      .catch(() => !cancelled && setDevice(null))

    return () => {
      cancelled = true
    }
  }, [adminToken, uuid])

  if (!device) {
    return null
  }

  const removeAllAndBlock = async () => {
    if (!window.confirm('Obrisati sve fotografije ovog telefona na ovom događaju i blokirati ga?')) {
      return
    }

    setBusy(true)

    try {
      const deleted = await deleteDevicePhotos(adminToken, device.id, { roomCode })
      setDevice(await setDeviceBlocked(adminToken, device.id, true))
      setMessage(`Obrisano ${deleted}, telefon blokiran.`)
      window.setTimeout(onDone, 1200)
    } catch {
      setMessage('Nije uspjelo.')
      setBusy(false)
    }
  }

  return (
    <div className="moderation">
      <span>
        Telefon: {device.name ?? 'bez imena'}
        {device.client && ` · ${device.client}`}
        {device.blocked && ' · blokiran'}
      </span>
      {message ? (
        <span>{message}</span>
      ) : (
        <button type="button" onClick={removeAllAndBlock} disabled={busy}>
          Sve od ovog telefona: obriši i blokiraj
        </button>
      )}
    </div>
  )
}
