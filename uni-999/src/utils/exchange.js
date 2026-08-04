import { apiRequest, fetchProfile } from './auth.js'

const DEFAULT_MAX = 99999

export function requestId(prefix = 'exs') {
  return (
    prefix +
    '_' +
    Date.now().toString(36) +
    '_' +
    Math.random().toString(36).slice(2, 8)
  )
}

export function freeRightsOf(profile) {
  const p = profile || {}
  if (p.rights_free != null) return Math.max(0, Number(p.rights_free) || 0)
  const rights = Number(p.rights || 0)
  const locked = Number(p.rights_locked || 0)
  return Math.max(0, rights - locked)
}

export function hongbaoOf(profile) {
  const p = profile || {}
  const n = p.hongbao != null ? p.hongbao : p.balance
  return Math.max(0, Number(n) || 0)
}

export function pairInfo(cfg, from, to) {
  let f = from === 'balance' ? 'hongbao' : from
  let t = to === 'balance' ? 'hongbao' : to
  const pairs = (cfg && cfg.exchange_pairs) || {}
  const key = f + '_' + t
  if (pairs[key]) {
    const p = pairs[key]
    return {
      enabled: p.enabled !== false,
      min: Number(p.min) || 1,
      max: Number(p.max) || DEFAULT_MAX,
    }
  }
  if (f === 'rights' && t === 'hongbao') {
    return {
      enabled: cfg ? cfg.exchange_rights_to_balance_enabled !== false : true,
      min: Math.max(1, Number((cfg && cfg.exchange_r2b_min) || 1)),
      max: DEFAULT_MAX,
    }
  }
  if (f === 'hongbao' && t === 'rights') {
    return {
      enabled: cfg ? cfg.exchange_balance_to_rights_enabled !== false : true,
      min: Math.max(1, Number((cfg && cfg.exchange_b2r_min) || 1)),
      max: DEFAULT_MAX,
    }
  }
  return { enabled: false, min: 1, max: DEFAULT_MAX }
}

export function unitValue(cfg, asset, sharePrice) {
  if (asset === 'hongbao' || asset === 'balance') {
    return Math.max(0.0001, Number((cfg && cfg.hongbao_unit_value) || 1))
  }
  const price =
    sharePrice != null
      ? sharePrice
      : (cfg && (cfg.current_share_price || cfg.single_ticket_value)) || 5
  return Math.max(0.0001, Number(price) || 5)
}

export function estimateCredit(cfg, from, to, amount, sharePrice) {
  const amt = Math.max(0, Number(amount) || 0)
  const fromU = unitValue(cfg, from, sharePrice)
  const toU = unitValue(cfg, to, sharePrice)
  if (!(toU > 0)) return 0
  return (amt * fromU) / toU
}

export function rateLine(cfg, from, to, sharePrice) {
  const fromU = unitValue(cfg, from, sharePrice)
  const toU = unitValue(cfg, to, sharePrice)
  return toU > 0 ? fromU / toU : 0
}

export async function loadExchangeBootstrap() {
  let cfg = null
  let profile = null
  try {
    const data = await apiRequest('bootstrap', 'GET', { include: 'home' })
    if (data) {
      cfg = data.config || null
      profile = data.profile || null
      if (data.market && data.market.share_price != null && cfg) {
        cfg = Object.assign({}, cfg, {
          current_share_price: data.market.share_price,
          single_ticket_value: data.market.share_price,
        })
      }
    }
  } catch (e) {}
  if (!profile) {
    profile = await fetchProfile()
  }
  if (!cfg) {
    try {
      cfg = await apiRequest('config', 'GET')
    } catch (e2) {
      cfg = {}
    }
  }
  return { config: cfg || {}, profile: profile || {} }
}

export async function submitSwap(from, to, amount) {
  return apiRequest('exchangeswap', 'POST', {
    from,
    to,
    amount,
    channel: 'swap',
    request_id: requestId('exs'),
  })
}
