/**
 * 与 FansHubMobile 手机校验/E.164 对齐（仅 uni-1889 使用）
 */
import { COUNTRY_STORAGE_KEY, LOCALE_META, getLocale } from './i18n.js'

export const LOGIN_COUNTRIES = [
  { code: 'CN', dial: '86', flagIso: 'cn', labelKey: 'country_cn', maxlen: 11, placeholderKey: 'login_phone_placeholder' },
  // maxlen 含可选国内冠号 0（如 09…）
  { code: 'PH', dial: '63', flagIso: 'ph', labelKey: 'country_ph', maxlen: 11, placeholderKey: 'login_phone_placeholder_ph' },
  { code: 'VN', dial: '84', flagIso: 'vn', labelKey: 'country_vn', maxlen: 10, placeholderKey: 'login_phone_placeholder_vn' },
  { code: 'MY', dial: '60', flagIso: 'my', labelKey: 'country_my', maxlen: 11, placeholderKey: 'login_phone_placeholder_my' },
  { code: 'KH', dial: '855', flagIso: 'kh', labelKey: 'country_kh', maxlen: 10, placeholderKey: 'login_phone_placeholder_kh' },
  { code: 'ID', dial: '62', flagIso: 'id', labelKey: 'country_id', maxlen: 13, placeholderKey: 'login_phone_placeholder_id' },
  { code: 'AE', dial: '971', flagIso: 'ae', labelKey: 'country_ae', maxlen: 10, placeholderKey: 'login_phone_placeholder_ae' },
  { code: 'TR', dial: '90', flagIso: 'tr', labelKey: 'country_tr', maxlen: 11, placeholderKey: 'login_phone_placeholder_tr' },
  { code: 'RU', dial: '7', flagIso: 'ru', labelKey: 'country_ru', maxlen: 11, placeholderKey: 'login_phone_placeholder_ru' },
  { code: 'JP', dial: '81', flagIso: 'jp', labelKey: 'country_jp', maxlen: 11, placeholderKey: 'login_phone_placeholder_jp' },
  { code: 'KR', dial: '82', flagIso: 'kr', labelKey: 'country_kr', maxlen: 11, placeholderKey: 'login_phone_placeholder_kr' },
]

const PATTERNS = {
  CN: /^1[3-9]\d{9}$/,
  PH: /^9\d{9}$/,
  KH: /^\d{8,9}$/,
  ID: /^8\d{8,11}$/,
  VN: /^[35789]\d{8}$/,
  MY: /^1\d{8,9}$/,
  AE: /^5\d{8}$/,
  TR: /^5\d{9}$/,
  RU: /^9\d{9}$/,
  JP: /^[789]0\d{8}$/,
  KR: /^10\d{7,8}$/,
}

export function getCountryMeta(code) {
  const c = String(code || 'CN').toUpperCase()
  return LOGIN_COUNTRIES.find((x) => x.code === c) || LOGIN_COUNTRIES[0]
}

export function readStoredCountry() {
  try {
    const v = uni.getStorageSync(COUNTRY_STORAGE_KEY)
    if (v && getCountryMeta(v).code === String(v).toUpperCase()) return String(v).toUpperCase()
  } catch (e) {}
  const meta = LOCALE_META[getLocale()]
  return (meta && meta.country) || 'CN'
}

export function setStoredCountry(code) {
  const c = getCountryMeta(code).code
  try {
    uni.setStorageSync(COUNTRY_STORAGE_KEY, c)
  } catch (e) {}
  return c
}

export function stripNational(raw, countryCode) {
  let digits = String(raw || '').replace(/\D/g, '')
  const c = getCountryMeta(countryCode)
  const dial = String(c.dial)
  if (digits.indexOf(dial) === 0) digits = digits.slice(dial.length)
  if (c.code === 'CN' && digits.length === 13 && digits.indexOf('86') === 0) {
    digits = digits.slice(2)
  }
  // 非中国：去掉国内拨号冠号 0（09… / 08… / 01…）
  if (c.code !== 'CN' && digits.charAt(0) === '0') {
    digits = digits.replace(/^0+/, '')
  }
  return digits
}

export function isValidNational(raw, countryCode) {
  const national = stripNational(raw, countryCode)
  const c = getCountryMeta(countryCode)
  const re = PATTERNS[c.code] || PATTERNS.CN
  return re.test(national)
}

export function toE164(raw, countryCode) {
  const national = stripNational(raw, countryCode)
  if (!national || !isValidNational(national, countryCode)) return ''
  const c = getCountryMeta(countryCode)
  return '+' + c.dial + national
}

/** i18n key：按国家给出格式说明（作校验失败提示） */
export function phoneInvalidKey(countryCode) {
  return getCountryMeta(countryCode).placeholderKey || 'alert_phone_invalid'
}

/** 从已绑定 E.164 识别国家（区号从长到短，避免 +7 误匹配 +971） */
export function detectCountryFromE164(mobile) {
  const raw = String(mobile || '').trim()
  if (!raw) return 'CN'
  const digits = raw.replace(/^\+/, '').replace(/\D/g, '')
  let best = ''
  let bestLen = -1
  LOGIN_COUNTRIES.forEach((c) => {
    const d = String(c.dial)
    if (digits.indexOf(d) === 0 && d.length > bestLen) {
      best = c.code
      bestLen = d.length
    }
  })
  return best || 'CN'
}

export function nationalFromE164(mobile) {
  const code = detectCountryFromE164(mobile)
  return stripNational(mobile, code)
}
