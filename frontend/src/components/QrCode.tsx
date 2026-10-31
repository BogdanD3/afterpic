import qrcode from 'qrcode-generator'
import { useMemo } from 'react'

interface QrCodeProps {
  value: string
  size?: number
  className?: string
}

/**
 * A QR code drawn as SVG in the browser, so it stays sharp when printed and
 * costs the server nothing. Error correction "M" survives a scratched or
 * slightly wet table card.
 */
export function QrCode({ value, size = 220, className }: QrCodeProps) {
  const { path, cells } = useMemo(() => {
    const qr = qrcode(0, 'M')
    qr.addData(value)
    qr.make()

    const count = qr.getModuleCount()
    let d = ''

    for (let row = 0; row < count; row++) {
      for (let col = 0; col < count; col++) {
        if (qr.isDark(row, col)) {
          d += `M${col + 4},${row + 4}h1v1h-1z`
        }
      }
    }

    return { path: d, cells: count + 8 }
  }, [value])

  return (
    <svg
      className={className}
      width={size}
      height={size}
      viewBox={`0 0 ${cells} ${cells}`}
      shapeRendering="crispEdges"
      role="img"
      aria-label={`QR kod: ${value}`}
    >
      <rect width={cells} height={cells} fill="#fff" />
      <path d={path} fill="#000" />
    </svg>
  )
}
