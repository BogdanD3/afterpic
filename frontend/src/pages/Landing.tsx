import '../landing.css'

/**
 * Where to reach you, shown as the button at the bottom. Leave `href` empty
 * to hide the button. Examples: 'https://instagram.com/yourname',
 * 'mailto:you@example.com', 'tel:+38761000000'.
 */
const CONTACT = { label: 'Pišite nam', href: '' }

const STEPS = [
  { title: 'Skeniraju QR kod', text: 'Gosti skeniraju kod sa stola. Bez aplikacije i bez registracije.' },
  { title: 'Slikaju', text: 'Kamera se otvara odmah, a fotografija se sama šalje u galeriju.' },
  { title: 'Svi gledaju uživo', text: 'Nove fotografije se pojavljuju svima za par sekundi, i svako ih može preuzeti.' },
]

const POINTS = [
  'Privatno: galeriju vidi samo ko ima link ili QR kod vašeg događaja.',
  'Sobe za manje ekipe, kad ih želite.',
  'Pune fotografije u dobrom kvalitetu, i na sporom internetu.',
  'Galerija ostaje dostupna i nakon proslave, pa se sama briše.',
]

/** The bare domain: explains the product, loads nothing from the server. */
export function Landing() {
  return (
    <main className="landing">
      <section className="landing-hero">
        <p className="landing-kicker">Afterpic</p>
        <h1>Sve fotografije sa vaše proslave, na jednom mjestu.</h1>
        <p>Gosti slikaju svojim telefonima, a vi dobijete zajedničku galeriju uživo.</p>
      </section>

      <ol className="landing-steps">
        {STEPS.map((step, index) => (
          <li key={step.title}>
            <span className="landing-step-number">{index + 1}</span>
            <div>
              <h2>{step.title}</h2>
              <p>{step.text}</p>
            </div>
          </li>
        ))}
      </ol>

      <ul className="landing-points">
        {POINTS.map((point) => (
          <li key={point}>{point}</li>
        ))}
      </ul>

      {CONTACT.href && (
        <a className="button landing-contact" href={CONTACT.href}>
          {CONTACT.label}
        </a>
      )}
    </main>
  )
}
