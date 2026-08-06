/**
 * H5 消息提示音（Web Audio，无资源文件）
 * - 普通消息：双 ding，音量偏大
 * - 红包消息：更高音程，更醒目
 * - 开红包：上升短旋律
 */
let audioCtx = null
let lastBeepAt = 0
let unlockBound = false

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

/** 普通消息提示（更大音量） */
export function playNormalMsgSound() {
  bindUnlock()
  try {
    const ctx = ensureCtx()
    if (!ctx || !throttleOk(400)) return
    tone(ctx, 880, 0, 0.13, 0.22)
    tone(ctx, 1175, 0.15, 0.17, 0.2)
  } catch (e) {}
}

/** 红包消息提示（与普通区分：更亮、三连音） */
export function playRedPacketMsgSound() {
  bindUnlock()
  try {
    const ctx = ensureCtx()
    if (!ctx || !throttleOk(350)) return
    tone(ctx, 988, 0, 0.1, 0.2)
    tone(ctx, 1319, 0.12, 0.12, 0.22)
    tone(ctx, 1568, 0.26, 0.16, 0.18)
  } catch (e) {}
}

/** 开红包成功 */
export function playOpenRedPacketSound() {
  bindUnlock()
  try {
    const ctx = ensureCtx()
    if (!ctx) return
    // 开包不与收消息共用节流，但短防抖
    const now = Date.now()
    if (lastBeepAt && now - lastBeepAt < 180) return
    lastBeepAt = now
    tone(ctx, 523, 0, 0.08, 0.16)
    tone(ctx, 659, 0.09, 0.09, 0.18)
    tone(ctx, 784, 0.19, 0.1, 0.2)
    tone(ctx, 1047, 0.3, 0.18, 0.22)
  } catch (e) {}
}

/**
 * 根据消息类型播提示音
 * @param {object} msg
 */
export function playIncomingMessageSound(msg) {
  if (!msg) return
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

bindUnlock()
