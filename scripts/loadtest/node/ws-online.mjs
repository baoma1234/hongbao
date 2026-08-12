/**
 * Node 版 WS 压测（Windows 无 k6 时可用）
 *
 *   cd scripts/loadtest && npm install
 *   node node/ws-online.mjs --vus=200 --duration=180
 */
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import WebSocket from 'ws'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const root = path.resolve(__dirname, '..')

function arg(name, fallback) {
  const hit = process.argv.find((a) => a.startsWith('--' + name + '='))
  return hit ? hit.split('=').slice(1).join('=') : fallback
}

const wsBase = arg('ws', process.env.WS_BASE || 'wss://hbsq.bio/im-ws')
const tokensPath = path.resolve(root, arg('tokens', 'tokens.json'))
const vus = Math.max(1, Number(arg('vus', process.env.VUS || 100)))
const durationSec = Math.max(30, Number(arg('duration', process.env.DURATION || 180)))
const tokens = JSON.parse(fs.readFileSync(tokensPath, 'utf8'))

let connected = 0
let authed = 0
let errors = 0
let closed = 0
const authLat = []

function wsUrl(row) {
  const q =
    'token=' +
    encodeURIComponent(row.token) +
    '&device_fp=' +
    encodeURIComponent(row.device_fp || 'load-' + row.user_id)
  return wsBase + (wsBase.includes('?') ? '&' : '?') + q
}

function runOne(i) {
  const row = tokens[i % tokens.length]
  const t0 = Date.now()
  const ws = new WebSocket(wsUrl(row), { handshakeTimeout: 15000 })
  let ok = false
  let pingTimer

  ws.on('open', () => {
    connected++
  })

  ws.on('message', (buf) => {
    let pkt
    try {
      pkt = JSON.parse(String(buf))
    } catch (e) {
      return
    }
    if (pkt.type === 'auth.ok' && !ok) {
      ok = true
      authed++
      authLat.push(Date.now() - t0)
      pingTimer = setInterval(() => {
        if (ws.readyState === WebSocket.OPEN) {
          ws.send(
            JSON.stringify({
              type: 'ping',
              data: {},
              req_id: 'p-' + i + '-' + Date.now(),
            })
          )
        }
      }, 25000)
    }
  })

  ws.on('error', () => {
    errors++
  })

  ws.on('close', () => {
    closed++
    if (pingTimer) clearInterval(pingTimer)
  })

  setTimeout(() => {
    try {
      ws.close()
    } catch (e) {}
  }, durationSec * 1000)
}

console.log('WS load test', { wsBase, vus, durationSec, tokens: tokens.length })

for (let i = 0; i < vus; i++) {
  setTimeout(() => runOne(i), (i % 50) * 20)
}

const report = setInterval(() => {
  console.log(
    `[${new Date().toISOString()}] connected=${connected} authed=${authed} closed=${closed} errors=${errors}`
  )
}, 10000)

setTimeout(() => {
  clearInterval(report)
  authLat.sort((a, b) => a - b)
  const p95 = authLat[Math.floor(authLat.length * 0.95)] || 0
  console.log('done', {
    connected,
    authed,
    closed,
    errors,
    auth_p95_ms: p95,
    auth_rate: authed / Math.max(1, vus),
  })
  process.exit(errors > vus * 0.05 ? 1 : 0)
}, durationSec * 1000 + 5000)
