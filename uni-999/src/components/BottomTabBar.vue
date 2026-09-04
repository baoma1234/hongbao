<template>
  <view class="bottom-action-bar show" :class="{ 'is-six': tabs.length > 5 }">
    <view
      v-for="(item, idx) in tabs"
      :key="item.tab"
      class="tab-btn"
      :class="{
        active: selected === item.tab,
        'tab-master': item.tab === 'fission',
        'tab-center': idx === centerTabIndex,
        'has-chat-unread': item.tab === 'messages' && unread > 0,
      }"
      :data-tab="item.tab"
      @click="switchTo(item)"
    >
      <view class="tab-ico tab-ico-img">
        <text v-if="item.emoji" class="tab-ico-emoji">{{ item.emoji }}</text>
        <image v-else :src="item.icon" mode="aspectFit" />
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
import { fetchConfig } from '../utils/auth.js'
import '../styles/tabbar-888.css'

const props = defineProps({
  active: { type: String, default: '' },
})

const locale = localeState()
const unread = ref(0)
const yxxTabOn = ref(false)

function refreshYxxTabFlag(cfg) {
  try {
    if (uni.getStorageSync('fanshub_yxx_preview') === '1') {
      yxxTabOn.value = true
      return
    }
  } catch (e) {}
  yxxTabOn.value = !!(cfg && cfg.yxx_tab_visible)
}

const selected = computed(() => {
  if (props.active) return props.active
  try {
    const pages = getCurrentPages()
    const cur = pages && pages.length ? pages[pages.length - 1] : null
    const route = (cur && (cur.route || '')) || ''
    if (route.indexOf('pages/community/') >= 0) return 'community'
    if (route.indexOf('pages/exchange/') >= 0) return 'home'
    if (route.indexOf('pages/notice/') >= 0) return 'home'
    if (route.indexOf('pages/commission/') >= 0) return 'home'
    if (route.indexOf('pages/messages/') >= 0) return 'messages'
    if (route.indexOf('pages/yxx/') >= 0) return 'yxx'
    if (route.indexOf('pages/fission/') >= 0) return 'fission'
    if (route.indexOf('pages/master/') >= 0) return 'fission'
    if (route.indexOf('pages/profile/') >= 0) return 'profile'
  } catch (e) {}
  return 'home'
})

const tabs = computed(() => {
  void locale.value
  const list = [
    {
      tab: 'home',
      path: '/pages/home/home',
      label: t('tab_bar_home') || '红宝',
      icon: packagedStaticUrl('tab/home.png'),
      nativeTab: true,
    },
    {
      tab: 'community',
      path: '/pages/community/community',
      label: t('tab_bar_community') || '社群',
      icon: packagedStaticUrl('tab/community.png'),
      nativeTab: true,
    },
    {
      tab: 'messages',
      path: '/pages/messages/messages',
      label: t('tab_bar_messages') || '消息',
      icon: packagedStaticUrl('logo.png'),
      nativeTab: true,
    },
  ]
  if (yxxTabOn.value) {
    list.push({
      tab: 'yxx',
      path: '/pages/yxx/hall',
      label: t('tab_bar_yxx') || '鱼虾蟹',
      icon: '',
      nativeTab: false,
      emoji: '🦀',
    })
  }
  list.push(
    {
      tab: 'fission',
      path: '/pages/fission/detail',
      label: t('tab_bar_fission') || '裂变',
      icon: packagedStaticUrl('tab/fission.png'),
      nativeTab: true,
    },
    {
      tab: 'profile',
      path: '/pages/profile/profile',
      label: t('tab_bar_profile') || '我的',
      icon: packagedStaticUrl('tab/profile.png'),
      nativeTab: true,
    }
  )
  return list
})

/** 底栏最中间一项：图标放大一倍 */
const centerTabIndex = computed(() => {
  const n = tabs.value.length
  if (n <= 0) return -1
  return Math.floor((n - 1) / 2)
})

function refreshUnread(n) {
  if (typeof n === 'number' && isFinite(n)) {
    unread.value = Math.max(0, n | 0)
    return
  }
  unread.value = getChatUnreadTotal()
}

function onTabUnread(n) {
  refreshUnread(n)
}

function currentRoute() {
  try {
    const pages = typeof getCurrentPages === 'function' ? getCurrentPages() : []
    const cur = pages && pages.length ? pages[pages.length - 1] : null
    return String((cur && cur.route) || '').replace(/^\//, '')
  } catch (e) {
    return ''
  }
}

function isOnTabPage(item) {
  if (!item || !item.path) return false
  const route = currentRoute()
  if (!route) return false
  const target = String(item.path || '')
    .replace(/^\//, '')
    .replace(/\?.*$/, '')
  return route === target
}

function switchTo(item) {
  if (!item) return
  // 公告/佣金等子页也会高亮「大厅」；必须按真实路由判断，否则点大厅无法退回
  if (isOnTabPage(item)) return
  if (item.nativeTab === false) {
    uni.reLaunch({ url: item.path })
    return
  }
  uni.switchTab({
    url: item.path,
    fail: () => uni.reLaunch({ url: item.path }),
  })
}

onMounted(() => {
  refreshUnread()
  fetchConfig()
    .then((cfg) => refreshYxxTabFlag(cfg))
    .catch(() => refreshYxxTabFlag(null))
  try {
    uni.$on && uni.$on('fanshub-tab-unread', onTabUnread)
    uni.$on && uni.$on('fanshub-inbox-unread', refreshUnread)
  } catch (e) {}
  try {
    uni.hideTabBar({ animation: false })
  } catch (e) {}
})

onUnmounted(() => {
  try {
    uni.$off && uni.$off('fanshub-tab-unread', onTabUnread)
    uni.$off && uni.$off('fanshub-inbox-unread', refreshUnread)
  } catch (e) {}
})
</script>
