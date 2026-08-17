/**
 * 消息提示音
 * - 私聊：更响、更高；群聊：更轻、更低
 * - H5：优先 wav，失败走 Web Audio
 * - App：InnerAudio 本地 wav，失败则 plus.device.beep
 * 尊重设置页「静音」开关
 */
import { isMsgMuted } from './app-prefs.js'
import { packagedStaticUrl } from './config.js'

let audioCtx = null
let lastBeepAt = 0
let unlockBound = false
/** @type {Record<string, UniApp.InnerAudioContext>} */
const appPlayers = {}
/** @type {Record<string, HTMLAudioElement>} */
const h5Players = {}

function isGroupScope(scope) {
  return scope === 'group'
}

function soundFile(scope) {
  return isGroupScope(scope) ? 'sound/notify-group.wav' : 'sound/notify.wav'
}

function ensureCtx() {
  try {
    const AC = typeof window !== 'undefined' && (window.AudioContext || window.webkitAudioContext)
    if (!AC) return null
    if (!audioCtx) audioCtx = new AC()
    if (audioCtx.state === 'suspended') audioCtx.resume().catch(() => {})
    return audioCtx
  } catch (e) {
    return null
  }
}

function bindUnlock() {
  if (unlockBound || typeof document === 'undefined') return
  unlockBound = true
  const unlock = () => {
    try {
      const ctx = ensureCtx()
      if (!ctx) return
      const buf = ctx.createBuffer(1, 1, 22050)
      const src = ctx.createBufferSource()
      src.buffer = buf
      src.connect(ctx.destination)
      src.start(0)
    } catch (e) {}
    try {
      Object.keys(h5Players).forEach((k) => {
        const a = h5Players[k]
        if (!a) return
        a.muted = true
        const p = a.play()
        if (p && typeof p.then === 'function') {
          p.then(() => {
            a.pause()
            a.currentTime = 0
            a.muted = false
          }).catch(() => {
            a.muted = false
          })
        }
      })
    } catch (e2) {}
    document.removeEventListener('pointerdown', unlock, true)
    document.removeEventListener('touchstart', unlock, true)
    document.removeEventListener('keydown', unlock, true)
  }
  document.addEventListener('pointerdown', unlock, true)
  document.addEventListener('touchstart', unlock, true)
  document.addEventListener('keydown', unlock, true)
}

function tone(ctx, freq, start, dur, vol) {
  const o = ctx.createOscillator()
  const g = ctx.createGain()
  o.type = 'sine'
  o.frequency.value = freq
  g.gain.value = vol
  o.connect(g)
  g.connect(ctx.destination)
  const t0 = ctx.currentTime
  o.start(t0 + start)
  g.gain.exponentialRampToValueAtTime(0.001, t0 + start + dur)
  o.stop(t0 + start + dur + 0.02)
}

function throttleOk(ms) {
  const now = Date.now()
  if (lastBeepAt && now - lastBeepAt < ms) return false
  lastBeepAt = now
  return true
}

function playAppBeep(kind, scope) {
  // #ifdef APP-PLUS
  const group = isGroupScope(scope)
  const key = group ? 'group' : 'private'
  try {
    if (!appPlayers[key]) {
      const a = uni.createInnerAudioContext()
      a.autoplay = false
      a.obeyMuteSwitch = false
      a.volume = group ? 0.42 : 1
      try {
        a.src = packagedStaticUrl(soundFile(scope))
      } catch (e0) {
        a.src = '/static/' + soundFile(scope)
      }
      appPlayers[key] = a
    }
    const player = appPlayers[key]
    player.volume = group ? 0.42 : 1
    try {
      player.stop()
    } catch (e1) {}
    try {
      player.seek(0)
    } catch (e1b) {}
    player.play()
    if (kind === 'rp' && !group) {
      try {
        plus.device.beep(1)
      } catch (e2) {}
    }
    return true
  } catch (e) {
    try {
      plus.device.beep(group ? 1 : kind === 'rp' ? 2 : 1)
      return true
    } catch (e3) {
      return false
    }
  }
  // #endif
  // #ifndef APP-PLUS
  return false
  // #endif
}

