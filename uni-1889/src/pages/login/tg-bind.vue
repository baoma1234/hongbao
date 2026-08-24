<template>
  <view class="login-page tg-bind-page">
    <TopBar />
    <view class="login-wrapper">
      <view class="login-brand">
        <image class="login-logo-img" :src="logo" mode="aspectFit" />
        <view class="login-logo">{{ t('brand_name') }}</view>
      </view>
      <view class="login-subtitle">
        <text class="login-subtitle-line">{{ statusHint }}</text>
      </view>

      <view v-if="booting" class="tg-boot">
        <text>{{ bootText }}</text>
      </view>

      <view v-else-if="!needBind" class="tg-boot tg-boot-error">
        <text>{{ bootText || '请从 Telegram 机器人进入' }}</text>
      </view>

      <template v-else>
        <view v-if="bootText" class="tg-bind-warn">
          <text>{{ bootText }}</text>
        </view>
        <view class="input-group">
          <view class="input-label">{{ t('login_phone_label') }}</view>
          <view class="phone-row">
            <view class="country-select" @click="countryOpen = !countryOpen">
              <image class="flag" :src="flagUrl(countryMeta.flagIso)" mode="aspectFill" />
              <text class="dial">+{{ countryMeta.dial }}</text>
              <text class="caret">▾</text>
            </view>
            <input
              class="login-input phone-input"
              type="number"
              :maxlength="countryMeta.maxlen"
              v-model="mobile"
              :placeholder="phonePlaceholder"
            />
          </view>
          <view v-if="countryOpen" class="country-panel">
            <view
              v-for="c in countries"
              :key="c.code"
              class="country-item"
              :class="{ on: c.code === country }"
              @click="pickCountry(c.code)"
            >
              <image class="flag" :src="flagUrl(c.flagIso)" mode="aspectFill" />
              <text class="cname">{{ t(c.labelKey) || c.code }}</text>
              <text class="cdial">+{{ c.dial }}</text>
            </view>
          </view>
        </view>

        <view class="input-group captcha-group">
          <view class="input-label">{{ t('login_captcha_label') }}</view>
          <input
            class="login-input captcha-input"
            type="number"
            maxlength="6"
            v-model="captcha"
            :placeholder="t('login_captcha_placeholder')"
          />
          <button
            class="captcha-btn"
            :class="{ disabled: smsLeft > 0 || sending }"
            :disabled="smsLeft > 0 || sending"
            @click="onSendSms"
          >
            {{ smsBtnText }}
          </button>
        </view>

        <button class="btn-login-submit" :loading="loading" @click="onBind">
          {{ bindBtnText }}
        </button>
      </template>
    </view>
  </view>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import TopBar from '../../components/TopBar.vue'
import { apiRequest, getDeviceFp, getToken, setToken } from '../../utils/auth.js'
import {
  flagUrl,
  localeState,
  logoUrl,
  t,
} from '../../utils/i18n.js'
import { imConnect } from '../../utils/im.js'
import { syncRegistrationAfterLogin } from '../../utils/jpush.js'
import {
  LOGIN_COUNTRIES,
  getCountryMeta,
  isValidNational,
  phoneInvalidKey,
  readStoredCountry,
  setStoredCountry,
  toE164,
} from '../../utils/login-country.js'
import { hydrateInviteCode, saveInviteCode } from '../../utils/openinstall.js'

const logo = logoUrl()
const locale = localeState()
const countries = LOGIN_COUNTRIES
const country = ref(readStoredCountry())
const countryOpen = ref(false)
const mobile = ref('')
const captcha = ref('')
const inviteCode = ref('')
const loading = ref(false)
const sending = ref(false)
const smsLeft = ref(0)
const smsInterval = ref(60)
const booting = ref(true)
const needBind = ref(false)
const bootText = ref('正在连接 Telegram…')
const initData = ref('')
const tgName = ref('')
let timer = null

const countryMeta = computed(() => getCountryMeta(country.value))
const phonePlaceholder = computed(() => {
  void locale.value
  return t(countryMeta.value.placeholderKey) || t('login_phone_placeholder')
})
const smsBtnText = computed(() => {
  void locale.value
  if (smsLeft.value > 0) {
    return t('login_captcha_resend', { count: smsLeft.value }) || smsLeft.value + 's'
  }
  return t('login_captcha_btn')
})
const bindBtnText = computed(() => {
  void locale.value
  return '绑定手机号并进入'
})
const statusHint = computed(() => {
  if (tgName.value) return 'Telegram：' + tgName.value
  return 'Telegram 内打开 · 绑定手机号登录'
})

