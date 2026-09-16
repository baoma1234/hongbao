/**
 * 将正文中的 http(s):// 拆成可点击片段（H5 / App / Safari 通用）
 * @param {string} text
 * @returns {{ type: 'text'|'link', value: string }[]}
 */
export function splitHttpUrlParts(text) {
  const s = String(text || '')
  if (!s) return []
  if (!/https?:\/\//i.test(s)) {
    return [{ type: 'text', value: s }]
  }
  const re = /https?:\/\/[^\s<>"'，。！？；：、）】」』》\u3000]+/gi
  const parts = []
  let last = 0
  let m
  while ((m = re.exec(s)) !== null) {
    if (m.index > last) {
      parts.push({ type: 'text', value: s.slice(last, m.index) })
    }
    let url = m[0]
    while (url.length > 8 && /[.,!?;:，。！？；：、）】」』》'"…]+$/.test(url)) {
      url = url.slice(0, -1)
    }
    if (url && /^https?:\/\//i.test(url)) {
      parts.push({ type: 'link', value: url })
    } else {
      parts.push({ type: 'text', value: m[0] })
      last = m.index + m[0].length
      continue
    }
    const trail = m[0].slice(url.length)
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
