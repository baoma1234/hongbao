<template>
  <view class="page">
    <view class="nav">
      <view class="nav-ico" @click="goBack">
        <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
          <path fill="currentColor" d="M15.41 16.59L10.83 12l4.58-4.59L14 6l-6 6 6 6z" />
        </svg>
      </view>
      <text class="nav-title">添加好友</text>
      <view class="nav-ico" @click="goRequests">
        <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
          <path fill="currentColor" d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8V22h19.2v-2.8c0-3.2-6.4-4.8-9.6-4.8z" />
        </svg>
      </view>
    </view>

    <view class="card link" @click="goRequests">
      <view>
        <text class="link-title">好友申请</text>
        <text class="link-sub">查看收到与发出的申请</text>
      </view>
      <text class="chev">›</text>
    </view>

    <view class="card">
      <text class="lab">对方手机号</text>
      <view class="row-phone">
        <picker :range="dials" range-key="label" @change="onDial">
          <view class="dial">+{{ dial }}</view>
        </picker>
        <input class="input" type="number" v-model="mobile" placeholder="请输入手机号" />
      </view>
      <text class="or">或</text>
      <text class="lab">对方会员ID</text>
      <input class="input" type="number" maxlength="8" v-model="memberId" placeholder="请输入8位数字会员ID" />
      <text class="hint">仅支持手机号或8位会员ID精确查找，二选一</text>
      <button class="submit" :disabled="busy" @click="submit">{{ busy ? '提交中…' : '查找并申请' }}</button>
    </view>
  </view>
</template>

<script setup>
import { ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { getToken } from '../../utils/auth.js'
import { friendLookup, friendRequest, imConnect } from '../../utils/im.js'

const dials = [
  { label: '+86 中国', v: '86' },
  { label: '+855 柬埔寨', v: '855' },
  { label: '+84 越南', v: '84' },
  { label: '+63 菲律宾', v: '63' },
  { label: '+62 印尼', v: '62' },
  { label: '+60 马来', v: '60' },
]
const dial = ref('86')
const mobile = ref('')
const memberId = ref('')
const busy = ref(false)

function onDial(e) {
  const i = Number(e.detail.value || 0)
  dial.value = dials[i] ? dials[i].v : '86'
}

function goBack() {
  uni.navigateBack({ fail: () => uni.switchTab({ url: '/pages/messages/messages' }) })
}

function goRequests() {
  uni.navigateTo({ url: '/pages/friend/requests' })
}

function parseQuery() {
  let m = String(mobile.value || '').replace(/\D+/g, '')
  const dList = ['855', '86', '84', '63', '62', '60']
  let d = String(dial.value || '').replace(/\D+/g, '')
  if (m.length >= 10) {
    for (let i = 0; i < dList.length; i++) {
      const x = dList[i]
      if (m.indexOf(x) === 0 && m.length > x.length + 6) {
        d = x
        m = m.slice(x.length)
        break
      }
    }
  }
  const id = String(memberId.value || '').replace(/\D+/g, '')
  const hasId = /^\d{8}$/.test(id)
  const hasMobile = m.length >= 6 && m.length <= 15
  if (hasId && hasMobile) return { error: '请只填写手机号或会员ID其中一项' }
  if (hasId) return { mode: 'id', user_id: id }
  if (hasMobile) return { mode: 'mobile', mobile: m, country_dial: d }
  if (id) return { error: '会员ID须为8位数字' }
  return { error: '请输入手机号或8位会员ID' }
}

async function submit() {
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
</script>

<style scoped>
.page { min-height: 100vh; background: #f6f1ea; }
.nav {
  display: flex; align-items: center; gap: 12rpx;
  padding: 18rpx 20rpx; background: linear-gradient(to right, #e63022, #c61114); color: #fff;
}
.nav-ico {
  width: 52rpx; height: 52rpx; border-radius: 12rpx;
  display: flex; align-items: center; justify-content: center;
}
.nav-title { flex: 1; font-size: 32rpx; font-weight: 800; }
.card {
  margin: 20rpx 24rpx; background: #fff; border-radius: 16rpx; padding: 24rpx;
  box-shadow: 0 4rpx 14rpx rgba(40, 20, 10, 0.05);
}
.card.link {
  display: flex; justify-content: space-between; align-items: center;
}
.link-title { display: block; font-size: 28rpx; font-weight: 800; color: #2a1f18; }
.link-sub { display: block; margin-top: 6rpx; font-size: 22rpx; color: #9a8574; }
.chev { font-size: 36rpx; color: #b8aaa0; }
.lab { display: block; font-size: 24rpx; color: #8a7a6e; font-weight: 700; margin-bottom: 10rpx; }
.row-phone { display: flex; gap: 12rpx; align-items: center; }
.dial {
  min-width: 120rpx; text-align: center; padding: 16rpx 12rpx;
  background: #fff8f0; border: 1.5px solid #f0b04a; border-radius: 12rpx; color: #8a4f1f; font-weight: 700;
}
.input {
  flex: 1; width: 100%; box-sizing: border-box; background: #fff;
  border: 1.5px solid #f0b04a; border-radius: 12rpx; padding: 16rpx 20rpx; font-size: 28rpx;
}
.or { display: block; text-align: center; color: #9a8574; margin: 20rpx 0; font-size: 22rpx; }
.hint { display: block; margin-top: 14rpx; color: #9a8574; font-size: 22rpx; line-height: 1.4; }
.submit {
  margin-top: 24rpx; background: linear-gradient(#fff, #fff) padding-box,
    linear-gradient(145deg, #ffe9b0, #f0b04a, #e07a22) border-box;
  border: 1.5px solid transparent; color: #3d2e22; font-weight: 800; border-radius: 14rpx; padding: 18rpx;
}
</style>
