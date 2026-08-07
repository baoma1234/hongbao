import { getApiBase, getDeviceFpKey, getImgBase, getLocale, getTokenKey, learnUploadCdnFromUrl, setUploadCdn } from './config.js'

export function getToken() {
  return uni.getStorageSync(getTokenKey()) || ''
}

export function setToken(token) {
  if (token) uni.setStorageSync(getTokenKey(), String(token))
  else uni.removeStorageSync(getTokenKey())
}

export function getDeviceFp() {
  let fp = uni.getStorageSync(getDeviceFpKey()) || ''
  if (!fp) {
    fp = 'u999_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 10)
    uni.setStorageSync(getDeviceFpKey(), fp)
  }
  return fp
}

export function logoutLocal() {
  setToken('')
}

let loginRedirectAt = 0

/** 未登录 / token 失效：清本地态并跳转登录页 */
export function goLoginIfUnauthorized(code, msg) {
  const c = Number(code)
  const m = String(msg || '')
  const need =
    c === 401 ||
    /请登录/.test(m) ||
    /未登录/.test(m) ||
    /Please login/i.test(m) ||
    /not\s*login/i.test(m)
  if (!need) return false
  logoutLocal()
  const now = Date.now()
  if (now - loginRedirectAt < 1500) return true
  loginRedirectAt = now
  try {
    const pages = typeof getCurrentPages === 'function' ? getCurrentPages() : []
    const cur = pages && pages.length ? pages[pages.length - 1] : null
    const route = String((cur && (cur.route || (cur.$page && cur.$page.fullPath))) || '')
    if (route.indexOf('pages/login/login') >= 0) return true
  } catch (e) {}
  uni.reLaunch({ url: '/pages/login/login' })
  return true
}

function rejectApiError(payload, reject) {
  const msg = (payload && (payload.msg || payload.message)) || '请求失败'
  const code = payload && payload.code
  goLoginIfUnauthorized(code, msg)
  const err = new Error(msg)
  err.payload = (payload && payload.data) || null
  err.code = code
  reject(err)
}

/**
 * ThinkPHP FansHub API：成功 code===1，返回 data
 */
export function apiRequest(action, method = 'POST', body = null) {
  const httpMethod = String(method || 'POST').toUpperCase()
  const token = getToken()
  const locale = getLocale()
  let url = getApiBase() + '/api/fanshub/' + action
  const headers = {
    'Content-Type': 'application/json',
    'X-Fanshub-Locale': locale,
  }
  if (token) headers.token = token

  let data = null
  if (httpMethod === 'GET') {
    const qs = new URLSearchParams(Object.assign({}, body || {}, { locale })).toString()
    url += (url.indexOf('?') >= 0 ? '&' : '?') + qs
  } else {
    data = Object.assign({}, body || {}, { locale })
  }

  return new Promise((resolve, reject) => {
    uni.request({
      url,
      method: httpMethod,
      header: headers,
      data: data || undefined,
      success(res) {
        const payload = res.data || {}
        if (payload.code !== 1) {
          rejectApiError(payload, reject)
          return
        }
        try {
          // config 接口：data.upload_cdn；bootstrap：data.config.upload_cdn
          const d = payload.data
          const cdn =
            (d && d.upload_cdn) ||
            (d && d.config && d.config.upload_cdn) ||
            ''
          if (cdn) setUploadCdn(cdn)
          // 任意接口若带回 OSS 绝对地址，顺便记下 CDN
          if (d && typeof d === 'object') {
            learnUploadCdnFromUrl(d.fullurl || d.avatar_url || '')
            if (d.profile) learnUploadCdnFromUrl(d.profile.avatar_url || d.profile.fullurl || '')
          }
        } catch (e) {}
        resolve(payload.data)
      },
      fail(err) {
        reject(new Error((err && err.errMsg) || '网络错误'))
      },
    })
  })
}

export async function fetchConfig() {
  try {
    return await apiRequest('config', 'GET')
  } catch (e) {
    return null
  }
}