function playH5Wav(kind, scope) {
  // #ifdef H5
  try {
    if (typeof Audio === 'undefined') return false
    const group = isGroupScope(scope)
    const key = group ? 'group' : 'private'
    if (!h5Players[key]) {
      const a = new Audio(packagedStaticUrl(soundFile(scope)))
      a.preload = 'auto'
      try {
        a.setAttribute('playsinline', 'true')
      } catch (e0) {}
      h5Players[key] = a
    }
    const a = h5Players[key]
    a.volume = group ? 0.4 : 1
    try {
      a.currentTime = 0
    } catch (e1) {}
    const p = a.play()
    if (p && typeof p.catch === 'function') {
      p.catch(() => playWebTone(kind, scope))
    }
    return true
  } catch (e) {
    return false
  }
  // #endif
  // #ifndef H5
  return false
  // #endif
}

function playWebTone(kind, scope) {
  bindUnlock()
  try {
    const ctx = ensureCtx()
    if (!ctx) return
    const group = isGroupScope(scope)
    if (kind === 'open') {
      tone(ctx, 523, 0, 0.08, 0.16)
      tone(ctx, 659, 0.09, 0.09, 0.18)
      tone(ctx, 784, 0.19, 0.1, 0.2)
      tone(ctx, 1047, 0.3, 0.18, 0.22)
      return
    }
    if (kind === 'rp') {
      if (group) {
        tone(ctx, 698, 0, 0.1, 0.1)
        tone(ctx, 880, 0.12, 0.12, 0.11)
        tone(ctx, 1046, 0.26, 0.14, 0.1)
      } else {
        tone(ctx, 988, 0, 0.11, 0.38)
        tone(ctx, 1319, 0.13, 0.13, 0.4)
        tone(ctx, 1568, 0.28, 0.18, 0.34)
      }
      return
    }
    if (group) {
      tone(ctx, 523, 0, 0.12, 0.1)
      tone(ctx, 659, 0.14, 0.14, 0.09)
    } else {
      tone(ctx, 988, 0, 0.14, 0.4)
      tone(ctx, 1319, 0.16, 0.18, 0.38)
    }
  } catch (e) {}
}

function playNotify(kind, scope) {
  if (playAppBeep(kind, scope)) return
  if (playH5Wav(kind, scope)) return
  playWebTone(kind, scope)
}

/** 普通消息提示 @param {'private'|'group'} [scope] */
export function playNormalMsgSound(scope) {
  if (isMsgMuted()) return
  if (!throttleOk(400)) return
  playNotify('msg', scope === 'group' ? 'group' : 'private')
}

/** 红包消息提示 @param {'private'|'group'} [scope] */
export function playRedPacketMsgSound(scope) {
  if (isMsgMuted()) return
  if (!throttleOk(350)) return
  playNotify('rp', scope === 'group' ? 'group' : 'private')
}

/** 开红包成功 */
export function playOpenRedPacketSound() {
  if (isMsgMuted()) return
  const now = Date.now()
  if (lastBeepAt && now - lastBeepAt < 180) return
  lastBeepAt = now
  playNotify('open', 'private')
}

function msgSoundScope(msg) {
  const type = (msg && msg.conversation_type) | 0
  if (type === 2) return 'group'
  if (type === 1) return 'private'
  if (((msg && msg.group_id) | 0) > 0) return 'group'
  return 'private'
}

/**
 * @param {object} msg
 */
export function playIncomingMessageSound(msg) {
  if (!msg || isMsgMuted()) return
  let ex = msg.extra || {}
  if (typeof ex === 'string') {
    try {
      ex = JSON.parse(ex) || {}
    } catch (e) {
      ex = {}
    }
  }
  const relayAuto = !!(ex.relay_auto | 0)
  const mtype = (msg.msg_type | 0) || 0
  const scope = msgSoundScope(msg)
  if (mtype === 2 || relayAuto) playRedPacketMsgSound(scope)
  else playNormalMsgSound(scope)
}

// #ifdef H5
bindUnlock()
// #endif
