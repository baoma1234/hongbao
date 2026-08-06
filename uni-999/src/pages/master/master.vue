<template>
  <view class="hb-page tab-master-page" :key="locale">
    <TopBar />
    <view id="tabMaster" class="tab-page active">
      <view class="master-festive-bg" aria-hidden="true">
        <view class="master-glow master-glow--a" />
        <view class="master-glow master-glow--b" />
        <view class="master-glow master-glow--c" />
        <view class="master-ornament master-ornament--tl" />
        <view class="master-ornament master-ornament--tr" />
      </view>

      <view class="master-page-inner">
        <view class="master-hero">
          <view class="page-hero-title master-hero-title">
            {{ t('page_hero_master_title') || '团长专属二期大厅' }}
          </view>
          <view class="master-hero-sub">
            {{ t('page_hero_master_sub') || '荣誉天梯明牌奖励 · 7天星火暴击 · 战队催活雷达' }}
          </view>
        </view>

        <view v-if="loading" class="team-radar-loading">{{ t('loading_generic') || '加载中…' }}</view>

        <template v-else>
          <view class="master-lock-card" v-if="p2.enabled && !master">
            <view class="master-lock-ico">🔒</view>
            <view class="master-lock-title">{{ t('master_lock_title') || '团长通道待解锁' }}</view>
            <view class="master-lock-desc">
              {{ t('master_lock_desc') || '完成首笔 VIP 福利核销后，将开放：荣誉天梯宝箱、7天星火暴击、战队催活雷达。' }}
            </view>
            <view class="master-lock-btn" @click="goHome">
              {{ t('master_lock_btn') || '前往领取页' }}
            </view>
          </view>

          <view class="master-lock-card" v-if="!p2.enabled">
            <view class="master-lock-title">团长二期未开启</view>
          </view>

          <view id="masterHonorBlock" v-if="p2.enabled">
            <view class="honor-ladder-card" @click="onCopyPromo">
              <view class="honor-ladder-ribbon">
                <text class="honor-ladder-title">{{ t('phase2_honor_title') || '团长天梯权益' }}</text>
              </view>
              <view class="honor-progress-wrap">
                <view class="honor-progress-meta">
                  <text>{{ subCountText }}</text>
                  <text class="honor-pct">{{ Math.round(progressPct) }}%</text>
                </view>
                <view class="honor-ladder-track">
                  <view class="honor-ladder-fill" :style="{ width: progressPct + '%' }" />
                </view>
              </view>
              <view class="honor-ladder-nodes">
                <view
                  v-for="n in tiers"
                  :key="n.id"
                  class="honor-tier"
                  :class="[
                    'honor-tier--' + n.icon,
                    n.state === 'reached' ? 'is-reached' : '',
                    n.state === 'current' ? 'is-current' : '',
                    n.state === 'locked' ? 'is-locked' : '',
                  ]"
                >
                  <view class="honor-tier-main">
                    <image class="honor-tier-ico" :src="honorIcon(n.icon)" mode="aspectFit" />
                    <view class="honor-tier-info">
                      <view class="honor-tier-name">{{ n.name }}</view>
                      <view class="honor-tier-req">
                        {{ t('phase2_honor_need_people', { n: n.threshold }) || ('达标需 ' + n.threshold + ' 人') }}
                      </view>
                    </view>
                    <view class="honor-tier-badge">{{ n.badge }}</view>
                  </view>
                  <view class="honor-tier-rewards">
                    <view class="honor-reward" :class="{ 'honor-reward--hot': n.cashHot }">
                      <text class="honor-reward-em">{{ t('phase2_honor_col_shares') || '解锁股份' }}</text>
                      <text class="honor-reward-strong">{{ n.rightsText }}股</text>
                      <text class="honor-reward-span">≈ ¥{{ n.rightsVal }}</text>
                    </view>
                    <view
                      class="honor-reward honor-reward--cash"
                      :class="{ 'is-empty': n.balance <= 0 }"
                    >
                      <text class="honor-reward-em">{{ t('phase2_honor_col_cash') || '现金奖励' }}</text>
                      <text class="honor-reward-strong">
                        {{ n.balance > 0 ? ('¥' + n.balance) : (t('phase2_honor_cash_none') || '暂无现金') }}
                      </text>
                    </view>
                  </view>
                </view>
              </view>
              <view class="honor-ladder-hint">{{ honorHint }}</view>
            </view>
          </view>

          <view id="masterPhase2Block" v-if="master">
            <view class="checkin-panel checkin-panel--hongbao">
              <view class="checkin-hongbao-body">
                <view class="checkin-hongbao-head">
                  <view class="checkin-hongbao-title">
                    <view class="checkin-flame" />
                    <text>7天星火暴击</text>
                  </view>
                  <view class="checkin-streak-label">{{ streakText }}</view>
                </view>
                <view class="checkin-ledger">{{ ledgerText }}</view>
                <view class="checkin-streak-frozen" v-if="frozenText">{{ frozenText }}</view>
                <view class="checkin-streak-bar">
                  <view class="checkin-streak-fill" :style="{ width: streakPct + '%' }" />
                </view>
                <view class="checkin-violent-row" @click="violent = !violent">
                  <checkbox :checked="violent" color="#2196F3" @click.stop="violent = !violent" />
                  <text>{{ violentLabel }}</text>
                </view>
                <view class="checkin-pending-box" v-if="showPendingBox">{{ pendingBoxText }}</view>
                <view class="checkin-btn-wrap">
                  <view
                    class="btn-checkin-main"
                    :class="{ 'is-done': checkedToday, 'is-disabled': busy }"
                    @click="onCheckin"
                  >
                    <text class="btn-checkin-main-text">{{ checkinBtnText }}</text>
                  </view>
                </view>
                <view class="checkin-btn-tip" v-if="checkinTip">{{ checkinTip }}</view>
              </view>
            </view>

            <view class="team-radar-panel">
              <view class="team-radar-title">{{ t('phase2_radar_title') || '📡 战队催活雷达 · 实时列表' }}</view>
              <view class="team-radar-viewport">
                <view v-if="!radar.length" class="team-radar-loading">
                  {{ t('phase2_radar_empty') || '暂无直属下线' }}
                </view>
                <view v-else class="team-radar-track">
                  <view
                    v-for="row in radar"
                    :key="row.user_id || row.mobile_mask"
                    class="team-radar-row"
                  >
                    <view class="team-radar-main">
                      <view>{{ row.mobile_mask || ('ID' + row.user_id) }}</view>
                      <view :class="{ 'team-radar-done': row.withdrawn }">
                        {{ row.withdrawn
                          ? (t('phase2_radar_done') || '已达标')
                          : (t('phase2_radar_progress', { balance: Number(row.balance || row.hongbao || 0).toFixed(2), threshold: row.threshold || 50 }) || (Number(row.balance || 0).toFixed(2) + ' / ' + (row.threshold || 50))) }}
                      </view>
                    </view>
                    <view
                      v-if="row.can_urge !== false && !row.withdrawn"
                      class="btn-urge"
                      @click="onUrge(row)"
                    >{{ t('phase2_radar_urge') || '催促' }}</view>
                    <text v-else-if="row.withdrawn" class="team-radar-done">
                      {{ t('phase2_radar_done') || '已达标' }}
                    </text>
                  </view>
                </view>
              </view>
            </view>
          </view>
        </template>
      </view>
    </view>
    <BottomTabBar active="master" />
  </view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { onShow, onHide } from '@dcloudio/uni-app'
