/**
 * 大厅浮标 / 轮播站内跳转（tab 页 switchTab，query 用本地缓存透传）
 */
import { openExternalHttpUrl } from './wallet.js'

export function openLobbyLink(raw) {
  let u = String(raw || '').trim()
  if (!u) return
  if (u.charAt(0) === '#') u = u.slice(1)
  if (/^https?:\/\//i.test(u)) {
    openExternalHttpUrl(u)
    return
  }
  if (u.charAt(0) !== '/') u = '/' + u
  const qIdx = u.indexOf('?')
  const pathOnly = qIdx >= 0 ? u.slice(0, qIdx) : u
  const qs = qIdx >= 0 ? u.slice(qIdx + 1) : ''
  const params = {}
  if (qs) {
    qs.split('&').forEach((pair) => {
      const i = pair.indexOf('=')
      const k = decodeURIComponent(i >= 0 ? pair.slice(0, i) : pair)
      const v = decodeURIComponent(i >= 0 ? pair.slice(i + 1) : '')
      if (k) params[k] = v
    })
  }
  if (pathOnly === '/pages/notice/notice' && params.cat) {
    try {
      uni.setStorageSync('fanshub_notice_cat', String(params.cat))
    } catch (e) {}
  }
  if (pathOnly === '/pages/community/community' && params.sub) {
    try {
      uni.setStorageSync('fanshub_community_sub', String(params.sub))
    } catch (e2) {}
  }
  const TAB = {
    '/pages/home/home': 1,
    '/pages/messages/messages': 1,
    '/pages/notice/notice': 1,
    '/pages/community/community': 1,
    '/pages/profile/profile': 1,
  }
  if (TAB[pathOnly]) {
    uni.switchTab({
      url: pathOnly,
      fail: () => uni.reLaunch({ url: pathOnly }),
    })
    return
  }
  if (u.indexOf('/pages/') === 0) {
    uni.navigateTo({
      url: u,
      fail: () => uni.reLaunch({ url: u }),
    })
  }
}
