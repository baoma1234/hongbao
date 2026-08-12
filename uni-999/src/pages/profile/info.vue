<template>
  <ProfileSubPage :page-key="locale" :title="t('profile_info_title') || '编辑资料'">
      <view class="match-card profile-card">
        <view class="profile-avatar-wrap" @click="pickAvatar">
          <image
            class="profile-avatar-img profile-avatar-lg"
            :src="avatarSrc(avatar)"
            mode="aspectFill"
          />
          <text class="profile-avatar-hint">{{ t('profile_avatar_hint') || '点击更换头像' }}</text>
        </view>
        <view class="profile-field">
          <text class="lab">{{ t('profile_nickname_label') || '昵称' }}</text>
          <input
            class="hb-input"
            v-model="nickname"
            maxlength="30"
            :placeholder="t('profile_nickname_placeholder') || '请输入昵称，最多30字'"
          />
        </view>
        <button class="btn-uid-submit" :disabled="busy" @click="save">
          {{ busy ? (t('loading_generic') || '保存中…') : (t('profile_save_btn') || '保存') }}
        </button>
      </view>
  </ProfileSubPage>
</template>

<script setup>
import { safeNavigateBack, HOME_TAB } from '../../utils/nav.js'
import { ref } from 'vue'
import ProfileSubPage from '../../components/ProfileSubPage.vue'
import { onShow } from '@dcloudio/uni-app'
import {
  fetchProfile,
  getToken,
  updateProfile,
  uploadAvatar,
} from '../../utils/auth.js'
import { localeState, t } from '../../utils/i18n.js'
import { avatarSrc } from '../../utils/chat.js'
import '../../styles/hb.css'

const locale = localeState()
const nickname = ref('')
const avatar = ref('')
const busy = ref(false)

function goBack() {
  safeNavigateBack(HOME_TAB)
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
    uni.showToast({ title: (e && e.message) || '\u52a0\u8f7d\u5931\u8d25', icon: 'none' })
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
        if (data && data.fullurl) {
          avatar.value = data.fullurl
        }
        if (data && data.profile) {
          avatar.value = data.profile.avatar_url || data.fullurl || data.profile.avatar || avatar.value
          nickname.value = data.profile.nickname || nickname.value
        } else if (data && (data.avatar_url || data.url)) {
          avatar.value = data.avatar_url || data.fullurl || data.url
        }
        uni.showToast({ title: t('alert_avatar_ok') || '\u5934\u50cf\u5df2\u66f4\u65b0', icon: 'none' })
      } catch (e) {
        uni.showToast({ title: (e && e.message) || '\u4e0a\u4f20\u5931\u8d25', icon: 'none' })
      } finally {
        busy.value = false
      }
    },
  })
}

async function save() {
  const name = String(nickname.value || '').trim()
  if (!name) {
    uni.showToast({ title: t('alert_nickname_empty') || '\u8bf7\u586b\u5199\u6635\u79f0', icon: 'none' })
    return
  }
  busy.value = true
  try {
    const p = await updateProfile(name)
    if (p) {
      nickname.value = p.nickname || name
      avatar.value = p.avatar_url || p.avatar || avatar.value
    }
    uni.showToast({ title: t('alert_profile_saved') || '\u8d44\u6599\u5df2\u4fdd\u5b58', icon: 'none' })
    setTimeout(goBack, 400)
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '\u4fdd\u5b58\u5931\u8d25', icon: 'none' })
  } finally {
    busy.value = false
  }
}

onShow(load)
</script>
