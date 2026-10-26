import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { createEvent, deleteEvent, deleteRoom, listEvents, rotateRoomCode, updateEvent, type EventChanges } from '../api/admin'
import { ApiError } from '../api/photos'
import { CopyButton } from '../components/CopyButton'
import { EventDevices } from '../components/EventDevices'
import { QrCode } from '../components/QrCode'
import { useAdminToken } from '../hooks/useAdminToken'
import { formatDate, roomAdminUrl, roomUrl } from '../lib/links'
import type { AdminEvent, AdminRoom } from '../types'
import '../admin.css'

/**
 * The super admin page: the events you sell, their links and QR codes,
 * switches during the event, and deleting.
 */
export function AdminPage() {
  const [token, setToken] = useAdminToken()

  if (!token) {
    return <AdminSignIn onSignIn={setToken} />
  }

  return <AdminDashboard token={token} onSignOut={() => setToken(null)} />
}

function AdminSignIn({ onSignIn }: { onSignIn: (token: string, remember: boolean) => void }) {
  const [value, setValue] = useState('')
  const [remember, setRemember] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setBusy(true)
    setError(null)

    try {
      await listEvents(value.trim())
      onSignIn(value.trim(), remember)
    } catch (failure) {
      setError(failure instanceof Error ? failure.message : 'Prijava nije uspjela.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <main className="admin-page admin-sign-in">
      <h1>Admin</h1>
      <form className="form" onSubmit={submit}>
        <label className="field">
          <span>Admin token (PHOTOS_ADMIN_TOKEN)</span>
          <input type="password" value={value} onChange={(event) => setValue(event.target.value)} autoFocus required />
        </label>
        <label className="checkbox">
          <input type="checkbox" checked={remember} onChange={(event) => setRemember(event.target.checked)} />
          <span>Zapamti na ovom uređaju</span>
        </label>
        {error && <p className="form-error">{error}</p>}
        <button type="submit" className="button" disabled={busy}>
          {busy ? 'Provjeravam…' : 'Prijavi se'}
        </button>
      </form>
    </main>
  )
}

function AdminDashboard({ token, onSignOut }: { token: string; onSignOut: () => void }) {
  const [events, setEvents] = useState<AdminEvent[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [printing, setPrinting] = useState<{ name: string; url: string } | null>(null)

  const handleError = useCallback(
    (failure: unknown) => {
      if (failure instanceof ApiError && failure.status === 401) {
        onSignOut()

        return
      }

      setError(failure instanceof Error ? failure.message : 'Zahtjev nije uspio.')
    },
    [onSignOut],
  )

  const reload = useCallback(() => {
    listEvents(token).then(setEvents).catch(handleError)
  }, [handleError, token])

  useEffect(reload, [reload])

  useEffect(() => {
    if (!printing) {
      return
    }

    const done = () => setPrinting(null)
    window.addEventListener('afterprint', done)
    window.print()

    return () => window.removeEventListener('afterprint', done)
  }, [printing])

  const replace = (updated: AdminEvent) =>
    setEvents((current) => current?.map((event) => (event.id === updated.id ? updated : event)) ?? null)

  return (
    <>
      <main className="admin-page">
        <header className="admin-header">
          <h1>Događaji</h1>
          <button type="button" className="button button-secondary" onClick={onSignOut}>
            Odjava
          </button>
        </header>

        {error && (
          <p className="notice" role="alert" onClick={() => setError(null)}>
            {error}
          </p>
        )}

        <NewEventForm
          token={token}
          onCreated={(event) => setEvents((current) => [event, ...(current ?? [])])}
          onError={handleError}
        />

        {events === null && <p className="admin-muted">Učitavam…</p>}
        {events?.length === 0 && <p className="admin-muted">Još nema događaja. Napravite prvi iznad.</p>}

        {events?.map((event) => (
          <EventCard
            key={event.id}
            event={event}
            token={token}
            onChanged={replace}
            onReload={reload}
            onRemoved={() => setEvents((current) => current?.filter((known) => known.id !== event.id) ?? null)}
            onPrint={(name, url) => setPrinting({ name, url })}
            onError={handleError}
          />
        ))}
      </main>

      {printing && <TableCard name={printing.name} url={printing.url} />}
    </>
  )
}

interface NewEventFormProps {
  token: string
  onCreated: (event: AdminEvent) => void
  onError: (failure: unknown) => void
}

function NewEventForm({ token, onCreated, onError }: NewEventFormProps) {
  const [name, setName] = useState('')
  const [days, setDays] = useState('')
  const [guestRooms, setGuestRooms] = useState(false)
  const [busy, setBusy] = useState(false)

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setBusy(true)

    try {
      onCreated(
        await createEvent(token, {
          name: name.trim(),
          days: days === '' ? null : Number(days),
          guest_rooms_enabled: guestRooms,
        }),
      )
      setName('')
      setDays('')
      setGuestRooms(false)
    } catch (failure) {
      onError(failure)
    } finally {
      setBusy(false)
    }
  }

  return (
    <form className="admin-card form" onSubmit={submit}>
      <h2>Novi događaj</h2>
      <label className="field">
        <span>Naziv (vide ga gosti)</span>
        <input value={name} onChange={(event) => setName(event.target.value)} maxLength={80} placeholder="npr. Rođendan Ana" required />
      </label>
      <label className="field">
        <span>Trajanje u danima (prazno = zadano iz .env, 0 = bez isteka)</span>
        <input type="number" min={0} max={365} value={days} onChange={(event) => setDays(event.target.value)} placeholder="zadano" />
      </label>
      <label className="checkbox">
        <input type="checkbox" checked={guestRooms} onChange={(event) => setGuestRooms(event.target.checked)} />
        <span>Gosti mogu praviti svoje sobe</span>
      </label>
      <button type="submit" className="button" disabled={busy || name.trim() === ''}>
        {busy ? 'Pravim…' : 'Napravi događaj'}
      </button>
    </form>
  )
}

function eventStatus(event: AdminEvent): { label: string; tone: 'ok' | 'warn' | 'off' } {
  if (event.accepts_uploads) {
    return { label: 'Aktivan', tone: 'ok' }
  }

  if (event.expires_at && new Date(event.expires_at) <= new Date()) {
    return { label: `Istekao, briše se ${event.deletes_at ? formatDate(event.deletes_at) : ''}`, tone: 'off' }
  }

  return { label: 'Slanje zatvoreno', tone: 'warn' }
}

interface EventCardProps {
  event: AdminEvent
  token: string
  onChanged: (event: AdminEvent) => void
  onReload: () => void
  onRemoved: () => void
  onPrint: (name: string, url: string) => void
  onError: (failure: unknown) => void
}

function EventCard({ event, token, onChanged, onReload, onRemoved, onPrint, onError }: EventCardProps) {
  const [open, setOpen] = useState(false)
  const [showDevices, setShowDevices] = useState(false)
  const [days, setDays] = useState('')
  const [busy, setBusy] = useState(false)
  const main = event.rooms.find((room) => room.is_main)
  const guestRooms = event.rooms.filter((room) => !room.is_main)
  const status = eventStatus(event)

  const change = async (changes: EventChanges) => {
    setBusy(true)

    try {
      onChanged(await updateEvent(token, event.id, changes))
    } catch (failure) {
      onError(failure)
    } finally {
      setBusy(false)
    }
  }

  const remove = async () => {
    const typed = window.prompt(
      `Brisanje je trajno: ${event.rooms.length} soba i ${event.photos_count} fotografija.\nUpišite naziv događaja da potvrdite:\n${event.name}`,
    )

    if (typed?.trim() !== event.name) {
      return
    }

    try {
      await deleteEvent(token, event.id)
      onRemoved()
    } catch (failure) {
      onError(failure)
    }
  }

  return (
    <section className="admin-card event-card">
      <button type="button" className="event-summary" onClick={() => setOpen(!open)} aria-expanded={open}>
        <div>
          <h2>{event.name}</h2>
          <p className="admin-muted">
            {event.photos_count} fotografija · {event.rooms.length} {event.rooms.length === 1 ? 'soba' : 'sobe'} ·{' '}
            {event.expires_at ? `ističe ${formatDate(event.expires_at)}` : 'bez isteka'}
          </p>
        </div>
        <span className={`status status-${status.tone}`}>{status.label}</span>
      </button>

      {open && main && (
        <div className="event-details">
          <div className="event-qr">
            <QrCode value={roomUrl(main.code)} size={160} />
            <div className="event-links">
              <LinkRow label="Link za goste (QR na stolovima)" url={roomUrl(main.code)} />
              <LinkRow label="Link za domaćina (može brisati fotografije)" url={roomAdminUrl(main.code, event.host_key)} />
              <button type="button" className="button" onClick={() => onPrint(event.name, roomUrl(main.code))}>
                Štampaj kartice za stolove
              </button>
            </div>
          </div>

          <div className="event-switches">
            <label className="checkbox">
              <input
                type="checkbox"
                checked={event.uploads_open}
                disabled={busy}
                onChange={(input) => change({ uploads_open: input.target.checked })}
              />
              <span>Slanje fotografija otvoreno</span>
            </label>
            <label className="checkbox">
              <input
                type="checkbox"
                checked={event.guest_rooms_enabled}
                disabled={busy}
                onChange={(input) => change({ guest_rooms_enabled: input.target.checked })}
              />
              <span>Gosti mogu praviti sobe</span>
            </label>
          </div>

          <form
            className="inline-form"
            onSubmit={(submitted) => {
              submitted.preventDefault()
              change({ days: Number(days) }).then(() => setDays(''))
            }}
          >
            <label className="field">
              <span>Novi istek: za koliko dana od danas (0 = bez isteka)</span>
              <input type="number" min={0} max={365} value={days} onChange={(input) => setDays(input.target.value)} required />
            </label>
            <button type="submit" className="button button-secondary" disabled={busy || days === ''}>
              Postavi
            </button>
          </form>

          <RoomRow room={main} token={token} onChanged={onReload} onError={onError} />

          {guestRooms.length > 0 && <h3>Sobe gostiju</h3>}
          {guestRooms.map((room) => (
            <RoomRow key={room.code} room={room} token={token} onChanged={onReload} onError={onError} />
          ))}

          <h3>Telefoni</h3>
          {showDevices ? (
            <EventDevices token={token} eventId={event.id} onPhotosDeleted={onReload} onError={onError} />
          ) : (
            <button type="button" className="button button-secondary" onClick={() => setShowDevices(true)}>
              Prikaži ko je šta poslao
            </button>
          )}

          <button type="button" className="button button-danger" onClick={remove}>
            Obriši događaj
          </button>
        </div>
      )}
    </section>
  )
}

function LinkRow({ label, url }: { label: string; url: string }) {
  return (
    <div className="link-row">
      <span>{label}</span>
      <code>{url}</code>
      <CopyButton text={url} />
    </div>
  )
}

interface RoomRowProps {
  room: AdminRoom
  token: string
  onChanged: () => void
  onError: (failure: unknown) => void
}

function RoomRow({ room, token, onChanged, onError }: RoomRowProps) {
  const rotate = async () => {
    if (!window.confirm('Novi link: stari link i odštampani QR kodovi za ovu sobu prestaju da rade. Nastaviti?')) {
      return
    }

    try {
      await rotateRoomCode(token, room.code)
      onChanged()
    } catch (failure) {
      onError(failure)
    }
  }

  const remove = async () => {
    if (!window.confirm(`Obrisati sobu „${room.name}” i ${room.photos_count} fotografija? Ovo se ne može poništiti.`)) {
      return
    }

    try {
      await deleteRoom(token, room.code)
      onChanged()
    } catch (failure) {
      onError(failure)
    }
  }

  return (
    <div className="room-row">
      <div>
        <strong>{room.is_main ? 'Glavna galerija' : room.name}</strong>
        <span className="admin-muted"> · {room.photos_count} fotografija</span>
      </div>
      <div className="room-row-actions">
        <a className="button button-secondary" href={roomUrl(room.code)} target="_blank" rel="noreferrer">
          Otvori
        </a>
        {room.admin_key && <CopyButton text={roomAdminUrl(room.code, room.admin_key)} label="Admin link sobe" />}
        <button type="button" className="button button-secondary" onClick={rotate}>
          Novi link
        </button>
        {!room.is_main && (
          <button type="button" className="button button-danger" onClick={remove}>
            Obriši
          </button>
        )}
      </div>
    </div>
  )
}

/** Only visible when printing: four table cards per A4 page. */
function TableCard({ name, url }: { name: string; url: string }) {
  return (
    <div className="print-area" aria-hidden="true">
      {[0, 1, 2, 3].map((index) => (
        <div className="table-card" key={index}>
          <p className="table-card-title">{name}</p>
          <QrCode value={url} size={260} />
          <p className="table-card-text">Skeniraj i slikaj</p>
          <p className="table-card-hint">Tvoje fotografije odmah vide svi gosti.</p>
        </div>
      ))}
    </div>
  )
}
