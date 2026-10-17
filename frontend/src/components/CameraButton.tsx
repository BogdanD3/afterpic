interface CameraButtonProps {
  onFiles: (files: File[]) => void
}

export function CameraButton({ onFiles }: CameraButtonProps) {
  return (
    <label className="camera-button">
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <path
          d="M4 8a2 2 0 0 1 2-2h2.2l1.4-2h4.8l1.4 2H18a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8Z"
          stroke="currentColor"
          strokeWidth="2"
          strokeLinejoin="round"
        />
        <circle cx="12" cy="12.5" r="3.5" stroke="currentColor" strokeWidth="2" />
      </svg>
      <span>Slikaj</span>
      <input
        className="visually-hidden"
        type="file"
        accept="image/*"
        capture="environment"
        onChange={(event) => {
          const files = Array.from(event.target.files ?? [])

          // Reset so picking the same photo again still fires onChange.
          event.target.value = ''

          if (files.length > 0) {
            onFiles(files)
          }
        }}
      />
    </label>
  )
}
