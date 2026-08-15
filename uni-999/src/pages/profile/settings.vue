<template>
  <ProfileSubPage :page-key="pageKey" :title="tt('profile_settings_title', '设置')">
    <view class="settings-card">
      <view class="settings-row">
        <view class="settings-main">
          <text class="settings-title">{{ tt('profile_settings_mute', '消息静音') }}</text>
          <text class="settings-sub">{{ tt('profile_settings_mute_sub', '开启后新消息不播放提示音') }}</text>
        </view>
        <switch :checked="muteOn" color="#E63022" @change="onMuteChange" />
      </view>
      <view class="settings-row">
        <view class="settings-main">
          <text class="settings-title">{{ tt('profile_settings_push', '推送通知') }}</text>
          <text class="settings-sub">{{ pushHint }}</text>
        </view>
        <switch :checked="pushOn" color="#E63022" @change="onPushChange" />
      </view>
    </view>

    <view class="settings-ver">
      <text class="settings-ver-lab">{{ tt('profile_settings_version', '版本号') }}</text>
      <text class="settings-ver-val">{{ versionText }}</text>
    </view>
  </ProfileSubPage>
</template>

<script setup>
import { computed, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import ProfileSubPage from '../../components/ProfileSubPage.vue'
import {
  getAppVersionInfo,
  isMsgMuted,
  isPushEnabled,
  setMsgMuted,
} from '../../utils/app-prefs.js'
import { applyPushPreference, isJPushPluginPresent } from '../../utils/jpush.js'
import { copyState, localeState, tt } from '../../utils/i18n.js'

const locale = localeState()
const copyTick = copyState()
const pageKey = computed(() => String(locale.value || '') + '-' + String(copyTick.value || 0))

const muteOn = ref(false)
const pushOn = ref(true)
const versionName = ref('0.1.0')
const versionCode = ref('100')
const jpushReady = ref(false)

const versionText = computed(() => {
  const n = versionName.value || '0.1.0'
  const c = versionCode.value || ''
  return c ? `${n} (${c})` : n
})

const pushHint = computed(() => {
  if (jpushReady.value) {
    return tt('profile_settings_push_sub_on', '关闭后停止接收系统推送')
  }
  return tt(
    'profile_settings_push_sub_pending',
    '开关已保存；接入极光推送并重新打包后生效'
  )
})

function refresh() {
  muteOn.value = isMsgMuted()
  pushOn.value = isPushEnabled()
  const v = getAppVersionInfo()
  versionName.value = v.versionName
  versionCode.value = v.versionCode
  jpushReady.value = isJPushPluginPresent()
}

function onMuteChange(e) {
  const on = !!(e && e.detail && e.detail.value)
  muteOn.value = on
  setMsgMuted(on)
  uni.showToast({
    title: on
      ? tt('profile_settings_mute_on', '已开启静音')
      : tt('profile_settings_mute_off', '已关闭静音'),
    icon: 'none',
  })
}

function onPushChange(e) {
  const on = !!(e && e.detail && e.detail.value)
  pushOn.value = on
  const r = applyPushPreference(on)
  if (!r.wired) {
    uni.showToast({
      title: tt('profile_settings_push_saved', '已保存，打包接入极光后生效'),
      icon: 'none',
    })
  } else {
    uni.showToast({
      title: on
        ? tt('profile_settings_push_enabled', '推送已开启')
        : tt('profile_settings_push_disabled', '推送已关闭'),
      icon: 'none',
    })
  }
}

onShow(() => {
  refresh()
})
</script>

<style scoped>
.settings-card {
  margin: 12px 14px;
  background: #fff;
  border-radius: 14px;
  overflow: hidden;
  box-shadow: 0 4px 16px rgba(40, 20, 10, 0.06);
}
.settings-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 14px 16px;
  border-bottom: 1px solid rgba(0, 0, 0, 0.06);
}
.settings-row:last-child {
  border-bottom: none;
}
.settings-main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.settings-title {
  font-size: 15px;
  font-weight: 700;
  color: #2a1f18;
}
.settings-sub {
  font-size: 12px;
  color: #999;
  line-height: 1.35;
}
.settings-ver {
  margin: 28px 14px 40px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
}
.settings-ver-lab {
  font-size: 12px;
  color: #aaa;
}
.settings-ver-val {
  font-size: 13px;
  color: #666;
  font-weight: 600;
}
</style>
