import { AdminPage } from './pages/AdminPage'
import { Landing } from './pages/Landing'
import { RoomPage } from './pages/RoomPage'

/**
 * Three pages, picked by the path (Nginx serves index.html for all of them):
 * `/` the landing page, `/r/{code}` a room, `/admin` the super admin page.
 */
export default function App() {
  const path = window.location.pathname.replace(/\/+$/, '') || '/'
  const room = /^\/r\/([a-z0-9-]+)$/.exec(path)

  if (room) {
    return <RoomPage code={room[1]} />
  }

  if (path === '/admin') {
    return <AdminPage />
  }

  if (path === '/') {
    return <Landing />
  }

  return (
    <main className="message-page">
      <h1>Stranica ne postoji</h1>
      <p>Otvorite link ili skenirajte QR kod koji ste dobili za svoj događaj.</p>
    </main>
  )
}
