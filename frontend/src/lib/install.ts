import { useEffect, useState } from 'react'

/** Chrome/Edge/Android's deferred "Add to Home screen" prompt. */
interface InstallPromptEvent extends Event {
  prompt(): Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>
}

let deferred: InstallPromptEvent | null = null
const listeners = new Set<() => void>()
const notify = () => listeners.forEach((fn) => fn())

/** Call once at startup so the browser's prompt event isn't missed before React mounts. */
export function listenForInstall() {
  window.addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); deferred = e as InstallPromptEvent; notify() })
  window.addEventListener('appinstalled', () => { deferred = null; notify() })
}

const standalone = () => window.matchMedia?.('(display-mode: standalone)').matches || (navigator as Navigator & { standalone?: boolean }).standalone === true
const isIos = () => /iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)

/** `canInstall`: the browser offers an install prompt; `iosHint`: Safari needs Share → Add to Home Screen. */
export function useInstall() {
  const [, setTick] = useState(0)
  useEffect(() => {
    const fn = () => setTick((n) => n + 1)
    listeners.add(fn)
    return () => { listeners.delete(fn) }
  }, [])
  const install = async () => {
    if (!deferred) return
    await deferred.prompt()
    await deferred.userChoice.catch(() => undefined)
    deferred = null
    notify()
  }
  return { canInstall: !!deferred && !standalone(), iosHint: isIos() && !standalone(), install }
}
