/**
 * WS 重连退避（纯函数，便于自动化单测）
 *
 * 指数：2s → 4s → 8s → 16s → 32s→cap 30s
 * 抖动：在 [0.5, 1.0] × base 内随机，避免万人同时掉线齐刷（惊群）
 */

/**
 * @param {number} attempt 从 1 开始的重连次数
 * @param {{ jitter?: boolean, random?: () => number, capMs?: number, baseMs?: number }} [opts]
 * @returns {number} 延迟毫秒
 */
export function calcReconnectDelayMs(attempt, opts = {}) {
  const baseMs = opts.baseMs != null ? opts.baseMs : 2000
  const capMs = opts.capMs != null ? opts.capMs : 30000
  const n = Math.min(Math.max(1, attempt | 0), 6)
  const base = Math.min(capMs, baseMs * Math.pow(2, Math.max(0, n - 1)))
  if (opts.jitter === false) {
    return Math.floor(base)
  }
  const rnd = typeof opts.random === 'function' ? opts.random() : Math.random()
  const factor = 0.5 + Math.max(0, Math.min(1, rnd)) * 0.5
  return Math.floor(base * factor)
}

/**
 * 无抖动时的期望序列（断言用）
 * @returns {number[]}
 */
export function expectedBackoffSeriesMs() {
  return [2000, 4000, 8000, 16000, 30000, 30000]
}
