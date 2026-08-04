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
  return apiRequest('sendsms', 'POST', Object.assign({ mobile, country_code: 'CN' }, extra))
}

export async function login(mobile, captcha, inviteCode = '') {
  const data = await apiRequest('login', 'POST', {
    mobile,
    captcha,
    code: inviteCode || '',
    device_fp: getDeviceFp(),
  })
  if (data && data.token) setToken(data.token)
  return data
}

export async function fetchProfile() {
  return apiRequest('profile', 'GET')
}

export function logoutLocal() {
  setToken('')
}