import TopBar from '../../components/TopBar.vue'
import BottomTabBar from '../../components/BottomTabBar.vue'
import { getToken, apiRequest } from '../../utils/auth.js'
import { assetBase, localeState, t } from '../../utils/i18n.js'
import {
  copySharePromo,
  copyText,
  doCheckin,
  honorTier,
  isMaster,
  loadMasterProfile,
  loadTeamRadar,
  mergeTeamRadar,
  phase2Of,
  urgeCopy,
} from '../../utils/master.js'
import '../../styles/tabs-extra.css'
import '../../styles/master-uni-adapter.css'

const locale = localeState()
const loading = ref(true)
const profile = ref({})
const radar = ref([])
const violent = ref(true)
const busy = ref(false)
const violentBonus = ref(4)
const withdrawThreshold = ref(50)
let pollTimer = null
let lastEventsSig = ''

const p2 = computed(() => phase2Of(profile.value))
const master = computed(() => isMaster(p2.value))
const honor = computed(() => p2.value.honor || {})
const checkin = computed(() => p2.value.checkin || {})
const tiers = computed(() => {
  void locale.value
  return honorTier(honor.value, t)
})
const progressPct = computed(() => Math.max(0, Math.min(100, Number(honor.value.progress_percent) || 0)))
const subCountText = computed(() => {
  void locale.value
  const c = honor.value.sub_withdrawn_count | 0
  return t('phase2_honor_sub_count', { count: c }) || ('已达标转化 ' + c + ' 人')
})
const honorHint = computed(() => {
  void locale.value
  const h = honor.value
  if (h.capped) {
    return t('phase2_honor_capped', { pack_total: h.top_pack_total || 0 }) || '长期天梯已封顶 · 点击复制密令继续带队'
  }
  if (h.next_tier) {
    const need = h.next_tier.need != null ? h.next_tier.need : 0
    const name = t('phase2_honor_name_' + (h.next_tier.id || '')) || h.next_tier.name || ''
    return t('phase2_honor_progress_short', { name, need: Math.max(0, need) }) || ('距离解锁「' + name + '」还差 ' + Math.max(0, need) + ' 人')
  }
  return t('phase2_honor_hint') || '点击本卡复制专属推广密令'
})

