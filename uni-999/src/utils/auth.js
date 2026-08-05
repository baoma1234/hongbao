import { getApiBase, getDeviceFpKey, getLocale, getTokenKey } from './config.js'

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
          const err = new Error(payload.msg || payload.message || '请求失败')
          err.payload = payload.data || null
          err.code = payload.code
          reject(err)
          return
        }
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
  const base = getApiBase() || ''
  const token = getToken()
  const locale = getLocale()
  return new Promise((resolve, reject) => {
    uni.uploadFile({
      url: base + '/api/fanshub/avatarupload',
      filePath,
      name: 'file',
      formData: { locale },
      header: token ? { token, 'X-Fanshub-Locale': locale } : { 'X-Fanshub-Locale': locale },
      success(res) {
        try {
          const body = JSON.parse((res && res.data) || '{}')
          if ((body && body.code) !== 1) {
            reject(new Error(body.msg || body.message || '上传失败'))
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
  })
}

export function logoutLocal() {
  setToken('')
}
