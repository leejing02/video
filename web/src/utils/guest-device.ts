// One random ID per browser/phone. The API derives this device's guest account
// from it, so the same device keeps the same temporary identity. A new ID is
// made after GUEST_DAYS, matching the server-side guest lifetime.
export const GUEST_DEVICE_KEY = 'guest_device'
export const GUEST_DAYS = 30
const DAY = 24 * 60 * 60 * 1000

function randomId() {
  if (typeof crypto.randomUUID === 'function') return crypto.randomUUID()
  const bytes = crypto.getRandomValues(new Uint8Array(16))
  return Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('')
}

export function guestDeviceId(storage: Storage = localStorage, now = Date.now()): string {
  try {
    const saved = JSON.parse(storage.getItem(GUEST_DEVICE_KEY) || 'null')
    if (typeof saved?.id === 'string' && /^[A-Za-z0-9-]{32,64}$/.test(saved.id) &&
        typeof saved.created === 'number' && saved.created <= now && now - saved.created < GUEST_DAYS * DAY) {
      return saved.id
    }
  } catch { /* Replace an unreadable value below. */ }
  const id = randomId()
  try { storage.setItem(GUEST_DEVICE_KEY, JSON.stringify({ id, created: now })) } catch { /* Private mode: this visit only. */ }
  return id
}

export function isGuestEmail(email: string | null | undefined) {
  return !!email && email.toLowerCase().endsWith('@guest.invalid')
}
