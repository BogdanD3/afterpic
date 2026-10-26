import { useState } from 'react'

export function CopyButton({ text, label = 'Kopiraj' }: { text: string; label?: string }) {
  const [copied, setCopied] = useState(false)

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(text)
      setCopied(true)
      window.setTimeout(() => setCopied(false), 1500)
    } catch {
      window.prompt('Kopirajte link:', text)
    }
  }

  return (
    <button type="button" className="button button-secondary" onClick={copy}>
      {copied ? 'Kopirano ✓' : label}
    </button>
  )
}
