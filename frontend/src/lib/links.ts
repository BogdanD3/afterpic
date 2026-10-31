/** Full link to a room, as it goes into a QR code. */
export function roomUrl(code: string): string {
  return `${window.location.origin}/r/${code}`
}

/** A room link with an admin key: the host's link, or a guest room creator's. */
export function roomAdminUrl(code: string, key: string): string {
  return `${roomUrl(code)}?key=${encodeURIComponent(key)}`
}

export function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString('bs-BA', { day: 'numeric', month: 'numeric', year: 'numeric' })
}