export async function sendSms(mobile, extra = {}) {
  const body = Object.assign(
    {
      mobile,
      country_code: (extra && extra.country_code) || 'CN',
    },
    extra || {}
  )
  return apiRequest('sendsms', 'POST', body)
}

export async function login(mobile, captcha, inviteCode = '', extra = {}) {
  const invite = String(inviteCode || '').trim()
  const data = await apiRequest('login', 'POST', {
    mobile,
    captcha,
    code: invite,
    invite,
    country_code: (extra && extra.country_code) || 'CN',
    device_fp: getDeviceFp(),
  })
  if (data && data.token) setToken(data.token)
  return data
}

export async function fetchProfile() {
  return apiRequest('profile', 'GET')
}

export async function updateProfile(nickname) {
  return apiRequest('updateprofile', 'POST', { nickname })
}

export async function changePassword(body) {
  return apiRequest('changepassword', 'POST', body || {})
}

export async function setPayPassword(payPassword, confirmPassword) {
  return apiRequest('setpaypassword', 'POST', {
    pay_password: payPassword,
    confirm_password: confirmPassword,
  })
}

export async function changePayPassword(payPassword, confirmPassword, captcha) {
  return apiRequest('changepaypassword', 'POST', {
    pay_password: payPassword,
    confirm_password: confirmPassword,
    captcha,
  })
}

export async function logoutRemote() {
  try {
    await apiRequest('logout', 'POST', {})
  } catch (e) {
    /* 本地仍清登录态 */
  }
}

/** 头像上传：FansHub avatarupload */
export function uploadAvatar(filePath) {
  return uploadFanshubAction('avatarupload', filePath)
}

/** 自定义表情包上传：FansHub stickerupload */
export function uploadSticker(filePath) {
  return uploadFanshubAction('stickerupload', filePath)
}

function uploadFanshubAction(action, filePath) {
  // 上传必须打 API 站；imgUri 可能是 CDN/OSS，没有 /api
  const base = getApiBase() || getImgBase() || ''
  const token = getToken()
  const locale = getLocale()
  return new Promise((resolve, reject) => {
    uni.uploadFile({
      url: base + '/api/fanshub/' + action,
      filePath,
      name: 'file',
      formData: { locale },
      header: token ? { token, 'X-Fanshub-Locale': locale } : { 'X-Fanshub-Locale': locale },
      success(res) {
        try {
          const body = JSON.parse((res && res.data) || '{}')
          if ((body && body.code) !== 1) {
            const msg = body.msg || body.message || '上传失败'
            goLoginIfUnauthorized(body.code, msg)
            reject(new Error(msg))
            return
          }
          resolve(body.data || {})
        } catch (e) {
          reject(new Error('上传失败'))
        }
      },
      fail(err) {
        reject(new Error((err && err.errMsg) || '上传失败'))
      },
    })
  }).then((data) => {
    if (data) learnUploadCdnFromUrl(data.fullurl || data.avatar_url || '')
    return data
  })
}

/** 通用文件上传（聊天图/群头像等）：/api/common/upload */
export function uploadCommonFile(filePath) {
  // 上传必须打 API 站；imgUri 可能是 CDN/OSS，没有 /api
  const base = getApiBase() || getImgBase() || ''
  const token = getToken()
  return new Promise((resolve, reject) => {
    uni.uploadFile({
      url: base + '/api/common/upload',
      filePath,
      name: 'file',
      header: token ? { token } : {},
      success(res) {
        try {
          const body = JSON.parse((res && res.data) || '{}')
          if ((body && body.code) !== 1) {
            const msg = body.msg || body.message || '上传失败'
            goLoginIfUnauthorized(body.code, msg)
            reject(new Error(msg))
            return
          }
          resolve(body.data || {})
        } catch (e) {
          reject(new Error('上传失败'))
        }
      },
      fail(err) {
        reject(new Error((err && err.errMsg) || '上传失败'))
      },
    })
  }).then((data) => {
    if (data) learnUploadCdnFromUrl(data.fullurl || '')
    return data
  })
}
