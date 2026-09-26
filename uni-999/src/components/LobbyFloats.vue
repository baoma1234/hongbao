<template>
  <view v-if="leftRows.length" class="home-lobby-floats is-left" :style="wrapStyle">
    <view
      v-for="f in leftRows"
      :key="'fl' + f.id"
      class="home-lobby-float"
      hover-class="home-lobby-float--active"
      @click="onTap(f)"
    >
      <image class="home-lobby-float-img" :src="f.src" mode="aspectFit" />
      <view
        class="home-lobby-float-close"
        hover-class="home-lobby-float-close--active"
        @click.stop="onDismiss(f)"
      >×</view>
    </view>
  </view>
  <view v-if="rightRows.length" class="home-lobby-floats is-right" :style="wrapStyle">
    <view
      v-for="f in rightRows"
      :key="'fr' + f.id"
      class="home-lobby-float"
      hover-class="home-lobby-float--active"
      @click="onTap(f)"
    >
      <image class="home-lobby-float-img" :src="f.src" mode="aspectFit" />
      <view
        class="home-lobby-float-close"
        hover-class="home-lobby-float-close--active"
        @click.stop="onDismiss(f)"
      >×</view>
    </view>
  </view>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { apiRequest, getToken } from '../utils/auth.js'
import { getUploadsBase, packagedStaticUrl } from '../utils/config.js'
import { openExternalHttpUrl } from '../utils/wallet.js'
import { openLobbyLink } from '../utils/lobby-nav.js'
import { getSafeAreaInsets } from '../utils/safe-area.js'
import '../styles/home-lobby-floats.css'

const props = defineProps({
  /** 父页已拉到的 floats 行（大厅可传入，避免重复请求） */
  rows: { type: Array, default: null },
})

const ASSET_VER = '23'
const DISMISS_KEY = 'fanshub_lobby_floats_dismissed'
const remoteRows = ref([])
const dismissedIds = ref(loadDismissed())

const wrapStyle = computed(() => {
  let bottom = 70
  try {
    const inset = getSafeAreaInsets()
    bottom = 56 + Math.max(0, Number(inset.bottom || 0)) + 12
  } catch (e) {}
  return { bottom: bottom + 'px' }
})

function mediaUrl(resolved, raw) {
  const u = String(resolved || '').trim()
  if (/^https?:\/\//i.test(u) || u.indexOf('data:') === 0) return u
  let p = String(raw || u || '').trim()
  if (/^https?:\/\//i.test(p)) return p
  p = p.replace(/^\/+/, '').replace(/^static\//, '')
  if (!p) return ''
  const base = String(getUploadsBase() || '').replace(/\/+$/, '')
  if (p.indexOf('uploads/') === 0) {
    return base ? base + '/' + p : '/' + p
  }
  if (p.indexOf('999/static/') === 0) {
    return base ? base + '/' + p + '?v=' + ASSET_VER : '/' + p + '?v=' + ASSET_VER
  }
  let lobbyFile = ''
  if (p.indexOf('home/lobby/') === 0) lobbyFile = p.slice('home/lobby/'.length)
  else if (p.indexOf('/') < 0 && /\.(png|jpe?g|webp|gif)$/i.test(p)) lobbyFile = p
  if (lobbyFile) {
    if (base) return base + '/999/static/home/lobby/' + lobbyFile + '?v=' + ASSET_VER
    return packagedStaticUrl('home/lobby/' + lobbyFile) + '?v=' + ASSET_VER
  }
  return ''
}

function dismissSession() {
  const tok = String(getToken() || '')
  return tok ? tok.slice(-32) : ''
}

function loadDismissed() {
  try {
    const raw = uni.getStorageSync(DISMISS_KEY)
    const o = typeof raw === 'string' ? JSON.parse(raw || '{}') : raw && typeof raw === 'object' ? raw : null
    const session = dismissSession()
    if (!o || !session || String(o.session || '') !== session) return new Set()
    const ids = Array.isArray(o.ids) ? o.ids : []
    return new Set(ids.map((x) => String(x)))
  } catch (e) {
    return new Set()
  }
}

function persistDismissed(ids) {
  const session = dismissSession()
  if (!session) return
  try {
    uni.setStorageSync(DISMISS_KEY, JSON.stringify({ session, ids: Array.from(ids) }))
  } catch (e) {}
}

const sourceRows = computed(() => {
  if (Array.isArray(props.rows) && props.rows.length) return props.rows
  return remoteRows.value
})

const parsed = computed(() => {
  const dismissed = dismissedIds.value
  const out = []
  const rows = sourceRows.value
  if (!Array.isArray(rows)) return out
  for (let i = 0; i < rows.length; i++) {
    const f = rows[i]
    if (!f) continue
    const src = mediaUrl(String(f.image || ''), String(f.image_raw || f.image || ''))
    if (!src) continue
    const id = String(f.id != null ? f.id : 'f' + i)
    if (dismissed.has(id)) continue
    const side = String(f.side || 'right').toLowerCase() === 'left' ? 'left' : 'right'
    out.push({
      id,
      side,
      linkType: String(f.link_type || f.linkType || 'internal'),
      linkUrl: String(f.link_url != null ? f.link_url : f.linkUrl || ''),
      src,
    })
  }
  return out
})

const leftRows = computed(() => parsed.value.filter((f) => f.side === 'left'))
const rightRows = computed(() => parsed.value.filter((f) => f.side === 'right'))

async function fetchFloats() {
  // 父页显式传入数组时不重复请求（大厅）
  if (Array.isArray(props.rows)) return
  try {
    const data = await apiRequest('lobbyhome', 'GET', {})
    const list = data && data.floats
    remoteRows.value = Array.isArray(list) ? list : []
  } catch (e) {
    /* keep previous */
  }
}

function onTap(f) {
  if (!f) return
  const lt = String(f.linkType || 'internal')
  if (lt === 'none') return
  const url = String(f.linkUrl || '').trim()
  if (!url) return
  if (lt === 'external') {
    if (/^https?:\/\//i.test(url)) openExternalHttpUrl(url)
    else uni.showToast({ title: '外链无效', icon: 'none' })
    return
  }
  openLobbyLink(url)
}

function onDismiss(f) {
  if (!f || f.id == null) return
  const next = new Set(dismissedIds.value)
  next.add(String(f.id))
  dismissedIds.value = next
  persistDismissed(next)
}

function refresh() {
  dismissedIds.value = loadDismissed()
  void fetchFloats()
}

watch(
  () => props.rows,
  (v) => {
    if (Array.isArray(v) && v.length) remoteRows.value = []
  }
)

onMounted(refresh)
onShow(refresh)
</script>
