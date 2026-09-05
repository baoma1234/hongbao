<template>
  <view class="gd-page" :style="pageStyle">
    <TopBar :title="tt('game_detail_title', '游戏详情')" />
    <view class="gd-nav" :style="navStyle">
      <text class="gd-back" @click="goBack">‹</text>
      <text class="gd-nav-spacer" />
      <text class="gd-nav-spacer" />
    </view>

    <scroll-view scroll-y class="gd-scroll" :style="{ height: scrollH }">
      <view v-if="!game" class="gd-empty">
        <text>{{ tt('game_detail_missing', '游戏不存在') }}</text>
      </view>
      <view v-else class="gd-body">
        <view class="gd-hero">
          <image class="gd-hero-img" :src="heroUrl" mode="aspectFill" />
          <view class="gd-hero-shade" />
          <view class="gd-hero-info">
            <view class="gd-title-row">
              <text class="gd-game-title">{{ game.title }}</text>
              <view v-if="game.badge" class="gd-badge" :class="game.badge">
                <text class="gd-badge-ico">🔥</text>
                <text>{{ game.badgeText || 'HOT' }}</text>
              </view>
            </view>
            <text class="gd-players">👥 {{ playersText }} {{ tt('lobby_playing', '人在玩') }}</text>
            <view class="gd-tags">
              <text class="gd-tag">🛡 {{ tt('game_detail_fair', '公平公正') }}</text>
              <text class="gd-tag">⚡ {{ tt('game_detail_fast', '秒速开奖') }}</text>
              <text class="gd-tag">👍 {{ tt('game_detail_easy', '玩法简单') }}</text>
            </view>
          </view>
        </view>

        <view class="gd-card">
          <text class="gd-card-hd">{{ tt('game_detail_intro', '游戏简介') }}</text>
          <text class="gd-card-txt">{{ game.intro }}</text>
        </view>

        <view class="gd-card">
          <text class="gd-card-hd">{{ tt('game_detail_rules', '游戏规则') }}</text>
          <view v-for="(rule, idx) in game.rules" :key="idx" class="gd-rule">
            <text class="gd-rule-dot">•</text>
            <text class="gd-rule-txt">{{ rule }}</text>
          </view>
        </view>

        <view class="gd-card gd-sessions">
          <view class="gd-sessions-hd">
            <text class="gd-card-hd">{{ tt('game_detail_sessions', '选择场次') }}</text>
            <text class="gd-sessions-tip" @click="showSessionTip">{{ tt('game_detail_how', '如何选择？') }} ›</text>
          </view>
          <view class="gd-session-grid" :class="{ single: game.sessions.length === 1 }">
            <view
              v-for="(s, idx) in game.sessions"
              :key="idx"
              class="gd-session"
              :class="{ on: selectedSession === idx }"
              hover-class="gd-hit"
              @click="selectedSession = idx"
            >
              <text class="gd-session-tag">{{ s.tag }}</text>
              <text class="gd-session-mult">{{ s.mult }}</text>
              <text class="gd-session-meta">{{ sessionEntryText(s) }}</text>
              <text v-if="s.max && s.max !== '—'" class="gd-session-meta">
                {{ tt('game_detail_max', '最高赢') }} {{ s.max }}
              </text>
            </view>
          </view>
        </view>

        <view class="gd-start-wrap">
          <button type="button" class="gd-start-btn" hover-class="gd-hit" @click="onStart">
            {{ tt('game_detail_start', '立即开始') }}
          </button>
          <text class="gd-start-sub">{{ tt('game_detail_remain', '今日剩余次数：10 次') }}</text>
        </view>

        <view class="gd-card gd-records">
          <view class="gd-records-hd">
            <text class="gd-card-hd">{{ tt('game_detail_records', '玩家战绩') }}</text>
            <text class="gd-records-more" @click="goMoreRecords">
              {{ tt('game_detail_more', '更多战绩') }} ›
            </text>
          </view>
          <view v-for="(row, idx) in records" :key="idx" class="gd-record-row">
            <image
              class="gd-record-av"
              :src="row.avatarUrl || defaultAvatar"
              mode="aspectFill"
            />
            <view class="gd-record-mid">
              <text class="gd-record-name">{{ row.name }}</text>
              <text class="gd-record-sub">{{ row.session }}</text>
            </view>
            <view class="gd-record-right">
              <text class="gd-record-win">{{ tt('game_detail_won', '赢得') }} {{ row.amount }} {{ gemLabel }}</text>
              <text class="gd-record-time">{{ row.time }}</text>
            </view>
          </view>
        </view>

        <view class="gd-scroll-pad" />
      </view>
    </scroll-view>

    <BottomTabBar active="home" />
  </view>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { onLoad, onShow, onHide } from '@dcloudio/uni-app'
