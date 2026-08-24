<template>
  <view
    class="hb-page profile-sub-page"
    :class="pageClass"
    :style="profileSubPageStyle"
    :key="pageKey === '' || pageKey == null ? undefined : pageKey"
  >
    <TopBar />
    <view class="profile-sub-hd" :style="profileSubHdStyle">
      <text class="profile-back-btn" @click="handleBack">‹</text>
      <text class="profile-sub-title">
        <slot name="title">{{ title }}</slot>
      </text>
      <text class="profile-sub-spacer" />
    </view>
    <view class="profile-sub-body" :class="bodyClass">
      <slot />
    </view>
  </view>
</template>

<script setup>
import { onMounted } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import TopBar from './TopBar.vue'
import { HOME_TAB, safeNavigateBack } from '../utils/nav.js'
import { useProfileSubHdStyle } from '../utils/profile-sub-layout.js'

const props = defineProps({
  title: { type: String, default: '' },
  bodyClass: { type: [String, Object, Array], default: '' },
  pageClass: { type: [String, Object, Array], default: '' },
  pageKey: { type: [String, Number], default: '' },
  /** safeNavigateBack 无历史时的回退页 */
  backFallback: { type: String, default: HOME_TAB },
})

const { profileSubHdStyle, profileSubPageStyle, refreshProfileSubLayout } = useProfileSubHdStyle()

function handleBack() {
  safeNavigateBack(props.backFallback)
}

onMounted(() => {
  refreshProfileSubLayout()
})

onShow(() => {
  refreshProfileSubLayout()
})
</script>
