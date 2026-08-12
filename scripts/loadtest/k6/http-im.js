/**
 * FansHub IM HTTP API 压测（/im-api）
 *
 *   php scripts/loadtest/export_tokens.php --count=2000
 *   k6 run scripts/loadtest/k6/http-im.js
 */
import http from 'k6/http'
import { check, sleep } from 'k6'
import { Rate, Trend } from 'k6/metrics'

const apiBase = (__ENV.API_BASE || 'https://hbsq.bio').replace(/\/+$/, '')
const imApi = apiBase + '/im-api'
const tokensFile = __ENV.TOKENS || '../tokens.json'
const tokens = JSON.parse(open(tokensFile))
const quickVus = Number(__ENV.VUS || 0)

const convOk = new Rate('im_conversations_ok')
const convMs = new Trend('im_conversations_ms', true)
const healthOk = new Rate('im_health_ok')

export const options = quickVus
  ? {
      vus: quickVus,
      duration: __ENV.DURATION || '2m',
      thresholds: {
        im_conversations_ok: ['rate>0.98'],
        im_conversations_ms: ['p(95)<3000'],
      },
    }
  : {
      stages: [
        { duration: '1m', target: 50 },
        { duration: '3m', target: 200 },
        { duration: '3m', target: 500 },
        { duration: '3m', target: 1000 },
        { duration: '2m', target: 0 },
      ],
      thresholds: {
        im_conversations_ok: ['rate>0.98'],
        im_conversations_ms: ['p(95)<2500'],
      },
    }

function pickToken(vu) {
  const row = tokens[(vu - 1) % tokens.length]
  return row || tokens[0]
}

export function setup() {
  const r = http.get(imApi + '/health', { tags: { name: 'health' } })
  healthOk.add(r.status === 200)
  return { ok: r.status === 200 }
}

export default function () {
  const row = pickToken(__VU)
  const body = JSON.stringify({ token: row.token })
  const params = {
    headers: {
      'Content-Type': 'application/json',
      'X-Fans-Token': row.token,
    },
    tags: { name: 'conversations' },
  }

  const t0 = Date.now()
  const res = http.post(imApi + '/im/conversations', body, params)
  const ok = res.status === 200 && res.json('code') === 1
  convOk.add(ok)
  convMs.add(Date.now() - t0)
  check(res, {
    'status 200': (r) => r.status === 200,
    'code 1': (r) => r.json('code') === 1,
  })

  // 10% VU 顺带拉历史（轻量）
  if (__VU % 10 === 0) {
    http.post(
      imApi + '/im/history',
      JSON.stringify({ token: row.token, peer_type: 'group', peer_id: 1, limit: 20 }),
      params
    )
  }

  sleep(0.5 + Math.random() * 1.5)
}