const streak = computed(() => checkin.value.streak_day | 0)
const streakPct = computed(() => Math.min(100, Math.round((streak.value / 7) * 100)))
const streakText = computed(() => {
  void locale.value
  return t('phase2_checkin_streak', { streak: streak.value }) || ('连续暴力打卡 ' + streak.value + '/7天')
})
const ledgerText = computed(() => {
  void locale.value
  const raw =
    t('phase2_checkin_ledger_short', { jackpot: '175', loss: '140' }) ||
    t('phase2_checkin_ledger', { jackpot: '175', loss: '140', streak: streak.value }) ||
    '满7天5倍核爆总池，保底 ¥175 筹码秒提现\n断签降级，直接损失 ¥140'
  return String(raw)
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<\/?[^>]+>/g, '')
    .trim()
})
const frozenText = computed(() => {
  void locale.value
  if (!p2.value.streak_frozen) return ''
  const need = Math.max(0, 2 - (p2.value.today_invite_count | 0))
  if (need > 0) return t('phase2_checkin_frozen', { need }) || ('断签冻结：今日再拉 ' + need + ' 人可复活')
  return t('phase2_checkin_revive_ready') || '今日已拉满 2 人，暴击资格将自动复活'
})
const bonusAmtText = computed(() => Number(violentBonus.value || 4).toFixed(2))
const violentMaxText = computed(() => (1 + Number(violentBonus.value || 4)).toFixed(2))
const showPendingBox = computed(
  () => !!checkedToday.value && checkin.value.today_mode === 'violent'
)
const pendingBoxText = computed(() => {
  void locale.value
  const amt = bonusAmtText.value
  if (checkin.value.bonus_unlocked) {
    return t('phase2_checkin_pending_ok', { amount: amt }) || ('✓ 对账成功，今日额外￥' + amt + ' 已全额到账！')
  }
  return t('phase2_checkin_pending', { amount: amt }) || ('⏳ 今日暴力对账中：￥' + amt + ' 元(等待散户新客注册中…)')
})
const checkedToday = computed(() => !!checkin.value.checked_today)
const violentLabel = computed(() => {
  void locale.value
  return t('phase2_checkin_toggle', { amount: violentMaxText.value }) || ('激活【5倍暴力分享签到】（今日最高 ¥' + violentMaxText.value + '）')
})
const checkinBtnText = computed(() => {
  void locale.value
  if (checkedToday.value) {
    const mode =
      checkin.value.today_mode === 'violent'
        ? t('phase2_checkin_mode_violent') || '暴力'
        : t('phase2_checkin_mode_normal') || '普通'
    return t('phase2_checkin_done_btn', { mode }) || ('今日已签到 · ' + mode)
  }
  return violent.value
    ? t('phase2_checkin_violent_btn_short') || '暴力分享签到'
    : t('phase2_checkin_normal_btn_short') || '普通打卡'
})
const checkinTip = computed(() => {
  void locale.value
  if (checkedToday.value) return ''
  return violent.value
    ? t('phase2_checkin_violent_tip') || '一键复制今日密令 · 锁定制胜7天全勤'
    : t('phase2_checkin_normal_tip') || '今日仅得 1 元 · 放弃 7 天大奖资格'
})