import TopBar from '../../components/TopBar.vue'
import BottomTabBar from '../../components/BottomTabBar.vue'
import { apiRequest, getToken } from '../../utils/auth.js'
import { getUploadsBase, packagedStaticUrl } from '../../utils/config.js'
import { getLobbyGameDetail } from '../../data/lobby-game-detail.js'
import { HOME_TAB, safeNavigateBack } from '../../utils/nav.js'
import { tt } from '../../utils/i18n.js'
import { applySafeAreaCssVars, getSafeAreaInsets, measureChatOverlayTop } from '../../utils/safe-area.js'
import '../../styles/game-detail.css'

const LOBBY_ASSET_VER = '3'
const RECORD_CACHE_PREFIX = 'fans_hub_gd_records_v3_'
const RECORD_BUCKET_MS = 10 * 60 * 1000
const SUB_NAV_H = 48

const gameId = ref('')
const game = computed(() => getLobbyGameDetail(gameId.value))
const selectedSession = ref(0)
const playersText = ref('—')
const matchedGroupId = ref(0)
const officialGroups = ref([])
const records = ref([])
const botPlayers = ref([])
const scrollH = ref('100vh')
const navStyle = ref({})
const pageStyle = ref({})
let recordsRefreshTimer = null

const gemLabel = computed(() => tt('game_detail_gem', '红宝'))
const defaultAvatar = computed(() => packagedStaticUrl('default-avatar.png'))

function groupDisplayOnline(g) {
  return (g && (g.online_count || g.member_count || g.display_member_count)) | 0
}

function findOfficialGroup(matcher) {
  const rows = officialGroups.value || []
  if (!matcher) return null
  if (typeof matcher === 'string') {
    return rows.find((x) => String(x.name || '').indexOf(matcher) >= 0) || null
  }
  return rows.find((x) => matcher.test(String(x.name || ''))) || null
}

function resolveActiveGroupMatch() {
  const g = game.value
  if (!g) return null
  const sessions = g.sessions || []
  const sess = sessions[selectedSession.value]
  if (sess && sess.groupMatch) return sess.groupMatch
  return g.groupMatch || null
}

function syncPlayersFromOfficial() {
  const g = game.value
  if (!g) {
    matchedGroupId.value = 0
    playersText.value = '—'
    return
  }
  // 接龙大厅卡：展示四档群在线合计；进群仍按所选场次
  if (g.sumGroupMatch) {
    let sum = 0
    const rows = officialGroups.value || []
    for (let i = 0; i < rows.length; i++) {
      if (g.sumGroupMatch.test(String(rows[i].name || ''))) {
        sum += groupDisplayOnline(rows[i])
      }
    }
    playersText.value = sum > 0 ? formatCountNum(sum) : '—'
    const sessMatch = resolveActiveGroupMatch()
    const row = findOfficialGroup(sessMatch)
    matchedGroupId.value = row ? (row.id || row.group_id) | 0 : 0
    return
  }
  const matcher = resolveActiveGroupMatch()
  const row = findOfficialGroup(matcher)
  if (row) {
    matchedGroupId.value = (row.id || row.group_id) | 0
    playersText.value = formatCountNum(groupDisplayOnline(row))
    return
  }
  matchedGroupId.value = 0
  playersText.value = '—'
}

function sessionEntryText(s) {
  const e = String((s && s.entry) || '').trim()
  if (!e || e === '—' || e === '标准') {
    return tt('game_detail_entry_group', '进入群聊即可开局')
  }
  if (/^\d/.test(e)) {
    return (tt('game_detail_entry', '入场') || '入场') + ' ' + e + ' ' + gemLabel.value
  }
  return e
}

function heroAsset(name) {
  const p = String(name || '').replace(/^\/+/, '')
  const oss = String(getUploadsBase() || '').replace(/\/+$/, '')
  if (oss) return oss + '/999/static/home/lobby/' + p + '?v=' + LOBBY_ASSET_VER
  return packagedStaticUrl('home/lobby/' + p) + '?v=' + LOBBY_ASSET_VER
}

const heroUrl = computed(() => (game.value ? heroAsset(game.value.hero) : ''))

function formatCountNum(n) {
  const x = Math.max(0, Math.floor(Number(n) || 0))
  return x.toLocaleString('en-US')
}

