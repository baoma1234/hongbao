import { apiRequest, fetchProfile } from './auth.js'

export async function loadMasterProfile() {
  const profile = await fetchProfile()
  return profile || {}
}

export function phase2Of(profile) {
  return (profile && profile.phase2) || { enabled: false }
}

export function isMaster(p2) {
  return !!(p2 && p2.enabled && p2.user_mode === 'master')
}

export async function loadTeamRadar() {
  return apiRequest('teamradar', 'GET')
}

export async function doCheckin(violent, confirmed) {
  return apiRequest('checkin', 'POST', {
    violent: violent ? 1 : 0,
    confirmed: confirmed ? 1 : 0,
  })
}

export async function copySharePromo() {
  return apiRequest('share', 'POST', { copy_only: 1 })
}

export async function urgeCopy(inviteeUserId) {
  return apiRequest('urgecopy', 'POST', { invitee_user_id: inviteeUserId | 0 })
}

export function copyText(text) {
  const s = String(text || '')
  return new Promise((resolve, reject) => {
    if (!s) {
      reject(new Error('empty'))
      return
    }
    uni.setClipboardData({
      data: s,
      success: () => resolve(true),
      fail: (err) => reject(err || new Error('copy fail')),
    })
  })
}

function teamRadarDaySeed() {
  const d = new Date()
  return d.getFullYear() * 10000 + (d.getMonth() + 1) * 100 + d.getDate()
}

function teamRadarRng(seed) {
  let a = (seed >>> 0) || 1
  return function () {
    a |= 0
    a = (a + 0x6d2b79f5) | 0
    let t = Math.imul(a ^ (a >>> 15), 1 | a)
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296
  }
}

/** 虚拟战队雷达：多国脱敏号 + 接近门槛进度（对齐 888） */
export function buildVirtualTeamRadar(limit, threshold) {
  limit = Math.max(3, Math.min(12, limit || 8))
  const th = Number(threshold) || 50
  const rnd = teamRadarRng(teamRadarDaySeed() ^ 0x7eada1)
  const pools = [
    { dial: '+86', heads: ['130', '135', '136', '137', '138', '139', '150', '158', '186', '188'], tailLen: 4 },
    { dial: '+63', heads: ['905', '906', '915', '917', '918', '919', '920', '921'], tailLen: 4 },
    { dial: '+84', heads: ['90', '91', '93', '96', '97', '98', '32', '33'], tailLen: 4 },
    { dial: '+60', heads: ['10', '11', '12', '13', '16', '17', '18', '19'], tailLen: 4 },
    { dial: '+855', heads: ['10', '11', '12', '15', '16', '17', '70', '77'], tailLen: 3 },
    { dial: '+62', heads: ['812', '813', '815', '816', '817', '818', '821', '822'], tailLen: 4 },
  ]
  const fracs = [0.96, 0.88, 0.76, 0.64, 0.52, 0.41, 0.33, 1.0, 0.28, 0.22, 0.18, 0.12]
  const rows = []
  for (let i = 0; i < limit; i++) {
    const pool = pools[Math.floor(rnd() * pools.length)]
    const head = pool.heads[Math.floor(rnd() * pool.heads.length)]
    let tail = ''
    for (let t = 0; t < pool.tailLen; t++) tail += String(Math.floor(rnd() * 10))
    const frac = fracs[i] != null ? fracs[i] : 0.2 + rnd() * 0.5
    let bal = Math.round(th * frac * 100) / 100
    if (frac >= 1) bal = th
    else bal = Math.min(th - 0.5, Math.max(1, bal + Math.floor(rnd() * 3) - 1))
    const withdrawn = bal >= th
    rows.push({
      user_id: -(i + 1),
      mobile_mask: pool.dial + ' ' + head + '****' + tail,
      balance: bal,
      hongbao: bal,
      threshold: th,
      withdrawn,
      virtual: true,
    })
  }
  rows.sort((a, b) => {
    if (a.withdrawn !== b.withdrawn) return a.withdrawn ? 1 : -1
    return b.balance - a.balance
  })
  return rows
}

export function mergeTeamRadar(realList, threshold) {
  let list = buildVirtualTeamRadar(8, threshold)
  const real = Array.isArray(realList) ? realList : []
  if (real.length) {
    const map = {}
    real.forEach((r) => {
      if (!r || !r.mobile_mask) return
      map[r.mobile_mask] = {
        user_id: r.user_id,
        mobile_mask: r.mobile_mask,
        balance: Number(r.balance != null ? r.balance : r.hongbao) || 0,
        hongbao: Number(r.hongbao != null ? r.hongbao : r.balance) || 0,
        threshold: Number(r.threshold) || threshold || 50,
        withdrawn: !!r.withdrawn,
        virtual: false,
      }
    })
    list.forEach((v) => {
      if (!map[v.mobile_mask]) map[v.mobile_mask] = v
    })
    list = Object.keys(map).map((k) => map[k])
    list.sort((a, b) => {
      if (!!a.withdrawn !== !!b.withdrawn) return a.withdrawn ? 1 : -1
      return (b.balance || 0) - (a.balance || 0)
    })
    list = list.slice(0, 10)
  }
  return list
}

export function honorTier(honor, t) {
  const nodes = (honor && honor.nodes) || []
  const nextId = honor && honor.next_tier ? honor.next_tier.id : 0
  const price = Number((honor && honor.share_price) || 5)
  const iconClass = {
    1: 'bronze',
    2: 'silver',
    3: 'diamond',
    4: 'crown',
    5: 'glory',
    bronze: 'bronze',
    silver: 'silver',
    diamond: 'diamond',
    crown: 'crown',
    glory: 'glory',
  }
  return nodes.map((n) => {
    const rightsNum = parseFloat(n.rights) || 0
    const rightsVal =
      n.rights_val != null
        ? Math.round(Number(n.rights_val) || 0)
        : Math.round(rightsNum * price)
    const bal = Math.round(parseFloat(n.balance) || 0)
    const reached = !!n.reached
    const isCurrent = !reached && nextId && String(n.id) === String(nextId)
    const name = t('phase2_honor_name_' + n.id) || n.name || '段位' + n.id
    let badge = t('phase2_honor_badge_lock') || '待解锁'
    let state = 'locked'
    if (reached) {
      badge = t('phase2_honor_badge_done') || '已解锁'
      state = 'reached'
    } else if (isCurrent) {
      badge = t('phase2_honor_badge_next') || '冲刺中'
      state = 'current'
    }
    const ico = iconClass[n.icon] || iconClass[n.id] || 'bronze'
    return {
      id: n.id,
      name,
      threshold: n.threshold || 0,
      rightsText: String(n.rights != null ? n.rights : 0)
        .replace(/\.0+$/, '')
        .replace(/(\.\d*?)0+$/, '$1')
        .replace(/\.$/, ''),
      rightsVal,
      balance: bal,
      badge,
      state,
      icon: ico,
      cashHot: bal > 0 && Number(n.id) >= 4,
    }
  })
}