function honorIcon(ico) {
  return assetBase() + 'static/honor/' + (ico || 'bronze') + '.svg'
}

function goHome() {
  uni.switchTab({ url: '/pages/home/home' })
}

function goExchange() {
  uni.switchTab({ url: '/pages/exchange/exchange' })
}

async function onCopyPromo() {
  try {
    const data = await copySharePromo()
    await copyText((data && data.share_text) || '')
    uni.showToast({ title: t('phase2_toast_promo_ok') || '推广密令已复制', icon: 'none' })
  } catch (e) {
    uni.showToast({ title: (e && e.message) || t('phase2_toast_copy_fail') || '复制失败', icon: 'none' })
  }
}

function stripHtml(s) {
  return String(s || '')
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<\/?[^>]+>/g, '')
    .trim()
}

function showPhase2Events(events) {
  if (!events || !events.length) return
  const ev = events[0]
  const rest = events.slice(1)
  const title = ev.title || (t('phase2_toast_checkin_ok') || '提示')
  const content = stripHtml(ev.message)

  if (ev.type === 'confirm_normal') {
    uni.showModal({
      title: title || (t('phase2_confirm_violent_title') || '确认放弃暴力分享？'),
      content: content || (t('phase2_confirm_violent_msg') || '普通打卡今日仅得 ¥1，并放弃 7 天暴击资格。'),
      confirmText: t('phase2_btn_persist_1') || '坚持领1元',
      cancelText: t('phase2_btn_reselect_violent') || '改选暴力',
      success: async (res) => {
        busy.value = true
        try {
          if (res.confirm) {
            const data = await doCheckin(false, true)
            await afterCheckin(data, true)
          } else {
            violent.value = true
            const data = await doCheckin(true, true)
            await afterCheckin(data, true)
          }
        } catch (e) {
          uni.showToast({ title: (e && e.message) || '签到失败', icon: 'none' })
        } finally {
          busy.value = false
        }
      },
    })
    return
  }

  let confirmText = t('phase2_btn_know') || '知道了'
  let onOk = () => {
    if (rest.length) setTimeout(() => showPhase2Events(rest), 450)
  }
  if (ev.type === 'day7_explosion') {
    confirmText = t('phase2_btn_day7_cash') || '去闪兑'
    onOk = () => {
      goExchange()
      if (rest.length) setTimeout(() => showPhase2Events(rest), 600)
    }
  } else if (ev.type === 'honor_tier') {
    if (ev.capped) {
      confirmText = t('phase2_btn_honor_withdraw') || '去领取'
      onOk = () => {
        goHome()
        if (rest.length) setTimeout(() => showPhase2Events(rest), 600)
      }
    } else {
      confirmText = t('phase2_btn_honor_exchange') || '去闪兑'
      onOk = () => {
        goExchange()
        if (rest.length) setTimeout(() => showPhase2Events(rest), 600)
      }
    }
  } else if (ev.type === 'mode_master') {
    confirmText = t('phase2_btn_enter_master') || '进入团长大厅'
  }

  uni.showModal({
    title,
    content: content || title,
    showCancel: false,
    confirmText: String(confirmText).slice(0, 8),
    success: () => onOk(),
  })
}