function pickCountry(code) {
  country.value = code
  setStoredCountry(code)
  countryOpen.value = false
}

function startCooldown(sec) {
  const n = Math.max(1, parseInt(String(sec || smsInterval.value), 10) || 60)
  smsLeft.value = n
  if (timer) clearInterval(timer)
  timer = setInterval(() => {
    smsLeft.value -= 1
    if (smsLeft.value <= 0) {
      smsLeft.value = 0
      clearInterval(timer)
      timer = null
    }
  }, 1000)
}

function readStoredTgInitData() {
  // #ifdef H5
  try {
    if (typeof sessionStorage === 'undefined') return ''
    return String(sessionStorage.getItem('__tg_initData') || sessionStorage.getItem('tgWebAppData') || '')
  } catch (e) {
    return ''
  }
  // #endif
  // #ifndef H5
  return ''
  // #endif
}

function parseInitDataFromLocation() {
  // #ifdef H5
  try {
    const out = {}
    const eat = (str) => {
      String(str || '')
        .replace(/^[#?]/, '')
        .split('&')
        .forEach((pair) => {
          const i = pair.indexOf('=')
          if (i < 0) return
          let k = pair.slice(0, i)
          let v = pair.slice(i + 1)
          try {
            k = decodeURIComponent(k)
          } catch (e) {}
          // tgWebAppData 只解一次；内部仍是 querystring
          try {
            v = decodeURIComponent(String(v).replace(/\+/g, '%20'))
          } catch (e) {}
          out[k] = v
        })
    }
    if (typeof location !== 'undefined') {
      eat(location.search)
      // hash 形如 #/pages/x 时不要当参数；仅当含 tgWebAppData= 才解析
      const h = String(location.hash || '')
      if (h.indexOf('tgWebAppData=') >= 0) eat(h)
    }
    return String(out.tgWebAppData || '')
  } catch (e) {
    return ''
  }
  // #endif
  // #ifndef H5
  return ''
  // #endif
}

function resolveInitData(tg) {
  const fromTg = tg && tg.initData ? String(tg.initData) : ''
  if (fromTg) return fromTg
  const stored = readStoredTgInitData()
  if (stored) return stored
  return parseInitDataFromLocation()
}

function loadTelegramWebApp() {
  return new Promise((resolve) => {
    // #ifdef H5
    try {
      if (typeof window !== 'undefined' && window.Telegram && window.Telegram.WebApp) {
        resolve(window.Telegram.WebApp)
        return
      }
      const finish = () => {
        resolve((typeof window !== 'undefined' && window.Telegram && window.Telegram.WebApp) || null)
      }
      const exist = document.querySelector('script[data-tg-webapp]')
      if (exist) {
        if (window.Telegram && window.Telegram.WebApp) {
          finish()
          return
        }
        exist.addEventListener('load', finish)
        exist.addEventListener('error', finish)
        setTimeout(finish, 2500)
        return
      }
      // 优先本站静态文件（telegram.org 在国内常不可达）
      const localSrc = ((typeof location !== 'undefined' && location.pathname.indexOf('/1889') === 0)
        ? '/1889'
        : '') + '/static/vendor/telegram-web-app.js'
      const tryUrls = [localSrc, 'https://telegram.org/js/telegram-web-app.js']
      let idx = 0
      const loadNext = () => {
        if (idx >= tryUrls.length) {
          finish()
          return
        }
        const s = document.createElement('script')
        s.src = tryUrls[idx++]
        s.async = true
        s.setAttribute('data-tg-webapp', '1')
        s.onload = finish
        s.onerror = loadNext
        document.head.appendChild(s)
      }
      loadNext()
      setTimeout(finish, 5000)
    } catch (e) {
      resolve(null)
    }
    // #endif
    // #ifndef H5
    resolve(null)
    // #endif
  })
}

/** Telegram 偶发 initData 稍晚才注入，短轮询几次 */
function waitForInitData(tg, rounds = 12, gapMs = 150) {
  return new Promise((resolve) => {
    let n = 0
    const tick = () => {
      const raw = resolveInitData(tg)
      if (raw) {
        resolve(raw)
        return
      }
      n += 1
      if (n >= rounds) {
        resolve('')
        return
      }
      setTimeout(tick, gapMs)
    }
    tick()
  })
}

async function enterWithToken(data) {
  if (data && data.token) setToken(data.token)
  try {
    if (typeof sessionStorage !== 'undefined') {
      sessionStorage.removeItem('__tg_bind')
      sessionStorage.removeItem('__tg_initData')
    }
  } catch (e) {}
  try {
    await imConnect()
    syncRegistrationAfterLogin()
  } catch (e) {}
  uni.showToast({ title: '登录成功', icon: 'none' })
  setTimeout(() => {
    uni.reLaunch({ url: '/pages/home/home' })
  }, 300)
}

async function runAuth() {
  booting.value = true
  needBind.value = false
  bootText.value = '正在连接 Telegram…'
  const tg = await loadTelegramWebApp()
  if (tg) {
    try {
      tg.ready()
      if (typeof tg.expand === 'function') tg.expand()
    } catch (e) {}
    bootText.value = '正在验证 Telegram…'
    initData.value = await waitForInitData(tg)
    const u = tg.initDataUnsafe && tg.initDataUnsafe.user
    if (u) {
      tgName.value = [u.first_name, u.last_name].filter(Boolean).join(' ')
        || (u.username ? '@' + u.username : '')
    }
    const sp = (tg.initDataUnsafe && tg.initDataUnsafe.start_param) || ''
    if (sp && !inviteCode.value) {
      inviteCode.value = String(sp)
      saveInviteCode(String(sp))
    }
  } else {
    initData.value = ''
  }
  // 非 TG WebApp（无 initData）：提示错误，不要静默空白
  if (!initData.value) {
    if (getToken()) {
      bootText.value = '已登录，正在进入…'
      uni.reLaunch({ url: '/pages/home/home' })
      return
    }
    booting.value = false
    needBind.value = false
    bootText.value = '请从 Telegram 机器人「进入游戏」打开本页（浏览器直接打开无法绑定）'
    uni.showToast({ title: '请从 Telegram 机器人进入', icon: 'none' })
    return
  }
  try {
    const data = await apiRequest('tgauth', 'POST', { init_data: initData.value })
    if (data && data.token && data.bound !== false) {
      await enterWithToken(data)
      return
    }
    needBind.value = true
    booting.value = false
    bootText.value = ''
    if (data && data.tg && data.tg.start_param && !inviteCode.value) {
      inviteCode.value = String(data.tg.start_param)
      saveInviteCode(inviteCode.value)
    }
  } catch (e) {
    booting.value = false
    // 有 initData 仍展示绑定表单，方便重试短信绑定
    needBind.value = true
    bootText.value = (e && e.message) || 'Telegram 验证失败，请填写手机号绑定'
    uni.showToast({ title: (e && e.message) || 'Telegram 验证失败', icon: 'none' })
  }
}

async function onSendSms() {
  if (sending.value || smsLeft.value > 0) return
  const phone = toE164(mobile.value, country.value)
  if (!String(mobile.value || '').trim()) {
    uni.showToast({ title: t('alert_phone_required') || '请输入手机号', icon: 'none' })
    return
  }
  if (!phone || !isValidNational(mobile.value, country.value)) {
    uni.showToast({
      title: t(phoneInvalidKey(country.value)) || t('alert_phone_invalid') || '手机号不正确',
      icon: 'none',
    })
    return
  }
  sending.value = true
  try {
    const data = await apiRequest('tgsendsms', 'POST', {
      init_data: initData.value,
      mobile: phone,
      country_code: country.value,
    })
    const wait = (data && data.retry_after) || smsInterval.value
    startCooldown(wait)
    if (data && data.mock && data.mock_code) {
      captcha.value = String(data.mock_code)
      uni.showToast({ title: data.hint || '测试验证码已填入', icon: 'none' })
    } else {
      uni.showToast({ title: t('alert_sms_sent') || '验证码已发送', icon: 'none' })
    }
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '发送失败', icon: 'none' })
  } finally {
    sending.value = false
  }
}

