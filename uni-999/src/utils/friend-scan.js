/**
 * 扫一扫 / 相册识码加好友（个人中心与消息「+」共用）
 */
import { friendLookup, friendRequest, imConnect } from './im.js'
import { assetBase } from './i18n.js'

export function parseFriendPayload(text) {
  const raw = String(text || '').trim()
  if (!raw) return ''
  let m = raw.match(/FANSHUB_FRIEND[:：]\s*(\d{8})/i)
  if (m) return m[1]
  m = raw.match(/(?:friend|uid|user_id)[=:/]\s*(\d{8})/i)
  if (m) return m[1]
  m = raw.match(/^\d{8}$/)
  if (m) return m[0]
  m = raw.replace(/\D+/g, '').match(/(\d{8})/)
  return m ? m[1] : ''
}

function loadScriptOnce(src) {
  return new Promise((resolve, reject) => {
    // #ifdef H5
    if (typeof document === 'undefined') {
      reject(new Error('no document'))
      return
    }
    const exist = document.querySelector('script[data-src="' + src + '"]')
    if (exist) {
      resolve()
      return
    }
    const s = document.createElement('script')
    s.src = src
    s.async = true
    s.setAttribute('data-src', src)
    s.onload = () => resolve()
    s.onerror = () => reject(new Error('load fail'))
    document.head.appendChild(s)
    // #endif
    // #ifndef H5
    reject(new Error('H5 only'))
    // #endif
  })
}

async function decodeQrFromPath(filePath) {
  // #ifdef H5
  await loadScriptOnce(assetBase() + 'static/vendor/jsQR.js')
  const jsQRFn = typeof window !== 'undefined' ? window.jsQR : null
  if (!jsQRFn) throw new Error('扫码库未加载')
  const img = await new Promise((resolve, reject) => {
    const el = new Image()
    el.onload = () => resolve(el)
    el.onerror = () => reject(new Error('图片加载失败'))
    el.src = filePath
  })
  const canvas = document.createElement('canvas')
  canvas.width = img.naturalWidth || img.width
  canvas.height = img.naturalHeight || img.height
  const ctx = canvas.getContext('2d')
  ctx.drawImage(img, 0, 0)
  const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height)
  const code = jsQRFn(imageData.data, imageData.width, imageData.height)
  if (!code || !code.data) throw new Error('未识别到二维码')
  return String(code.data)
  // #endif
  // #ifndef H5
  throw new Error('请使用扫一扫')
  // #endif
}

export async function addFriendByMemberId(memberId, selfUserId) {
  const id = String(memberId || '').replace(/\D+/g, '')
  if (!/^\d{8}$/.test(id)) {
    uni.showToast({ title: '无效的会员二维码', icon: 'none' })
    return false
  }
  const self = String(selfUserId || '').replace(/\D+/g, '')
  if (self && self === id) {
    uni.showToast({ title: '不能添加自己为好友', icon: 'none' })
    return false
  }
  await imConnect()
  const packet = await friendLookup({ user_id: id })
  const data = (packet && packet.data) || {}
  if (!data.found) {
    uni.showToast({ title: '未找到该用户', icon: 'none' })
    return false
  }
  const u = data.user || {}
  const name = u.nickname || ('ID' + (u.user_id || id))
  const ok = await new Promise((resolve) => {
    uni.showModal({
      title: '确认添加',
      content: '向「' + name + '」发送好友申请？',
      success: (r) => resolve(!!r.confirm),
    })
  })
  if (!ok) return false
  await friendRequest({ user_id: u.user_id || id, message: '' })
  uni.showToast({ title: '已发送好友申请', icon: 'none' })
  return true
}

/**
 * @param {{ selfUserId?: string|number, onManual?: () => void }} [opts]
 */
export function openFriendScanSheet(opts) {
  const selfUserId = opts && opts.selfUserId
  const onManual =
    (opts && opts.onManual) ||
    (() => {
      uni.navigateTo({ url: '/pages/friend/add' })
    })

  uni.showActionSheet({
    itemList: ['扫一扫', '从相册识别', '手动输入会员ID'],
    success: async (res) => {
      const idx = res.tapIndex | 0
      if (idx === 2) {
        onManual()
        return
      }
      if (idx === 0) {
        try {
          const r = await new Promise((resolve, reject) => {
            uni.scanCode({
              onlyFromCamera: true,
              success: resolve,
              fail: reject,
            })
          })
          const id = parseFriendPayload(r && r.result)
          if (id) await addFriendByMemberId(id, selfUserId)
          else uni.showToast({ title: '无效的会员二维码', icon: 'none' })
        } catch (e) {
          uni.showToast({ title: (e && e.errMsg) || '扫码取消', icon: 'none' })
        }
        return
      }
      try {
        const pick = await new Promise((resolve, reject) => {
          uni.chooseImage({
            count: 1,
            sizeType: ['compressed'],
            sourceType: ['album'],
            success: resolve,
            fail: reject,
          })
        })
        const path = pick.tempFilePaths && pick.tempFilePaths[0]
        if (!path) return
        const raw = await decodeQrFromPath(path)
        const id = parseFriendPayload(raw)
        if (id) await addFriendByMemberId(id, selfUserId)
        else uni.showToast({ title: '无效的会员二维码', icon: 'none' })
      } catch (e) {
        uni.showToast({ title: (e && e.message) || '识别失败', icon: 'none' })
      }
    },
  })
}