function recordBucket() {
  return Math.floor(Date.now() / RECORD_BUCKET_MS)
}

function msToNextBucket() {
  return RECORD_BUCKET_MS - (Date.now() % RECORD_BUCKET_MS) + 200
}

function makeRng(seed) {
  let s = (seed >>> 0) || 1
  return () => {
    s = (Math.imul(s, 1664525) + 1013904223) >>> 0
    return s / 4294967296
  }
}

function hashSeed(str) {
  let h = 2166136261
  const s = String(str || '')
  for (let i = 0; i < s.length; i++) {
    h ^= s.charCodeAt(i)
    h = Math.imul(h, 16777619)
  }
  return h >>> 0
}

function shufflePickSeeded(list, count, rnd) {
  const arr = (list || []).slice()
  for (let i = arr.length - 1; i > 0; i--) {
    const j = Math.floor(rnd() * (i + 1))
    const t = arr[i]
    arr[i] = arr[j]
    arr[j] = t
  }
  return arr.slice(0, Math.max(0, count))
}

function pickRecordAmount(session, rnd) {
  const entry = String((session && session.entry) || '')
  const range = entry.match(/^(\d+)\s*-\s*(\d+)$/)
  if (range) {
    const lo = parseInt(range[1], 10) || 10
    const hi = parseInt(range[2], 10) || 1000
    return (lo + rnd() * Math.max(1, hi - lo)).toFixed(2)
  }
  const n = parseFloat(entry)
  if (!isNaN(n) && n > 0) {
    return (n * (0.6 + rnd() * 1.8)).toFixed(2)
  }
  return (10 + rnd() * 990).toFixed(2)
}

function normalizeBotPlayers(rawPlayers, rawNicks) {
  const out = []
  const seen = {}
  if (Array.isArray(rawPlayers)) {
    rawPlayers.forEach((p) => {
      if (!p || typeof p !== 'object') return
      const name = String(p.name || p.nickname || '').trim()
      if (!name || seen[name]) return
      seen[name] = 1
      out.push({
        name,
        avatar: String(p.avatar || p.avatar_url || '').trim(),
      })
    })
  }
  if (Array.isArray(rawNicks)) {
    rawNicks.forEach((n) => {
      const name = String(n || '').trim()
      if (!name || seen[name]) return
      seen[name] = 1
      out.push({ name, avatar: '' })
    })
  }
  return out
}

function cacheKey(gid, bucket) {
  return RECORD_CACHE_PREFIX + String(gid || '') + '_' + String(bucket)
}

function readCachedRecords(gid, bucket) {
  try {
    const raw = uni.getStorageSync(cacheKey(gid, bucket))
    if (!raw) return null
    const parsed = typeof raw === 'string' ? JSON.parse(raw) : raw
    if (!Array.isArray(parsed) || !parsed.length) return null
    // 必须有昵称+头像，否则重建
    if (parsed.some((r) => !r || !String(r.name || '').trim() || !String(r.avatarUrl || '').trim())) {
      return null
    }
    return parsed
  } catch (e) {
    return null
  }
}

function writeCachedRecords(gid, bucket, rows) {
  try {
    uni.setStorageSync(cacheKey(gid, bucket), JSON.stringify(rows || []))
  } catch (e) {}
}

/** 随机抽取完整机器人账号（昵称+头像绑定在同一账号） */
function buildRecords(players, bucket) {
  const gid = gameId.value || 'game'
  const cached = readCachedRecords(gid, bucket)
  if (cached) return cached

  const rnd = makeRng(hashSeed(gid + ':' + bucket))
  const pool = (players || []).filter((p) => p && String(p.name || '').trim())
  // 只抽有头像的真实机器人账号
  const withAv = pool.filter((p) => String(p.avatar || '').trim())
  const pickPool = withAv.length ? withAv : pool
  if (!pickPool.length) {
    return []
  }
  const list = shufflePickSeeded(pickPool, Math.min(4, pickPool.length), rnd)
  const sessions = game.value && game.value.sessions ? game.value.sessions : []
  const times = ['刚刚', '1分钟前', '2分钟前', '5分钟前', '8分钟前']
  const rows = list.map((p) => {
    const s =
      sessions[Math.floor(rnd() * Math.max(1, sessions.length))] || {
        tag: '热门场次',
        mult: '—',
      }
    return {
      name: String(p.name || '玩家').trim(),
      avatarUrl: String(p.avatar || '').trim(),
      session: s.tag + (s.mult && s.mult !== '—' ? ' · ' + s.mult : ''),
      amount: pickRecordAmount(s, rnd),
      time: times[Math.floor(rnd() * times.length)],
    }
  })
  writeCachedRecords(gid, bucket, rows)
  return rows
}