async function onBind() {
  if (loading.value) return
  const phone = toE164(mobile.value, country.value)
  const code = String(captcha.value || '').trim()
  if (!phone || !isValidNational(mobile.value, country.value)) {
    uni.showToast({
      title: t(phoneInvalidKey(country.value)) || t('alert_phone_invalid') || '手机号不正确',
      icon: 'none',
    })
    return
  }
  if (!code) {
    uni.showToast({ title: t('alert_captcha_required') || '请输入验证码', icon: 'none' })
    return
  }
  loading.value = true
  try {
    const data = await apiRequest('tgbind', 'POST', {
      init_data: initData.value,
      mobile: phone,
      captcha: code,
      code: String(inviteCode.value || '').trim(),
      invite: String(inviteCode.value || '').trim(),
      country_code: country.value,
      device_fp: getDeviceFp(),
    })
    await enterWithToken(data)
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '绑定失败', icon: 'none' })
  } finally {
    loading.value = false
  }
}

onLoad((q) => {
  const code = hydrateInviteCode(q || {})
  if (code) inviteCode.value = code
  // URL ?tg_start= / ?code=
  try {
    if (typeof location !== 'undefined') {
      const sp = new URLSearchParams(location.search || '')
      const ts = sp.get('tg_start') || sp.get('code') || ''
      if (ts && !inviteCode.value) {
        inviteCode.value = ts
        saveInviteCode(ts)
      }
    }
  } catch (e) {}
})

