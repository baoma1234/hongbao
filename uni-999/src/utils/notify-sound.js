/**
 * 消息提示音（私聊 / 群聊 / 红包共用同一套）
 * - 资源：static/sound/notify.mp3 + notify.wav（H5/Safari/APK/IPA 四端）
 * - App：只播打包本地文件；优先 mp3，失败再 wav；plus.audio → InnerAudio → beep
 * - H5/Safari：优先 mp3，失败再 wav，再 WebAudio 兜底
 * 尊重设置页「静音」开关
 */
import { isMsgMuted } from './app-prefs.js'
import { packagedStaticUrl } from './config.js'

let audioCtx = null
let lastBeepAt = 0
let unlockBound = false
/** @type {Record<string, UniApp.InnerAudioContext|null>} */
const appInner = {}
/** @type {Record<string, HTMLAudioElement>} */
const h5Players = {}

const SOUND_BASE = 'notify'

function soundRel(ext) {
  return 'sound/' + SOUND_BASE + '.' + ext
}

function pushUnique(list, item) {
  const s = String(item || '').trim()
  if (s && list.indexOf(s) < 0) list.push(s)
}

/**
 * App 仅本地路径（打进包的 static）：
 * 1) /static/sound/notify.mp3|wav
 * 2) _www 转换后的绝对路径（iOS/部分安卓 InnerAudio 需要）
 * 禁止 https 远程，避免未同步/失败时静音
 */
function appLocalSrcList() {
  const out = []
  ;['mp3', 'wav'].forEach((ext) => {
    const rel = soundRel(ext)
    // #ifdef APP-PLUS
    try {
      // eslint-disable-next-line no-undef
      if (typeof plus !== 'undefined' && plus.io && plus.io.convertLocalFileSystemURL) {
        pushUnique(out, plus.io.convertLocalFileSystemURL('_www/static/' + rel))
        pushUnique(out, plus.io.convertLocalFileSystemURL('/static/' + rel))
      }
    } catch (e) {}
    // #endif
    pushUnique(out, packagedStaticUrl(rel))
    pushUnique(out, '/static/' + rel)
  })
  return out
}

function h5SrcList() {
  const out = []
  ;['mp3', 'wav'].forEach((ext) => {
    pushUnique(out, packagedStaticUrl(soundRel(ext)))
  })
  return out
}

function destroyInner(key) {
  const p = appInner[key]
  if (!p) return
  try {
    p.stop()
  } catch (e) {}
  try {
    p.destroy()
  } catch (e2) {}
  appInner[key] = null
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
    // 预热 mp3/wav 双源
    try {
      h5SrcList().forEach((src) => {
        const a = new Audio(src)
        a.preload = 'auto'
        a.muted = true
        const p = a.play()
        if (p && typeof p.then === 'function') {
          p.then(() => {
            a.pause()
            a.muted = false
          }).catch(() => {})
        }
      })
    } catch (e3) {}
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
  const group = scope === 'group'
  const key = group ? 'group' : 'private'
  const sources = appLocalSrcList()
  if (!sources.length) {
    try {
      // eslint-disable-next-line no-undef
      plus.device.beep(1)
    } catch (e0) {}
    return true
  }

  let idx = 0
  const fallbackBeep = () => {
    try {
      // eslint-disable-next-line no-undef
      plus.device.beep(group ? 1 : kind === 'rp' ? 2 : 1)
    } catch (e) {}
  }

  const tryPlusAudio = (src) => {
    try {
      // eslint-disable-next-line no-undef
      if (typeof plus === 'undefined' || !plus.audio || !plus.audio.createPlayer) return false
      // eslint-disable-next-line no-undef
      const player = plus.audio.createPlayer(src)
      player.setSessionCategory && player.setSessionCategory('ambient')
      player.play(
        () => {
          try {
            player.stop()
          } catch (e1) {}
        },
        () => {
          try {
            player.stop()
          } catch (e2) {}
          attemptInner()
        }
      )
      return true
    } catch (e) {
      return false
    }
  }

  const attemptInner = () => {
    if (idx >= sources.length) {
      fallbackBeep()
      return
    }
    const src = sources[idx++]
    if (tryPlusAudio(src)) return
    destroyInner(key)
    try {
      const a = uni.createInnerAudioContext()
      a.autoplay = false
      a.obeyMuteSwitch = false
      a.volume = 1
      a.src = src
      a.onError(() => {
        destroyInner(key)
        attemptInner()
      })
      appInner[key] = a
      a.play()
    } catch (e) {
      attemptInner()
    }
  }

  attemptInner()
  return true
  // #endif
  // #ifndef APP-PLUS
  return false
  // #endif
}

function playH5File(kind, scope) {
  // #ifdef H5
  try {
    if (typeof Audio === 'undefined') return false
    const sources = h5SrcList()
    if (!sources.length) return false
    const key = scope === 'group' ? 'group' : 'private'

    const tryAt = (i) => {
      if (i >= sources.length) {
        playWebTone(kind, scope)
        return
      }
      const src = sources[i]
      let a = h5Players[key]
      if (!a) {
        a = new Audio()
        a.preload = 'auto'
        try {
          a.setAttribute('playsinline', 'true')
          a.setAttribute('webkit-playsinline', 'true')
        } catch (e0) {}
        h5Players[key] = a
      }
      const onFail = () => {
        try {
          a.removeEventListener('error', onFail)
        } catch (e1) {}
        tryAt(i + 1)
      }
      try {
        a.removeEventListener('error', onFail)
      } catch (e2) {}
      a.addEventListener('error', onFail)
      a.volume = 1
      try {
        if (a.src !== src) a.src = src
        a.currentTime = 0
      } catch (e3) {
        try {
          a.src = src
        } catch (e4) {}
      }
      const p = a.play()
      if (p && typeof p.catch === 'function') {
        p.catch(() => onFail())
      }
    }

    tryAt(0)
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
    const group = scope === 'group'
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
  if (playH5File(kind, scope)) return
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