function refreshRecordsFromPool() {
  records.value = buildRecords(botPlayers.value, recordBucket())
}

function scheduleRecordsRefresh() {
  if (recordsRefreshTimer) {
    clearTimeout(recordsRefreshTimer)
    recordsRefreshTimer = null
  }
  recordsRefreshTimer = setTimeout(() => {
    refreshRecordsFromPool()
    scheduleRecordsRefresh()
  }, msToNextBucket())
}

function stopRecordsRefresh() {
  if (recordsRefreshTimer) {
    clearTimeout(recordsRefreshTimer)
    recordsRefreshTimer = null
  }
}

async function loadExtras() {
  try {
    const rec = await apiRequest('communityrecommend', 'GET', {})
    const rows = (rec && (rec.list || rec.rows || rec.items)) || rec || []
    if (Array.isArray(rows)) officialGroups.value = rows
  } catch (e0) {
    officialGroups.value = []
  }
  syncPlayersFromOfficial()
  try {
    const data = await apiRequest('jackpot', 'GET')
    if (data) {
      botPlayers.value = normalizeBotPlayers(data.lobby_bot_players, data.lobby_bot_nicks)
      refreshRecordsFromPool()
      return
    }
  } catch (e) {}
  botPlayers.value = []
  refreshRecordsFromPool()
}

function measureLayout() {
  try {
    applySafeAreaCssVars()
    const inset = getSafeAreaInsets()
    const sys = uni.getSystemInfoSync()
    const overlayTop = measureChatOverlayTop() || Number(inset.top || sys.statusBarHeight || 0) + 48
    // 二级红条贴在 TopBar 底边
    navStyle.value = {
      top: overlayTop + 'px',
      height: SUB_NAV_H + 'px',
      paddingTop: '0px',
    }
    pageStyle.value = {
      '--gd-nav-h': SUB_NAV_H + 'px',
      '--chat-overlay-top': overlayTop + 'px',
    }
    const tab = 56 + Number(inset.bottom || 0)
    const h = (sys.windowHeight || 667) - overlayTop - SUB_NAV_H - tab
    scrollH.value = Math.max(280, h) + 'px'
  } catch (e) {
    scrollH.value = '70vh'
  }
}

function goBack() {
  safeNavigateBack(HOME_TAB)
}

function showSessionTip() {
  uni.showModal({
    title: tt('game_detail_how', '如何选择？'),
    content:
      tt(
        'game_detail_how_body',
        '新手建议从「新手推荐」开始；熟悉规则后可选择更高倍率场次，入场红宝越多，最高奖励越高。'
      ) || '新手建议从「新手推荐」开始；熟悉规则后可选择更高倍率场次。',
    showCancel: false,
  })
}

function goMoreRecords() {
  uni.switchTab({
    url: '/pages/messages/messages',
    fail() {
      uni.reLaunch({ url: '/pages/messages/messages' })
    },
  })
}

function onStart() {
  if (!game.value) return
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  if (game.value.comingSoon) {
    uni.showToast({ title: tt('lobby_coming_soon', '敬请期待'), icon: 'none' })
    return
  }
  if (game.value.startRoute === 'yxx') {
    uni.navigateTo({ url: '/pages/yxx/hall' })
    return
  }
  const gid = matchedGroupId.value | 0
  if (game.value.startRoute === 'group' && gid > 0) {
    uni.navigateTo({
      url: '/pages/chat/chat?type=2&id=' + gid,
      fail() {
        uni.switchTab({
          url: '/pages/messages/messages',
          fail() {
            uni.reLaunch({ url: '/pages/messages/messages' })
          },
        })
      },
    })
    return
  }
  uni.switchTab({
    url: '/pages/messages/messages',
    fail() {
      uni.reLaunch({ url: '/pages/messages/messages' })
    },
  })
}

onLoad((query) => {
  gameId.value = String((query && query.game) || '').trim()
  const g = getLobbyGameDetail(gameId.value)
  selectedSession.value = g && g.fixedSession != null ? g.fixedSession | 0 : 0
})

watch(selectedSession, () => {
  syncPlayersFromOfficial()
})

onShow(() => {
  measureLayout()
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  loadExtras()
  scheduleRecordsRefresh()
})

onHide(() => {
  stopRecordsRefresh()
})

onMounted(() => {
  measureLayout()
})

onUnmounted(() => {
  stopRecordsRefresh()
})
</script>
