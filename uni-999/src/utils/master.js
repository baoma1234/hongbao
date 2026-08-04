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

export function honorTier(honor, t) {
  const nodes = (honor && honor.nodes) || []
  const nextId = honor && honor.next_tier ? honor.next_tier.id : 0
  const price = Number((honor && honor.share_price) || 5)
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
    }
  })
}
