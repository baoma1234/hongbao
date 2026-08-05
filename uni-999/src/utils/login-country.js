/**
 * 与 /888 FANSHUB_COUNTRIES + FanshubI18n 手机校验/E.164 对齐（仅 uni-999 使用）
 */
import { COUNTRY_STORAGE_KEY, LOCALE_META, getLocale } from './i18n.js'

export const LOGIN_COUNTRIES = [
  { code: 'CN', dial: '86', flagIso: 'cn', labelKey: 'country_cn', maxlen: 11, placeholderKey: 'login_phone_placeholder' },
  { code: 'PH', dial: '63', flagIso: 'ph', labelKey: 'country_ph', maxlen: 10, placeholderKey: 'login_phone_placeholder_ph' },
  { code: 'VN', dial: '84', flagIso: 'vn', labelKey: 'country_vn', maxlen: 10, placeholderKey: 'login_phone_placeholder_vn' },
  { code: 'MY', dial: '60', flagIso: 'my', labelKey: 'country_my', maxlen: 10, placeholderKey: 'login_phone_placeholder_my' },
  { code: 'KH', dial: '855', flagIso: 'kh', labelKey: 'country_kh', maxlen: 9, placeholderKey: 'login_phone_placeholder_kh' },
  { code: 'ID', dial: '62', flagIso: 'id', labelKey: 'country_id', maxlen: 12, placeholderKey: 'login_phone_placeholder_id' },
]

const PATTERNS = {
  CN: /^1[3-9]\d{9}$/,
  PH: /^9\d{9}$/,
  KH: /^\d{8,9}$/,
  ID: /^8\d{8,11}$/,
  VN: /^[35789]\d{8}$/,
  MY: /^1\d{8,9}$/,
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
