/** 与 /888 共用 localStorage key：fans_hub_skin */
export const SKIN_STORAGE_KEY = 'fans_hub_skin'

export const SKIN_OPTIONS = [
  { id: 'default', labelKey: 'skin_option_default' },
  { id: 'a', labelKey: 'skin_option_a' },
  { id: 'b', labelKey: 'skin_option_b' },
  { id: 'd', labelKey: 'skin_option_d' },
]

export const SKINS = {
  default: {
    '--bg-main': '#F4F6F9',
    '--bg-card': '#FFFFFF',
    '--primary': '#00C853',
    '--secondary': '#FF9100',
    '--accent': '#0071FF',
    '--text-main': '#1A212D',
    '--text-muted': '#657786',
    '--danger': '#FF3B30',
  },
  a: {
    '--bg-main': '#FAFAFA',
    '--bg-card': '#FFFFFF',
    '--primary': '#E53935',
    '--secondary': '#FFB300',
    '--accent': '#D32F2F',
    '--text-main': '#212121',
    '--text-muted': '#757575',
    '--danger': '#FF3B30',
  },
  b: {
    '--bg-main': '#EDF2F7',
    '--bg-card': '#FFFFFF',
    '--primary': '#00C853',
    '--secondary': '#FF9100',
    '--accent': '#1A365D',
    '--text-main': '#2D3748',
    '--text-muted': '#718096',
    '--danger': '#FF3B30',
  },
  d: {
    '--bg-main': '#F6F7FB',
    '--bg-card': '#FFFFFF',
    '--primary': '#3F51B5',
    '--secondary': '#00BCD4',
    '--accent': '#1E3A8A',
    '--text-main': '#111827',
    '--text-muted': '#6B7280',
    '--danger': '#EF4444',
  },
}

const listeners = new Set()

export function onSkinChange(fn) {
  listeners.add(fn)
  return () => listeners.delete(fn)
}

export function getSkinId() {
  try {
    const id = uni.getStorageSync(SKIN_STORAGE_KEY) || 'default'
    return SKINS[id] ? id : 'default'
  } catch (e) {
    return 'default'
  }
}

function applyCssVars(skin) {
  // #ifdef H5
  if (typeof document !== 'undefined' && document.documentElement) {
    Object.keys(skin).forEach((key) => {
      document.documentElement.style.setProperty(key, skin[key])
    })
  }
  // #endif
}

export function applySkin(skinId, { flash = false } = {}) {
  const id = SKINS[skinId] ? skinId : 'default'
  const skin = SKINS[id]
  applyCssVars(skin)
  try {
    uni.setStorageSync(SKIN_STORAGE_KEY, id)
  } catch (e) {}
  listeners.forEach((fn) => {
    try {
      fn(id, !!flash)
    } catch (e) {}
  })
  return id
}

export function initSkin() {
  return applySkin(getSkinId(), { flash: false })
}
