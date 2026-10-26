import { useState, type FormEvent } from 'react'
import { Dialog } from './Dialog'

interface ReasonDialogProps {
  title: string
  text: string
  onSend: (reason: string) => Promise<void>
  onClose: () => void
}

/** A request to the organiser with a short reason: remove a photo, delete a room. */
export function ReasonDialog({ title, text, onSend, onClose }: ReasonDialogProps) {
  const [reason, setReason] = useState('')
  const [state, setState] = useState<'idle' | 'busy' | 'sent'>('idle')
  const [error, setError] = useState<string | null>(null)

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setState('busy')
    setError(null)

    try {
      await onSend(reason.trim())
      setState('sent')
    } catch (failure) {
      setError(failure instanceof Error ? failure.message : 'Zahtjev nije poslan.')
      setState('idle')
    }
  }

  if (state === 'sent') {
    return (
      <Dialog title={title} onClose={onClose}>
        <p className="dialog-text">Zahtjev je poslan organizatoru. Hvala!</p>
        <div className="dialog-actions">
          <button type="button" className="button" onClick={onClose}>
            U redu
          </button>
        </div>
      </Dialog>
    )
  }

  return (
    <Dialog title={title} onClose={onClose}>
      <form className="form" onSubmit={submit}>
        <p className="dialog-text">{text}</p>
        <label className="field">
          <span>Razlog</span>
          <textarea value={reason} onChange={(event) => setReason(event.target.value)} maxLength={500} rows={3} required autoFocus />
        </label>
        {error && <p className="form-error">{error}</p>}
        <div className="dialog-actions">
          <button type="submit" className="button" disabled={state === 'busy' || reason.trim() === ''}>
            {state === 'busy' ? 'Šaljem…' : 'Pošalji zahtjev'}
          </button>
        </div>
      </form>
    </Dialog>
  )
}
