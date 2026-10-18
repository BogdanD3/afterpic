/**
 * Longest side, in pixels, of a photo sent to the server. The server never
 * stores more than PHOTOS_DOWNLOAD_MAX_EDGE (3000 by default, 4000 at most is
 * useful), so sending more only costs upload time. A 200 MP photo becomes
 * about 12 MP and a few MB instead of 30-60 MB.
 */
const MAX_EDGE = 4000

/** JPEG quality for photos made on the phone. 0.92 is visually lossless. */
const JPEG_QUALITY = 0.92

/** Photos that need no resizing are sent untouched up to this size. */
const MAX_UNTOUCHED_BYTES = 10 * 1024 * 1024

const UPLOADABLE_TYPES = ['image/jpeg', 'image/png', 'image/webp']

/**
 * Decodes the photo. Browsers apply the EXIF orientation, so the size and
 * the pixels are upright. Rejects for formats the browser cannot read.
 */
async function loadImage(file: File): Promise<HTMLImageElement> {
  const url = URL.createObjectURL(file)
  const image = new Image()

  try {
    image.src = url
    await image.decode()

    return image
  } finally {
    URL.revokeObjectURL(url)
  }
}

async function drawAsJpeg(image: HTMLImageElement, width: number, height: number): Promise<Blob> {
  const canvas = document.createElement('canvas')
  canvas.width = width
  canvas.height = height

  try {
    const context = canvas.getContext('2d')

    if (!context) {
      throw new Error('Canvas is not available.')
    }

    // JPEG has no transparency; without this, transparent pixels turn black.
    context.fillStyle = '#fff'
    context.fillRect(0, 0, width, height)
    context.imageSmoothingEnabled = true
    context.imageSmoothingQuality = 'high'
    context.drawImage(image, 0, 0, width, height)

    const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', JPEG_QUALITY))

    if (!blob) {
      throw new Error('The photo could not be encoded.')
    }

    return blob
  } finally {
    // Safari keeps canvas memory until the size is set to zero.
    canvas.width = 0
    canvas.height = 0
  }
}

function jpegName(name: string): string {
  const base = name.replace(/\.[^.]*$/, '')

  return `${base || 'photo'}.jpg`
}

/**
 * Makes the photo ready to send: very large photos are scaled down on the
 * phone to at most MAX_EDGE pixels, and formats the server cannot read
 * (HEIC on iPhone, when the browser can decode it) are turned into JPEG.
 * Normal photos are sent untouched. The new JPEG has no EXIF, so GPS never
 * leaves the phone.
 *
 * Never rejects: when anything fails, the original file is returned and the
 * usual checks decide whether it can be sent.
 */
export async function preparePhoto(file: File): Promise<File> {
  let image: HTMLImageElement

  try {
    image = await loadImage(file)
  } catch {
    return file
  }

  const { naturalWidth: width, naturalHeight: height } = image
  const scale = Math.min(1, MAX_EDGE / Math.max(width, height))
  const isUploadable = UPLOADABLE_TYPES.includes(file.type)

  if (scale === 1 && isUploadable && file.size <= MAX_UNTOUCHED_BYTES) {
    return file
  }

  try {
    const blob = await drawAsJpeg(image, Math.round(width * scale), Math.round(height * scale))

    // A file that only needed re-encoding is kept when the JPEG is no smaller.
    if (scale === 1 && isUploadable && blob.size >= file.size) {
      return file
    }

    return new File([blob], jpegName(file.name), { type: 'image/jpeg', lastModified: file.lastModified })
  } catch {
    return file
  }
}
