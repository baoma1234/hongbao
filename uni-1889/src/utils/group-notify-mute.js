/**
 * 群「消息不提醒」本地缓存（提示音跳过）
 * 服务端 notify_mute 决定是否极光推送
 */

const KEY = 'hb_group_notify_mute'

function readMap() {
  try {
    const raw = uni.getStorageSync(KEY)
    if (!raw) return {}
    if (typeof raw === 'object') return raw || {}
    const o = JSON.parse(String(raw))
    return o && typeof o === 'object' ? o : {}
  } catch (e) {
    return {}
  }
}

function writeMap(map) {
  try {
    uni.setStorageSync(KEY, map || {})
  } catch (e) {}
}

export function isGroupNotifyMuted(groupId) {
  const gid = groupId | 0
  if (!gid) return false
  const m = readMap()
  return !!m[String(gid)]
}

export function setGroupNotifyMuted(groupId, muted) {
  const gid = groupId | 0
  if (!gid) return
  const m = readMap()
  if (muted) m[String(gid)] = 1
  else delete m[String(gid)]
  writeMap(m)
}
