import { useEffect, useState, type FormEvent } from 'react'
import { fetchMyName, markAskedForName, saveMyName } from '../api/me'
import { Dialog } from './Dialog'

/**
 * The optional name under the guest's photos. Asked once after the first
 * photo; the upload does not wait for it. Can be changed from "Sobe".
 */
export function NameDialog({ onClose }: { onClose: () => void }) {
  const [name, setName] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    markAskedForName()
    fetchMyName()
      .then((current) => current && setName(current))
      .catch(() => undefined)
  }, [])

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setBusy(true)
    setError(null)

    try {
      await saveMyName(name.trim())
      onClose()
    } catch (failure) {
      setError(failure instanceof Error ? failure.message : 'Ime nije sačuvano.')
      setBusy(false)
    }
  }

  return (
    <Dialog title="Kako se zovete?" onClose={onClose}>
      <form className="form" onSubmit={submit}>
        <p className="dialog-text">Nije obavezno. Ime se prikazuje uz vaše fotografije, i ranije i buduće.</p>
        <label className="field">
          <span>Ime</span>
          <input value={name} onChange={(event) => setName(event.target.value)} maxLength={40} placeholder="npr. Ana" autoFocus />
        </label>
        {error && <p className="form-error">{error}</p>}
        <div className="dialog-actions">
          <button type="button" className="button button-secondary" onClick={onClose}>
            Preskoči
          </button>
          <button type="submit" className="button" disabled={busy}>
            {busy ? 'Čuvam…' : 'Sačuvaj'}
          </button>
        </div>
      </form>
    </Dialog>
  )
}
