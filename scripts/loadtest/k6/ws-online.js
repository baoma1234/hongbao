/**
 * FansHub IM WebSocket 在线压测（hbsq.bio 默认）
 *
 * 前置：
 *   php scripts/loadtest/export_tokens.php --reuse-bots --count=5000
 *   k6 run scripts/loadtest/k6/ws-online.js
 *
 * 环境变量：
 *   WS_BASE   默认 wss://hbsq.bio/im-ws
 *   TOKENS    默认 ../tokens.json
 *   VUS       覆盖 stages 目标（快速冒烟）
 *   DURATION  每档停留秒数，默认 3m
 */
import ws from 'k6/ws'
import { check, sleep } from 'k6'
import { Counter, Rate, Trend } from 'k6/metrics'

const wsBase = __ENV.WS_BASE || 'wss://hbsq.bio/im-ws'
const tokensFile = __ENV.TOKENS || '../tokens.json'
const tokens = JSON.parse(open(tokensFile))
const quickVus = Number(__ENV.VUS || 0)
const stageDur = __ENV.DURATION || '3m'

const authOk = new Rate('ws_auth_ok')
const authMs = new Trend('ws_auth_ms', true)
const pongMs = new Trend('ws_pong_ms', true)
const disconnects = new Counter('ws_disconnects')

export const options = quickVus
  ? {
      vus: quickVus,
      duration: __ENV.DURATION || '2m',
      thresholds: {
        ws_auth_ok: ['rate>0.98'],
        ws_auth_ms: ['p(95)<3000'],
      },
    }
  : {
      stages: [
        { duration: '1m', target: 200 },
        { duration: stageDur, target: 1000 },
        { duration: stageDur, target: 3000 },
        { duration: stageDur, target: 5000 },
        { duration: stageDur, target: 10000 },
        { duration: '2m', target: 0 },
      ],
      thresholds: {
        ws_auth_ok: ['rate>0.98'],
        ws_auth_ms: ['p(95)<5000'],
        ws_pong_ms: ['p(95)<2000'],
      },
    }

function pickToken(vu) {
  const row = tokens[(vu - 1) % tokens.length]
  return row || tokens[0]
}

function wsUrl(token, deviceFp) {
  const q =
    'token=' +
    encodeURIComponent(token) +
    '&device_fp=' +
    encodeURIComponent(deviceFp || 'load-' + __VU)
  return wsBase + (wsBase.indexOf('?') >= 0 ? '&' : '?') + q
}

export default function () {
  const row = pickToken(__VU)
  const url = wsUrl(row.token, row.device_fp)
  let authed = false
  let authStart = Date.now()
  let lastPing = 0

  const res = ws.connect(url, {}, function (socket) {
    socket.on('open', () => {
      authStart = Date.now()
    })

    socket.on('message', (data) => {
      let pkt
      try {
        pkt = JSON.parse(data)
      } catch (e) {
        return
      }
      if (!pkt || !pkt.type) return

      if (pkt.type === 'auth.ok' && !authed) {
        authed = true
        authMs.add(Date.now() - authStart)
        authOk.add(1)
      }
      if (pkt.type === 'pong' && pkt.req_id) {
        pongMs.add(Date.now() - lastPing)
      }
    })

    socket.on('close', () => {
      disconnects.add(1)
    })

    socket.setInterval(() => {
      if (!authed) return
      lastPing = Date.now()
      socket.send(
        JSON.stringify({
          type: 'ping',
          data: {},
          req_id: 'p-' + __VU + '-' + lastPing,
        })
      )
    }, 25000)

    // 挂线 3～5 分钟
    const hold = 180000 + Math.floor(Math.random() * 120000)
    socket.setTimeout(() => socket.close(), hold)
  })

  check(res, { ws_handshake_101: (r) => r && r.status === 101 })
  if (!authed) authOk.add(0)
  sleep(1 + Math.random())
}
