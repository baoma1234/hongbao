/**
 * 消息提示音
 * - H5：Web Audio
 * - App：优先 InnerAudio 本地 wav，失败则 plus.device.beep
 * 尊重设置页「静音」开关
 */
import { isMsgMuted } from './app-prefs.js'
import { packagedStaticUrl } from './config.js'

let audioCtx = null
let lastBeepAt = 0
let unlockBound = false
let appAudio = null

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

function playAppBeep(kind) {
  // #ifdef APP-PLUS
  try {
    if (!appAudio) {
      appAudio = uni.createInnerAudioContext()
      appAudio.autoplay = false
      appAudio.obeyMuteSwitch = false
      appAudio.volume = 1
      // 打包进 static 的短提示音；无文件时走 device.beep
      try {
        appAudio.src = packagedStaticUrl('sound/notify.wav')
      } catch (e0) {
        appAudio.src = '/static/sound/notify.wav'
      }
    }
    try {
      appAudio.stop()
    } catch (e1) {}
    appAudio.seek(0)
    appAudio.play()
    // 红包再补一次系统 beep 更醒目
    if (kind === 'rp') {
      try {
        plus.device.beep(1)
      } catch (e2) {}
    }
    return true
  } catch (e) {
    try {
      plus.device.beep(kind === 'rp' ? 2 : 1)
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

/** 普通消息提示 */
export function playNormalMsgSound() {
  if (isMsgMuted()) return
  if (!throttleOk(400)) return
  if (playAppBeep('msg')) return
  bindUnlock()
  try {
    const ctx = ensureCtx()
    if (!ctx) return
    tone(ctx, 880, 0, 0.13, 0.22)
    tone(ctx, 1175, 0.15, 0.17, 0.2)
  } catch (e) {}
}

/** 红包消息提示 */
export function playRedPacketMsgSound() {
  if (isMsgMuted()) return
  if (!throttleOk(350)) return
  if (playAppBeep('rp')) return
  bindUnlock()
  try {
    const ctx = ensureCtx()
    if (!ctx) return
    tone(ctx, 988, 0, 0.1, 0.2)
    tone(ctx, 1319, 0.12, 0.12, 0.22)
    tone(ctx, 1568, 0.26, 0.16, 0.18)
  } catch (e) {}
}

/** 开红包成功 */
export function playOpenRedPacketSound() {
  if (isMsgMuted()) return
  const now = Date.now()
  if (lastBeepAt && now - lastBeepAt < 180) return
  lastBeepAt = now
  if (playAppBeep('rp')) return
  bindUnlock()
  try {
    const ctx = ensureCtx()
    if (!ctx) return
    tone(ctx, 523, 0, 0.08, 0.16)
    tone(ctx, 659, 0.09, 0.09, 0.18)
    tone(ctx, 784, 0.19, 0.1, 0.2)
    tone(ctx, 1047, 0.3, 0.18, 0.22)
  } catch (e) {}
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
  if (mtype === 2 || relayAuto) playRedPacketMsgSound()
  else playNormalMsgSound()
}

// #ifdef H5
bindUnlock()
// #endif
