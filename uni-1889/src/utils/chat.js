/** 会话 / 消息展示辅助（对齐 888 preview） */

import {
  ensureAbsoluteHttpUrl,
  FALLBACK_RUNTIME,
  getApiBase,
  getImgBase,
  getUploadsBase,
  isOssHostUrl,
  learnUploadCdnFromUrl,
  packagedStaticUrl,
} from './config.js'

export function convKey(type, id) {
  return String(type | 0) + ':' + String(id || '')
}

export function msgType(m) {
  if (!m) return 1
  return (m.msg_type | 0) || (m.type | 0) || 1
}

export function msgExtra(m) {
  if (!m) return {}
  let e = m.extra
  if (e == null || e === '') return {}
  if (typeof e === 'string') {
    try {
      e = JSON.parse(e) || {}
    } catch (err) {
      e = {}
    }
    // 入表后写回对象，后续热路径不再反复 JSON.parse（可安全用于普通消息对象）
    try {
      m.extra = e
    } catch (err2) {}
    return e
  }
  return e
}

/** 规范化消息 extra（history / 本地插入时调用） */
export function normalizeMessage(m) {
  if (!m || typeof m !== 'object') return m
  msgExtra(m)
  return m
}

export function packetTypeLabel(t) {
  const n = t | 0
  if (n === 2) return '红宝拼手气'
  if (n === 3) return '红宝扫雷'
  if (n === 5) return '红宝接龙'
  if (n === 4) return '随机'
  return '普通'
}

export function isRecalled(m) {
  return !!(m && (m.status | 0) === 2)
}

export function isSystemMsg(m) {
  if (!m) return false
  const mt = msgType(m)
  return mt === 3 || mt === 99
}

/** 退群系统提示（服务端已停发；历史/残留推送仍过滤） */
export function isLeaveGroupTip(m) {
  if (!m) return false
  const ex = msgExtra(m)
  if (String(ex.event || '') === 'leave') return true
  const c = String(m.content || '')
  return /退出了群组/.test(c)
}

export function recallTip(m, myId, isPrivate) {
  const mine = (m && (m.from_user_id | 0)) === (myId | 0)
  if (isPrivate) return mine ? '你删除了一条消息' : '对方删除了一条消息'
  return mine ? '你撤回了一条消息' : '对方撤回了一条消息'
}

export function previewText(last) {
  if (!last) return '暂无消息'
  if (typeof last === 'string') return last
  if (isLeaveGroupTip(last)) return '暂无消息'
  if (isRecalled(last)) return '[已撤回]'
  const mt = msgType(last)
  const ex = msgExtra(last)
  if (mt === 2) {
    const bless = ex.blessing || '恭喜发财'
    return '[红包] ' + bless
  }
  if (mt === 10 || ex.niuniu) {
    return '[尾数牛牛] ' + (last.content || '红包尾数牛牛')
  }
  if (mt === 11 || ex.fission || ex.fission_share) {
    return '[裂变红宝] 官方活动'
  }
  if (mt === 4) {
    return '[图片]'
  }
  if (mt === 5) {
    return '[视频]'
  }
  if (mt === 6) {
    return '[表情]'
  }
  if (mt === 7) {
    return '[文件]'
  }
  if (mt === 8) {
    const amt = ex.amount != null ? parseFloat(ex.amount) : NaN
    if (!isNaN(amt) && amt > 0) return '[转账] ￥' + amt.toFixed(2)
    return last.content || '[转账]'
  }
  if (mt === 99 || (last.content && String(last.content).indexOf('[系统]') === 0)) {
    return last.content || '[系统消息]'
  }
  return last.content || last.text || '暂无消息'
}

export function formatConvTime(ts) {
  const t = Number(ts) || 0
  if (!t) return ''
  const d = new Date(t < 1e12 ? t * 1000 : t)
  const now = new Date()
  const pad = (n) => (n < 10 ? '0' + n : '' + n)
  const hm = pad(d.getHours()) + ':' + pad(d.getMinutes())
  if (
    d.getFullYear() === now.getFullYear() &&
    d.getMonth() === now.getMonth() &&
    d.getDate() === now.getDate()
  ) {
    return hm
  }
  return pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + hm
}

export function displayTitle(item) {
  if (!item) return '会话'
  if (item.remark) return String(item.remark)
  if (item.title) return String(item.title)
  if (item.peer_nickname) return String(item.peer_nickname)
  if ((item.conversation_type | 0) === 2) return '群 ' + (item.group_id || item.conversation_id || '')
  return '用户' + (item.peer_user_id || '')
}

