<template>
  <view class="chat-shell chat-friend-page">
    <TopBar />
    <view class="chat-hero-hd">
      <view class="chat-hero-back" @click="goBack">
          <text class="chat-hero-back-char">‹</text>
        </view>
      <view class="chat-hero-title">添加好友</view>
      <view class="chat-hero-spacer" />
    </view>

    <view class="chat-sub-main">
      <view class="chat-add-friend-req-link" @click="goRequests">
        <view class="chat-add-friend-req-link-ico" aria-hidden="true">
          <image class="chat-plus-menu-ico-img" :src="icoFriendReq" mode="aspectFit" />
        </view>
        <view class="chat-add-friend-req-link-body">
          <text class="chat-add-friend-req-link-title">好友申请</text>
          <text class="chat-add-friend-req-link-sub">查看收到与发出的申请</text>
        </view>
        <text v-if="pendingCount > 0" class="chat-friend-req-badge chat-add-friend-req-badge">{{ pendingCount > 99 ? '99+' : pendingCount }}</text>
        <text class="chat-add-friend-req-link-arrow">›</text>
      </view>

      <view class="chat-add-friend-card">
        <text class="chat-setting-label">对方手机号</text>
        <view class="chat-add-friend-phone-row">
          <view class="chat-add-friend-country country-select" @click="countryOpen = !countryOpen">
            <image class="flag" :src="flagUrl(countryMeta.flagIso)" mode="aspectFill" />
            <text class="dial">+{{ countryMeta.dial }}</text>
            <text class="caret">▾</text>
          </view>
          <input
            class="chat-setting-input chat-add-friend-mobile"
            type="number"
            :maxlength="countryMeta.maxlen"
            v-model="mobile"
            :placeholder="phonePlaceholder"
            @focus="countryOpen = false"
          />
        </view>
        <view v-if="countryOpen" class="country-panel chat-add-friend-country-panel">
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
        <view class="chat-add-friend-or">或</view>
        <text class="chat-setting-label">对方会员ID</text>
        <input
          class="chat-setting-input chat-add-friend-userid"
          type="number"
          maxlength="8"
          v-model="memberId"
          placeholder="请输入8位数字会员ID"
        />
        <text class="chat-add-friend-hint">仅支持手机号或8位会员ID精确查找，二选一；对方通过后才能发消息</text>
        <button class="chat-setting-save-btn" :disabled="busy" @click="submit">
          {{ busy ? '提交中…' : '查找并申请' }}
        </button>
      </view>
    </view>
  </view>
</template>

<script setup>
import { safeNavigateBack, HOME_TAB } from '../../utils/nav.js'
import { computed, ref } from 'vue'
import { onLoad, onShow } from '@dcloudio/uni-app'
import TopBar from '../../components/TopBar.vue'
import { getToken } from '../../utils/auth.js'
import { packagedStaticUrl } from '../../utils/config.js'
import { flagUrl, t } from '../../utils/i18n.js'
import {
  LOGIN_COUNTRIES,
  getCountryMeta,
  isValidNational,
  readStoredCountry,
  setStoredCountry,
  stripNational,
} from '../../utils/login-country.js'
import { friendLookup, friendRequest, friendRequests, imConnect } from '../../utils/im.js'
import '../../styles/chat.bundle.css'
import '../../styles/chat-uni-adapter.css'
import '../../styles/friend-uni-adapter.css'

const icoFriendReq = packagedStaticUrl('chat/plus_friend_req.png')
const countries = LOGIN_COUNTRIES
const country = ref(readStoredCountry())
const countryOpen = ref(false)
const mobile = ref('')
const memberId = ref('')
const busy = ref(false)
const pendingCount = ref(0)

const countryMeta = computed(() => getCountryMeta(country.value))
const phonePlaceholder = computed(() => t(countryMeta.value.placeholderKey) || '请输入手机号')

function pickCountry(code) {
  country.value = setStoredCountry(code)
  countryOpen.value = false
  mobile.value = ''
}

function goBack() {
  safeNavigateBack(HOME_TAB)
}

function goRequests() {
  uni.navigateTo({ url: '/pages/friend/requests' })
}

function parseQuery() {
  const id = String(memberId.value || '').replace(/\D+/g, '')
  if (id.length === 8) return { mode: 'id', user_id: Number(id) }
  const national = stripNational(mobile.value, country.value)
  const dial = String(getCountryMeta(country.value).dial || '86')
  if (national && isValidNational(national, country.value)) {
    return { mode: 'mobile', mobile: national, country_dial: dial }
  }
  if (national && national.length >= 6) {
    return { mode: 'mobile', mobile: national, country_dial: dial }
  }
  if (id) return { error: '会员ID须为8位数字' }
  return { error: '请填写手机号或8位会员ID' }
}

async function submit() {
  countryOpen.value = false
  const q = parseQuery()
  if (q.error) {
    uni.showToast({ title: q.error, icon: 'none' })
    return
  }
  busy.value = true
  try {
    await imConnect()
    const lookupPayload = q.mode === 'id' ? { user_id: q.user_id } : { mobile: q.mobile, country_dial: q.country_dial }
    const packet = await friendLookup(lookupPayload)
    const data = (packet && packet.data) || {}
    if (!data.found) {
      uni.showToast({ title: '未找到该用户', icon: 'none' })
      return
    }
    const u = data.user || {}
    const name = u.nickname || ('ID' + (u.user_id || ''))
    const ok = await new Promise((resolve) => {
      uni.showModal({
        title: '确认添加',
        content: '向「' + name + '」发送好友申请？',
        success: (r) => resolve(!!r.confirm),
        fail: () => resolve(false),
      })
    })
    if (!ok) return
    const reqPayload = q.mode === 'id' ? { user_id: q.user_id } : { mobile: q.mobile, country_dial: q.country_dial }
    const packet2 = await friendRequest(reqPayload)
    const d2 = (packet2 && packet2.data) || {}
    if (d2.auto_accepted || d2.status === 'accepted' || d2.status === 'already_friends') {
      uni.showToast({ title: '已是好友', icon: 'none' })
      const peer = d2.peer_user_id | 0
      const cid = d2.conversation_id || ''
      setTimeout(() => {
        uni.navigateTo({
          url:
            '/pages/chat/chat?type=1&id=' +
            encodeURIComponent(cid) +
            '&peer=' +
            encodeURIComponent(peer) +
            '&title=' +
            encodeURIComponent(name),
        })
      }, 300)
    } else {
      uni.showToast({ title: '申请已发送', icon: 'none' })
      setTimeout(() => uni.navigateTo({ url: '/pages/friend/requests?tab=outgoing' }), 300)
    }
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '添加失败', icon: 'none' })
  } finally {
    busy.value = false
  }
}

onLoad(() => {
  if (!getToken()) uni.reLaunch({ url: '/pages/login/login' })
})

onShow(async () => {
  if (!getToken()) return
  try {
    await imConnect()
    const packet = await friendRequests()
    const data = (packet && packet.data) || packet || {}
    pendingCount.value = data.pending_count | 0
  } catch (e) {
    pendingCount.value = 0
  }
})
</script>
