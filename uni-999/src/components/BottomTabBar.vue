<template>
  <view class="bottom-action-bar show" :key="locale">
    <view
      v-for="item in tabs"
      :key="item.tab"
      class="tab-btn"
      :class="{
        active: selected === item.tab,
        'tab-master': item.tab === 'fission',
        'has-chat-unread': item.tab === 'messages' && unread > 0,
      }"
      :data-tab="item.tab"
      @click="switchTo(item)"
    >
      <view class="tab-ico tab-ico-img">
        <image :src="item.icon" mode="aspectFit" />
      </view>
      <text>{{ item.label }}</text>
      <text v-if="item.tab === 'messages' && unread > 0" class="chat-tab-badge">
        {{ unread > 99 ? '99+' : unread }}
      </text>
    </view>
  </view>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { localeState, t } from '../utils/i18n.js'
import { packagedStaticUrl } from '../utils/config.js'
import { getChatUnreadTotal } from '../utils/tab-badge.js'
import '../styles/tabbar-888.css'

const props = defineProps({
  active: { type: String, default: '' },
})

const locale = localeState()
const unread = ref(0)
let timer = null

const selected = computed(() => {
  if (props.active) return props.active
  try {
    const pages = getCurrentPages()
    const cur = pages && pages.length ? pages[pages.length - 1] : null
    const route = (cur && (cur.route || '')) || ''
    if (route.indexOf('pages/exchange/') >= 0) return 'exchange'
    if (route.indexOf('pages/messages/') >= 0) return 'messages'
    if (route.indexOf('pages/fission/') >= 0) return 'fission'
    if (route.indexOf('pages/master/') >= 0) return 'fission'
    if (route.indexOf('pages/profile/') >= 0) return 'profile'
  } catch (e) {}
  return 'home'
})

const tabs = computed(() => {
  void locale.value
  return [
    {
      tab: 'home',
      path: '/pages/home/home',
      label: t('tab_bar_home') || '大厅',
      icon: packagedStaticUrl('tab/home.png'),
    },
    {
      tab: 'exchange',
      path: '/pages/exchange/exchange',
      label: t('tab_bar_exchange') || '闪兑',
      icon: packagedStaticUrl('tab/exchange.png'),
    },
    {
      tab: 'messages',
      path: '/pages/messages/messages',
      label: t('tab_bar_messages') || '红宝',
      icon: packagedStaticUrl('logo.png'),
    },
    {
      tab: 'fission',
      path: '/pages/fission/detail',
      label: t('tab_bar_fission') || '裂变',
      icon: packagedStaticUrl('tab/fission.png'),
    },
    {
      tab: 'profile',
      path: '/pages/profile/profile',
      label: t('tab_bar_profile') || '我的',
      icon: packagedStaticUrl('tab/profile.png'),
    },
  ]
})

function refreshUnread() {
  unread.value = getChatUnreadTotal()
}

function switchTo(item) {
  if (!item) return
  if (selected.value === item.tab) return
  uni.switchTab({ url: item.path })
}

onMounted(() => {
  refreshUnread()
  timer = setInterval(refreshUnread, 2000)
  try {
    uni.hideTabBar({ animation: false })
  } catch (e) {}
})

onUnmounted(() => {
  if (timer) clearInterval(timer)
})
</script>
