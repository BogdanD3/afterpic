import { useCallback, useEffect, useState } from 'react'
import { deleteDevicePhotos, listEventDevices, setDeviceBlocked } from '../api/admin'
import { formatDate } from '../lib/links'
import type { AdminDevice } from '../types'

interface EventDevicesProps {
  token: string
  eventId: number
  onPhotosDeleted: () => void
  onError: (failure: unknown) => void
}

/** Super admin: the phones that uploaded to an event, most photos first. */
export function EventDevices({ token, eventId, onPhotosDeleted, onError }: EventDevicesProps) {
  const [devices, setDevices] = useState<AdminDevice[] | null>(null)

  const load = useCallback(() => {
    listEventDevices(token, eventId).then(setDevices).catch(onError)
  }, [eventId, onError, token])

  useEffect(load, [load])

  const removeAll = async (device: AdminDevice) => {
    if (!window.confirm(`Obrisati svih ${device.photos_count} fotografija ovog telefona na ovom događaju?`)) {
      return
    }

    try {
      await deleteDevicePhotos(token, device.id, { eventId })
      onPhotosDeleted()
      load()
    } catch (failure) {
      onError(failure)
    }
  }

  const toggleBlock = async (device: AdminDevice) => {
    try {
      const updated = await setDeviceBlocked(token, device.id, !device.blocked)
      setDevices((current) => current?.map((known) => (known.id === device.id ? { ...known, blocked: updated.blocked } : known)) ?? null)
    } catch (failure) {
      onError(failure)
    }
  }

  if (devices === null) {
    return <p className="admin-muted">Učitavam telefone…</p>
  }

  if (devices.length === 0) {
    return <p className="admin-muted">Još niko nije poslao fotografiju.</p>
  }

  return (
    <div className="device-list">
      {devices.map((device) => (
        <div className="room-row" key={device.id}>
          <div>
            <strong>{device.name ?? 'Bez imena'}</strong>
            <span className="admin-muted">
              {' '}
              · {device.photos_count} fotografija
              {device.client && ` · ${device.client}`}
              {device.last_seen_at && ` · zadnji put ${formatDate(device.last_seen_at)}`}
              {device.blocked && ' · blokiran'}
            </span>
          </div>
          <div className="room-row-actions">
            <button type="button" className="button button-secondary" onClick={() => toggleBlock(device)}>
              {device.blocked ? 'Odblokiraj' : 'Blokiraj'}
            </button>
            <button type="button" className="button button-danger" onClick={() => removeAll(device)}>
              Obriši sve
            </button>
          </div>
        </div>
      ))}
    </div>
  )
}
