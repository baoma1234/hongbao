/**
 * 群进群链接：?join_group=ID&gt=TOKEN （search 或 hash query）
 * 打开后登录用户自动加入群聊。
 */
import { getToken } from './auth.js'
import { joinGroup, imConnect } from './im.js'

export const GROUP_JOIN_STORAGE_KEY = 'fanshub_pending_join_group'

function parseQueryMap() {
  const out = {}
  try {
    if (typeof location === 'undefined') return out
    const merge = (raw) => {
      const q = String(raw || '').replace(/^\?/, '')
      if (!q) return
      const sp = new URLSearchParams(q)
      sp.forEach((v, k) => {
        if (v != null && out[k] == null) out[k] = String(v)
      })
    }
    merge(location.search || '')
    const hash = String(location.hash || '')
    const qi = hash.indexOf('?')
    if (qi >= 0) merge(hash.slice(qi + 1))
  } catch (e) {}
  return out
}

export function readUrlGroupJoin() {
  try {
    const q = parseQueryMap()
    const gid = parseInt(String(q.join_group || q.g || '0'), 10) || 0
    const token = String(q.gt || q.invite_token || '').trim()
    if (gid <= 0) return null
    return { group_id: gid, token }
  } catch (e) {
    return null
  }
}

export function peekPendingGroupJoin() {
  try {
    const raw = uni.getStorageSync(GROUP_JOIN_STORAGE_KEY)
    if (!raw) return null
    const obj = typeof raw === 'string' ? JSON.parse(raw) : raw
    const gid = (obj && obj.group_id) | 0
    if (gid <= 0) return null
    return { group_id: gid, token: String((obj && obj.token) || '').trim() }
  } catch (e) {
    return null
  }
}

export function savePendingGroupJoin(groupId, token = '') {
  const gid = groupId | 0
  if (gid <= 0) return null
  const payload = { group_id: gid, token: String(token || '').trim() }
  try {
    uni.setStorageSync(GROUP_JOIN_STORAGE_KEY, JSON.stringify(payload))
  } catch (e) {}
  return payload
}

export function clearPendingGroupJoin() {
  try {
    uni.removeStorageSync(GROUP_JOIN_STORAGE_KEY)
  } catch (e) {}
}

/** 启动时：从 URL 记下待进群 */
export function captureGroupJoinFromUrl() {
  const fromUrl = readUrlGroupJoin()
  if (fromUrl) savePendingGroupJoin(fromUrl.group_id, fromUrl.token)
  return peekPendingGroupJoin()
}

/**
 * 已登录则执行进群并进入聊天；未登录则保留 pending，登录后会再试。
 * @returns {Promise<boolean>} 是否已处理并跳转
 */
export async function tryConsumeGroupJoin(opts = {}) {
  const silent = !!(opts && opts.silent)
  let pending = peekPendingGroupJoin() || readUrlGroupJoin()
  if (!pending || !(pending.group_id | 0)) return false
  if (!getToken()) {
    savePendingGroupJoin(pending.group_id, pending.token)
    try {
      uni.setStorageSync('fanshub_login_return', '/pages/messages/messages')
    } catch (e) {}
    return false
  }
  const gid = pending.group_id | 0
  const token = String(pending.token || '').trim()
  try {
    await imConnect().catch(() => {})
    await joinGroup(gid, token)
    clearPendingGroupJoin()
    if (!silent) {
      uni.showToast({ title: '已加入群聊', icon: 'none' })
    }
    uni.navigateTo({
      url:
        '/pages/chat/chat?type=2&id=' +
        encodeURIComponent(gid) +
        '&group=' +
        encodeURIComponent(gid) +
        '&title=' +
        encodeURIComponent('群' + gid),
    })
    return true
  } catch (e) {
    // 已在群内也算成功
    const msg = String((e && e.message) || '')
    if (/already|已在|member/i.test(msg) || msg === '') {
      clearPendingGroupJoin()
      uni.navigateTo({
        url:
          '/pages/chat/chat?type=2&id=' +
          encodeURIComponent(gid) +
          '&group=' +
          encodeURIComponent(gid),
      })
      return true
    }
    if (!silent) {
      uni.showToast({ title: msg || '进群失败', icon: 'none' })
    }
    return false
  }
}
