import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import '@fontsource/ibm-plex-sans-arabic/400.css'
import '@fontsource/ibm-plex-sans-arabic/600.css'
import '@fontsource/ibm-plex-sans-arabic/700.css'
import '@fontsource/amiri/400.css'
import '@fontsource/amiri/700.css'
import '@fontsource/reem-kufi/500.css'
import '@fontsource/reem-kufi/700.css'
import '@fontsource/ibm-plex-mono/400.css'
import '@fontsource/ibm-plex-mono/500.css'
import './styles/theme.css'
import './styles/app.css'
import App from './App'
import { registerServiceWorker } from './lib/push'
import { SessionProvider } from './lib/session'

// The page never scrolls: block touch-drag scrolling/rubber-banding except inside form controls.
document.addEventListener('touchmove', (e) => {
  const el = e.target as HTMLElement
  if (!el.closest('input, textarea, select, .allow-scroll')) e.preventDefault()
}, { passive: false })

registerServiceWorker()

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <SessionProvider>
      <App />
    </SessionProvider>
  </StrictMode>,
)
