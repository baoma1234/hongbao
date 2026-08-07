#!/usr/bin/env node
/**
 * P2 自动化：重连指数退避 + 抖动（防惊群）
 *
 * 用法（在仓库根目录）:
 *   node im-server/scripts/test_p2_reconnect_backoff.mjs
 *
 * 可选模拟「万人齐掉线」分散度:
 *   node im-server/scripts/test_p2_reconnect_backoff.mjs --storm=2000
 *
 * 退出码 0=通过，1=失败
 */

import { pathToFileURL } from 'url'
import path from 'path'
import { fileURLToPath } from 'url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const backoffPath = path.resolve(__dirname, '../../uni-999/src/utils/reconnect-backoff.js')

const { calcReconnectDelayMs, expectedBackoffSeriesMs } = await import(
  pathToFileURL(backoffPath).href
)

let fail = 0

function assert(cond, msg) {
  if (!cond) {
    console.log('[FAIL]', msg)
    fail++
  } else {
    console.log('[OK]', msg)
  }
}

// 1) 无抖动序列必须严格匹配
const series = expectedBackoffSeriesMs()
for (let i = 0; i < series.length; i++) {
  const got = calcReconnectDelayMs(i + 1, { jitter: false })
  assert(got === series[i], `no-jitter attempt=${i + 1} expect=${series[i]} got=${got}`)
}

// 2) attempt 封顶
assert(
  calcReconnectDelayMs(99, { jitter: false }) === 30000,
  'attempt>=6 caps at 30000'
)

// 3) 抖动落在 [0.5*base, base]
for (let attempt = 1; attempt <= 6; attempt++) {
  const base = calcReconnectDelayMs(attempt, { jitter: false })
  for (const rnd of [0, 0.25, 0.5, 0.75, 1]) {
    const d = calcReconnectDelayMs(attempt, { jitter: true, random: () => rnd })
    const lo = Math.floor(base * 0.5)
    const hi = Math.floor(base)
    assert(d >= lo && d <= hi, `jitter attempt=${attempt} rnd=${rnd} delay=${d} in [${lo},${hi}]`)
  }
}

// 4) 惊群模拟：同 attempt 下延迟方差应明显（不全相等）
const stormN = (() => {
  const arg = process.argv.find((a) => a.startsWith('--storm='))
  return arg ? Math.max(10, parseInt(arg.split('=')[1], 10) || 2000) : 500
})()
const delays = []
for (let i = 0; i < stormN; i++) {
  delays.push(calcReconnectDelayMs(1, { jitter: true }))
}
const uniq = new Set(delays).size
const min = Math.min(...delays)
const max = Math.max(...delays)
const avg = delays.reduce((a, b) => a + b, 0) / delays.length
console.log(
  `[INFO] storm n=${stormN} attempt=1 unique=${uniq} min=${min} max=${max} avg=${avg.toFixed(0)}`
)
assert(uniq >= 10, `storm should spread delays (unique=${uniq}, need >=10)`)
assert(max - min >= 500, `storm spread range should be >=500ms (got ${max - min})`)

console.log(`\nSummary: fail=${fail}`)
process.exit(fail > 0 ? 1 : 0)
