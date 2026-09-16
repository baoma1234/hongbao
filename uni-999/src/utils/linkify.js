/**
 * 正文链接拆分：http(s):// 与裸域名（如 233698.com）均可点
 * @param {string} text
 * @returns {{ type: 'text'|'link', value: string, href?: string }[]}
 */
export function splitHttpUrlParts(text) {
  const s = String(text || '')
  if (!s) return []
  if (!/https?:\/\//i.test(s) && !/[a-zA-Z0-9]\.[a-zA-Z]{2,}/.test(s)) {
    return [{ type: 'text', value: s }]
  }
  // 完整 URL，或裸域名(+可选路径)；排除纯数字小数
  const re =
    /https?:\/\/[^\s<>"'，。！？；：、）】」』》\u3000]+|(?:www\.)?[a-zA-Z0-9][a-zA-Z0-9-]*(?:\.[a-zA-Z0-9][a-zA-Z0-9-]*)*\.[a-zA-Z]{2,}(?::\d{2,5})?(?:\/[^\s<>"'，。！？；：、）】」』》\u3000]*)?/gi
  const parts = []
  let last = 0
  let m
  while ((m = re.exec(s)) !== null) {
    if (m.index > last) {
      parts.push({ type: 'text', value: s.slice(last, m.index) })
    }
    let raw = m[0]
    while (raw.length > 3 && /[.,!?;:，。！？；：、）】」』》'"…]+$/.test(raw)) {
      raw = raw.slice(0, -1)
    }
    // 避免误伤邮箱本地部分：@ 紧挨域名时跳过（整段按文本）
    const prev = m.index > 0 ? s.charAt(m.index - 1) : ''
    if (prev === '@' || !isLikelyDomainOrUrl(raw)) {
      parts.push({ type: 'text', value: m[0] })
      last = m.index + m[0].length
      continue
    }
    const href = toHttpHref(raw)
    parts.push({ type: 'link', value: raw, href })
    const trail = m[0].slice(raw.length)
    if (trail) {
      parts.push({ type: 'text', value: trail })
    }
    last = m.index + m[0].length
  }
  if (last < s.length) {
    parts.push({ type: 'text', value: s.slice(last) })
  }
  return parts.length ? parts : [{ type: 'text', value: s }]
}

/** 裸域名补全 https://，已有协议则原样 */
export function toHttpHref(raw) {
  const u = String(raw || '').trim()
  if (!u) return ''
  if (/^https?:\/\//i.test(u)) return u
  return 'https://' + u.replace(/^\/+/, '')
}

function isLikelyDomainOrUrl(raw) {
  const u = String(raw || '').trim()
  if (!u) return false
  if (/^https?:\/\//i.test(u)) return true
  // 须含字母 TLD，且主机段不全是数字点（防 1.2.3）
  if (!/\.[a-zA-Z]{2,}(?:[/:?]|$)/.test(u) && !/\.[a-zA-Z]{2,}$/.test(u)) {
    return false
  }
  const host = u.split('/')[0].split('?')[0].split(':')[0]
  if (!host || host.length > 253) return false
  // 至少一段字母（域名），拒绝纯数字主机
  if (!/[a-zA-Z]/.test(host)) return false
  return true
}