export function resolveConvId(item) {
  const type = item.conversation_type | 0
  if (type === 2) return String(item.group_id || item.conversation_id || '')
  return String(item.conversation_id || '')
}

export function avatarLetter(title) {
  const s = String(title || '?').trim()
  return s ? s.charAt(0) : '?'
}

/** /uploads 与媒体公网地址：优先 OSS，避免 888.json 的本站 imgUri 盖掉阿里云 */
export function publicUrl(pathOrUrl) {
  const raw = String(pathOrUrl || '').trim()
  if (!raw) return ''
  if (/^https?:\/\//i.test(raw) || /^data:/i.test(raw) || /^blob:/i.test(raw)) {
    if (isOssHostUrl(raw)) {
      learnUploadCdnFromUrl(raw)
      return raw
    }
    // 已是绝对地址：本站 / API 站的 /uploads 改写到 OSS
    try {
      const u = new URL(raw)
      if (u.pathname.indexOf('/uploads/') === 0) {
        const oss = getUploadsBase()
        if (oss && !isOssHostUrl(u.origin)) {
          return oss + u.pathname + u.search
        }
        const api = String(getApiBase() || '')
          .trim()
          .replace(/\/+$/, '')
        const img = String(getImgBase() || '')
          .trim()
          .replace(/\/+$/, '')
        // 兼容：upload_cdn 已写入 getImgBase 且与 API 不同
        if (img && api && isOssHostUrl(img) && u.origin === new URL(api + '/').origin) {
          return img + u.pathname + u.search
        }
      }
    } catch (e) {}
    return raw
  }
  let url = raw
  if (url.charAt(0) !== '/') {
    url = '/' + url.replace(/^\.\//, '')
  }
  if (url.indexOf('/uploads/') === 0) {
    const oss = getUploadsBase()
    if (oss) return oss + url
    const base = String(getImgBase() || getApiBase() || '')
      .trim()
      .replace(/\/+$/, '')
    if (base) return base + url
    if (typeof location !== 'undefined' && location.origin) return location.origin + url
    return url
  }
  // App 无 location.origin；相对路径必须补成 https，否则 <image> 空白
  if (url.charAt(0) === '/') {
    const abs = ensureAbsoluteHttpUrl(url, getApiBase() || FALLBACK_RUNTIME.apiUri)
    if (abs) return abs
    if (typeof location !== 'undefined' && location.origin) return location.origin + url
  }
  return url
}

/** 默认头像（统一静态图）；空头像 / SVG data URI / 旧默认图回退 */
export const DEFAULT_AVATAR_PATH = '/uploads/20260827/66a512d4927f66cb97135477153ba4e7.png'
export const DEFAULT_AVATAR_OSS =
  'https://888jhdhifhbchashjdl.oss-accelerate.aliyuncs.com/uploads/20260827/66a512d4927f66cb97135477153ba4e7.png'
export const DEFAULT_AVATAR_VER = '202608270240'

export function defaultAvatarUrl() {
  return publicUrl(DEFAULT_AVATAR_PATH) || (DEFAULT_AVATAR_OSS + '?v=' + DEFAULT_AVATAR_VER)
}

function isPlaceholderAvatar(url) {
  const raw = String(url == null ? '' : url).trim()
  if (!raw) return true
  if (/^data:image\/svg/i.test(raw)) return true
  if (raw.indexOf('/uploads/brand/default-avatar.png') >= 0) return true
  if (raw.indexOf('f48cc40355dd0f6d814e68ff6e414443') >= 0) return true
  return false
}

/** 对齐 888 avatarSrc：空头像也回默认图，避免空白块；短 LRU 缓存减轻列表重复拼 URL */
const avatarSrcCache = new Map()
const AVATAR_SRC_CACHE_MAX = 240

export function avatarSrc(url) {
  const raw = String(url == null ? '' : url).trim()
  const key = raw || '__default__'
  const hit = avatarSrcCache.get(key)
  if (hit) return hit
  let out = defaultAvatarUrl()
  if (raw && !isPlaceholderAvatar(raw)) {
    if (
      raw.indexOf('/') < 0 &&
      !/^https?:\/\//i.test(raw) &&
      !/\.(png|jpe?g|gif|webp|svg)(\?|$)/i.test(raw)
    ) {
      out = defaultAvatarUrl()
    } else {
      out = publicUrl(raw) || defaultAvatarUrl()
    }
  }
  if (avatarSrcCache.size >= AVATAR_SRC_CACHE_MAX) {
    avatarSrcCache.clear()
  }
  avatarSrcCache.set(key, out)
  return out
}
