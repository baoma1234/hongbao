<template>
  <view class="hb-page profile-sub-page" :key="locale">
    <view class="profile-sub-hd">
      <text class="profile-back-btn" @click="goBack">‹</text>
      <text class="profile-sub-title">{{ t('profile_info_title') || '编辑资料' }}</text>
      <text class="profile-sub-spacer" />
    </view>
    <view class="profile-sub-body">
      <view class="match-card profile-card">
        <view class="profile-avatar-edit" @click="pickAvatar">
          <image v-if="avatar" class="profile-avatar-img profile-avatar-lg" :src="avatar" mode="aspectFill" />
          <view v-else class="profile-avatar-fallback profile-avatar-lg">{{ letter }}</view>
          <text class="profile-avatar-hint">{{ t('profile_avatar_hint') || '点击更换头像' }}</text>
        </view>
        <view class="profile-field">
          <text class="lab">{{ t('profile_nickname_label') || '昵称' }}</text>
          <input
            class="hb-input"
            v-model="nickname"
            maxlength="30"
            :placeholder="t('profile_nickname_placeholder') || '请输入昵称（最多30字）'"
          />
        </view>
        <button class="btn-uid-submit" :disabled="busy" @click="save">
          {{ busy ? (t('loading_generic') || '加载中...') : (t('profile_save_btn') || '保存资料') }}
        </button>
      </view>
    </view>
  </view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import {
  fetchProfile,
  getToken,
  updateProfile,
  uploadAvatar,
} from '../../utils/auth.js'
import { localeState, t } from '../../utils/i18n.js'

const locale = localeState()
const nickname = ref('')
const avatar = ref('')
const busy = ref(false)

const letter = computed(() => String(nickname.value || '?').charAt(0))

function goBack() {
  uni.navigateBack({ fail: () => uni.switchTab({ url: '/pages/profile/profile' }) })
}

async function load() {
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  try {
    const p = await fetchProfile()
    nickname.value = p.nickname || p.username || ''
    avatar.value = p.avatar_url || p.avatar || ''
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '加载失败', icon: 'none' })
  }
}

function pickAvatar() {
  uni.chooseImage({
    count: 1,
    sizeType: ['compressed'],
    success: async (res) => {
      const path = res.tempFilePaths && res.tempFilePaths[0]
      if (!path) return
      busy.value = true
      try {
        const data = await uploadAvatar(path)
        if (data && data.profile) {
          avatar.value = data.profile.avatar_url || data.profile.avatar || avatar.value
          nickname.value = data.profile.nickname || nickname.value
        } else if (data && (data.avatar_url || data.url)) {
          avatar.value = data.avatar_url || data.url
        }
        uni.showToast({ title: t('alert_avatar_ok') || '头像已更新', icon: 'none' })
      } catch (e) {
        uni.showToast({ title: (e && e.message) || '上传失败', icon: 'none' })
      } finally {
        busy.value = false
      }
    },
  })
}

async function save() {
  const name = String(nickname.value || '').trim()
  if (!name) {
    uni.showToast({ title: t('alert_nickname_empty') || '请输入昵称', icon: 'none' })
    return
  }
  busy.value = true
  try {
    const p = await updateProfile(name)
    if (p) {
      nickname.value = p.nickname || name
      avatar.value = p.avatar_url || p.avatar || avatar.value
    }
    uni.showToast({ title: t('alert_profile_saved') || '资料已保存', icon: 'none' })
    setTimeout(goBack, 400)
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '保存失败', icon: 'none' })
  } finally {
    busy.value = false
  }
}

onShow(load)
</script>
