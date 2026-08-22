/**
 * 消息提示音
 * - 私聊：私聊提醒.mp3；群聊：群聊提醒.mp3
 * - H5：mp3，失败走 Web Audio
 * - App：InnerAudio 多路径重试（本地 mp3/wav → 线上 mp3），勿叠加系统 beep
 * 尊重设置页「静音」开关
 */
import { isMsgMuted } from './app-prefs.js'
import { getStaticBase, packagedStaticUrl } from './config.js'

let audioCtx = null
let lastBeepAt = 0
let unlockBound = false
/** @type {Record<string, UniApp.InnerAudioContext|null>} */
const appPlayers = {}
/** @type {Record<string, HTMLAudioElement>} */
const h5Players = {}

function isGroupScope(scope) {
  return scope === 'group'
}

function soundBasename(scope) {
  return isGroupScope(scope) ? 'notify-group' : 'notify'
}

function soundFile(scope, ext) {
  return 'sound/' + soundBasename(scope) + '.' + (ext || 'mp3')
}

function pushUnique(list, item) {
  const s = String(item || '').trim()
  if (s && list.indexOf(s) < 0) list.push(s)
}

/** App 播放候选：本地打包 mp3/wav → _www 绝对路径 → 线上 /999/static（防旧包未带上新音频） */
function appSoundSrcList(scope) {
  const out = []
  const base = soundBasename(scope)
  const rels = [soundFile(scope, 'mp3'), soundFile(scope, 'wav')]
  rels.forEach((rel) => {
    pushUnique(out, packagedStaticUrl(rel))
    // #ifdef APP-PLUS
    try {
      if (typeof plus !== 'undefined' && plus.io && plus.io.convertLocalFileSystemURL) {
        pushUnique(out, plus.io.convertLocalFileSystemURL('_www/static/' + rel))
      }
    } catch (e) {}
    // #endif
  })
  // #ifdef APP-PLUS
  try {
    const remote = getStaticBase() + 'static/sound/' + base + '.mp3'
    if (/^https?:\/\//i.test(remote)) pushUnique(out, remote)
  } catch (e2) {}
  // #endif
  return out
}

function destroyAppPlayer(key) {
  const p = appPlayers[key]
  if (!p) return
  try {
    p.stop()
  } catch (e) {}
  try {
    p.destroy()
  } catch (e2) {}
  appPlayers[key] = null
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
  const key = isGroupScope(scope) ? 'group' : 'private'
  const sources = appSoundSrcList(scope)
  if (!sources.length) return false

  let idx = 0
  let settled = false

  const finish = (ok) => {
    if (settled) return
    settled = true
    if (!ok) playWebTone(kind, scope)
  }

  const attempt = () => {
    if (idx >= sources.length) {
      finish(false)
      return
    }
    const src = sources[idx++]
    destroyAppPlayer(key)
    try {
      const a = uni.createInnerAudioContext()
      a.autoplay = false
      a.obeyMuteSwitch = false
      a.volume = 1
      a.src = src
      appPlayers[key] = a
      a.onError(() => {
        destroyAppPlayer(key)
        attempt()
      })
      a.onPlay(() => {
        finish(true)
      })
      try {
        a.stop()
      } catch (e1) {}
      try {
        a.seek(0)
      } catch (e1b) {}
      a.play()
    } catch (e) {
      attempt()
    }
  }

  attempt()
  return true
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
      const a = new Audio(packagedStaticUrl(soundFile(scope, 'mp3')))
      a.preload = 'auto'
      try {
        a.setAttribute('playsinline', 'true')
      } catch (e0) {}
      h5Players[key] = a
    }
    const a = h5Players[key]
    a.volume = 1
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