onMounted(() => {
  runAuth()
})

onUnmounted(() => {
  if (timer) clearInterval(timer)
})
</script>

<style scoped>
.login-page {
  min-height: 100vh;
  min-height: 100dvh;
  background: var(--bg-main, #f5f7fa);
  box-sizing: border-box;
}
.login-wrapper {
  padding: 24px 20px 40px;
  max-width: 480px;
  margin: 0 auto;
}
.login-brand {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
}
.login-logo-img {
  width: 72px;
  height: 72px;
  border-radius: 16px;
}
.login-logo {
  font-size: 22px;
  font-weight: 800;
  color: #1a1a1a;
}
.login-subtitle {
  text-align: center;
  margin-bottom: 20px;
}
.login-subtitle-line {
  display: block;
  font-size: 13px;
  color: #666;
  line-height: 1.5;
}
.tg-boot {
  text-align: center;
  padding: 40px 12px;
  color: #888;
  font-size: 14px;
}
.tg-boot-error {
  color: #c62828;
  line-height: 1.6;
}
.tg-bind-warn {
  margin-bottom: 12px;
  padding: 10px 12px;
  border-radius: 10px;
  background: #fff7ed;
  color: #9a3412;
  font-size: 13px;
  line-height: 1.45;
}
.input-group {
  margin-bottom: 16px;
}
.input-label {
  font-size: 13px;
  color: #555;
  margin-bottom: 8px;
}
.phone-row {
  display: flex;
  gap: 8px;
  align-items: center;
}
.country-select {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 0 10px;
  height: 44px;
  border-radius: 10px;
  background: #fff;
  border: 1px solid #e5e7eb;
  flex-shrink: 0;
}
.flag {
  width: 20px;
  height: 14px;
  border-radius: 2px;
}
.dial {
  font-size: 14px;
  font-weight: 600;
}
.caret {
  font-size: 10px;
  color: #999;
}
.login-input {
  flex: 1;
  height: 44px;
  padding: 0 12px;
  border-radius: 10px;
  background: #fff;
  border: 1px solid #e5e7eb;
  font-size: 16px;
  box-sizing: border-box;
}
.phone-input {
  min-width: 0;
}
.country-panel {
  margin-top: 8px;
  background: #fff;
  border-radius: 12px;
  border: 1px solid #e5e7eb;
  max-height: 220px;
  overflow-y: auto;
}
.country-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 12px;
}
.country-item.on {
  background: #fff7ed;
}
.cname {
  flex: 1;
  font-size: 14px;
}
.cdial {
  font-size: 13px;
  color: #888;
}
.captcha-group {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 8px;
}
.captcha-group .input-label {
  width: 100%;
}
.captcha-input {
  flex: 1;
  min-width: 120px;
}
.captcha-btn {
  height: 44px;
  line-height: 44px;
  padding: 0 14px;
  border-radius: 10px;
  background: #ff6a3d;
  color: #fff;
  font-size: 13px;
  border: none;
  flex-shrink: 0;
}
.captcha-btn.disabled {
  opacity: 0.55;
}
.btn-login-submit {
  margin-top: 12px;
  height: 48px;
  line-height: 48px;
  border-radius: 12px;
  background: linear-gradient(90deg, #ff7a45, #ff4d4f);
  color: #fff;
  font-size: 16px;
  font-weight: 700;
  border: none;
}
</style>