async function onCheckin() {
  if (busy.value) return
  if (checkedToday.value) {
    await onCopyPromo()
    return
  }
  busy.value = true
  try {
    const data = await doCheckin(violent.value, false)
    if (data && data.need_confirm) {
      showPhase2Events(data.events && data.events.length ? data.events : [{ type: 'confirm_normal', title: t('phase2_confirm_violent_title'), message: t('phase2_confirm_violent_msg') }])
      return
    }
    await afterCheckin(data)
  } catch (e) {
    uni.showToast({ title: (e && e.message) || t('phase2_toast_checkin_fail') || '签到失败', icon: 'none' })
  } finally {
    busy.value = false
  }
}

async function afterCheckin(data, fromConfirm) {
  if (data && data.share && data.share.share_text) {
    try {
      await copyText(data.share.share_text)
    } catch (e) {}
  }
  if (data && data.profile) profile.value = data.profile
  else await refresh(true)
  const events = (data && data.events) || []
  if (events.length) {
    showPhase2Events(events)
  } else if (!fromConfirm) {
    uni.showToast({ title: t('phase2_toast_checkin_ok') || '签到成功 · 密令已复制', icon: 'none' })
  }
  startPoll()
}

async function onUrge(row) {
  try {
    if (row.virtual || !(row.user_id > 0)) {
      const data = await copySharePromo()
      await copyText((data && data.share_text) || '')
    } else {
      const data = await urgeCopy(row.user_id)
      await copyText((data && data.text) || '')
    }
    uni.showToast({ title: t('phase2_toast_urge_ok') || '催活文案已复制', icon: 'none' })
  } catch (e) {
    uni.showToast({ title: (e && e.message) || t('phase2_toast_copy_fail') || '复制失败', icon: 'none' })
  }
}

async function refreshRadar() {
  if (!master.value) {
    radar.value = []
    return
  }
  try {
    const data = await loadTeamRadar()
    radar.value = mergeTeamRadar((data && data.list) || [], withdrawThreshold.value)
  } catch (e) {
    radar.value = mergeTeamRadar([], withdrawThreshold.value)
  }
}

async function loadCfg() {
  try {
    const cfg = await apiRequest('config', 'GET')
    if (!cfg) return
    if (cfg.checkin_violent_bonus != null) {
      const n = parseFloat(cfg.checkin_violent_bonus)
      if (!isNaN(n) && n > 0) violentBonus.value = n
    }
    if (cfg.withdraw_threshold != null) {
      const th = parseFloat(cfg.withdraw_threshold)
      if (!isNaN(th) && th > 0) withdrawThreshold.value = th
    }
  } catch (e) {}
}

async function refresh(skipEvents) {
  loading.value = true
  try {
    await loadCfg()
    profile.value = await loadMasterProfile()
    await refreshRadar()
    if (!skipEvents) {
      const events = (p2.value && p2.value.events) || []
      if (events.length) {
        const sig = JSON.stringify(events.map((e) => e.type + ':' + (e.title || '')))
        if (sig !== lastEventsSig) {
          lastEventsSig = sig
          showPhase2Events(events)
        }
      }
    }
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '加载失败', icon: 'none' })
  } finally {
    loading.value = false
  }
}

function startPoll() {
  stopPoll()
  const ck = checkin.value
  if (!(ck.checked_today && ck.today_mode === 'violent' && !ck.bonus_unlocked)) return
  pollTimer = setInterval(async () => {
    try {
      const p = await loadMasterProfile()
      profile.value = p
      const events = (p && p.phase2 && p.phase2.events) || []
      if (events.length) showPhase2Events(events)
    } catch (e) {}
  }, 20000)
}

function stopPoll() {
  if (pollTimer) {
    clearInterval(pollTimer)
    pollTimer = null
  }
}

onShow(async () => {
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  await refresh()
  startPoll()
})

onHide(() => stopPoll())
</script>

<style scoped>
/* 仅保留最小壳；视觉细节走 master-uni-adapter.css，避免 scoped 拆坏 888 选择器 */
</style>
