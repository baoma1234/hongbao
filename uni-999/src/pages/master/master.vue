<template>
  <view class="hb-page" :key="locale">
    <TopBar />
    <view class="hero">
      <text class="title">{{ t('page_hero_master_title') || '团长专属二期大厅' }}</text>
      <text class="sub">{{ t('page_hero_master_sub') || '荣誉天梯 · 星火暴击 · 战队催活' }}</text>
    </view>

    <view v-if="loading" class="card muted">{{ t('loading_generic') || '加载中…' }}</view>

    <template v-else>
      <!-- 未解锁 -->
      <view class="lock card" v-if="p2.enabled && !master">
        <text class="lock-ico">🔒</text>
        <text class="lock-t">{{ t('master_lock_title') || '团长通道待解锁' }}</text>
        <text class="lock-d">{{ t('master_lock_desc') || '完成首笔 VIP 福利核销后开放天梯、签到与雷达。' }}</text>
        <button class="btn" @click="goHome">{{ t('master_lock_btn') || '前往大厅' }}</button>
      </view>

      <view class="card muted" v-if="!p2.enabled">团长二期未开启</view>

      <!-- 天梯 -->
      <view class="honor card" v-if="p2.enabled" @click="onCopyPromo">
        <view class="honor-head">
          <text class="honor-title">{{ t('phase2_honor_title') || '团长天梯权益' }}</text>
          <text class="honor-tap">点此复制推广密令</text>
        </view>
        <view class="prog-meta">
          <text>{{ subCountText }}</text>
          <text>{{ Math.round(progressPct) }}%</text>
        </view>
        <view class="prog-track">
          <view class="prog-fill" :style="{ width: progressPct + '%' }" />
        </view>
        <view class="tier" v-for="n in tiers" :key="n.id" :class="n.state">
          <view class="tier-main">
            <view class="tier-info">
              <text class="tier-name">{{ n.name }}</text>
              <text class="tier-req">{{ t('phase2_honor_need_people', { n: n.threshold }) || ('达标需 ' + n.threshold + ' 人') }}</text>
            </view>
            <text class="tier-badge">{{ n.badge }}</text>
          </view>
          <view class="tier-rewards">
            <view class="rw">
              <text class="rw-lab">{{ t('phase2_honor_col_shares') || '解锁股份' }}</text>
              <text class="rw-val">{{ n.rightsText }}股</text>
              <text class="rw-sub">≈ ¥{{ n.rightsVal }}</text>
            </view>
            <view class="rw">
              <text class="rw-lab">{{ t('phase2_honor_col_cash') || '现金奖励' }}</text>
              <text class="rw-val">{{ n.balance > 0 ? ('¥' + n.balance) : (t('phase2_honor_cash_none') || '暂无现金') }}</text>
            </view>
          </view>
        </view>
        <text class="honor-hint">{{ honorHint }}</text>
      </view>

      <!-- 签到 + 雷达（团长） -->
      <template v-if="master">
        <view class="checkin card">
          <view class="ck-head">
            <text class="ck-title">7天星火暴击</text>
            <text class="ck-streak">{{ streakText }}</text>
          </view>
          <text class="ck-ledger">{{ ledgerText }}</text>
          <view class="prog-track thin">
            <view class="prog-fill fire" :style="{ width: streakPct + '%' }" />
          </view>
          <text v-if="frozenText" class="frozen">{{ frozenText }}</text>
          <view class="violent-row" @click="violent = !violent">
            <switch :checked="violent" color="#c61114" @change="onViolent" @click.stop />
            <text class="violent-lab">{{ violentLabel }}</text>
          </view>
          <view v-if="pendingBonus > 0" class="pending">
            {{ checkin.bonus_unlocked
              ? (t('phase2_checkin_pending_ok', { amount: pendingBonus.toFixed(2) }) || ('对账成功，额外 ¥' + pendingBonus.toFixed(2) + ' 已到账'))
              : (t('phase2_checkin_pending', { amount: pendingBonus.toFixed(2) }) || ('今日暴力对账箱：¥' + pendingBonus.toFixed(2) + '（等待新客…）')) }}
          </view>
          <button class="btn-ck" :class="{ done: checkedToday }" :disabled="busy" @click="onCheckin">
            {{ checkinBtnText }}
          </button>
          <text class="ck-tip">{{ checkinTip }}</text>
        </view>

        <view class="radar card">
          <text class="radar-title">{{ t('phase2_radar_title') || '战队催活雷达' }}</text>
          <view v-if="!radar.length" class="empty">{{ t('phase2_radar_empty') || '暂无直属下线' }}</view>
          <view v-for="row in radar" :key="row.user_id || row.mobile_mask" class="radar-row">
            <view class="radar-main">
              <text class="radar-mobile">{{ row.mobile_mask || ('ID' + row.user_id) }}</text>
              <text class="radar-prog">
                {{ row.withdrawn
                  ? (t('phase2_radar_done') || '已达标')
                  : (t('phase2_radar_progress', { balance: Number(row.balance || row.hongbao || 0).toFixed(2), threshold: row.threshold || 50 }) || (Number(row.balance || 0).toFixed(2) + ' / ' + (row.threshold || 50))) }}
              </text>
              <view class="mini-track">
                <view class="mini-fill" :style="{ width: Math.min(100, Number(row.progress) || 0) + '%' }" />
              </view>
            </view>
            <button
              v-if="row.can_urge !== false && !row.withdrawn"
              class="urge"
              size="mini"
              @click="onUrge(row)"
            >{{ t('phase2_radar_urge') || '催促' }}</button>
          </view>
        </view>
      </template>
    </template>
    <BottomTabBar active="master" />
  </view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { onShow, onHide } from '@dcloudio/uni-app'
import TopBar from '../../components/TopBar.vue'
import BottomTabBar from '../../components/BottomTabBar.vue'
import { getToken } from '../../utils/auth.js'
import { localeState, t } from '../../utils/i18n.js'
import {
  copySharePromo,
  copyText,
  doCheckin,
  honorTier,
  isMaster,
  loadMasterProfile,
  loadTeamRadar,
  phase2Of,
  urgeCopy,
} from '../../utils/master.js'

const locale = localeState()
const loading = ref(true)
const profile = ref({})
const radar = ref([])
const violent = ref(true)
const busy = ref(false)
let pollTimer = null

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
  return t('phase2_checkin_ledger_short', { jackpot: '175', loss: '140' }) ||
    t('phase2_checkin_ledger', { jackpot: '175', loss: '140', streak: streak.value }) ||
    '满7天5倍核爆总池，保底 ¥175 · 断签降级损失 ¥140'
})
const frozenText = computed(() => {
  void locale.value
  if (!p2.value.streak_frozen) return ''
  const need = Math.max(0, 2 - (p2.value.today_invite_count | 0))
  if (need > 0) return t('phase2_checkin_frozen', { need }) || ('断签冻结：今日再拉 ' + need + ' 人可复活')
  return t('phase2_checkin_revive_ready') || '今日已拉满 2 人，暴击资格将自动复活'
})
const pendingBonus = computed(() => Number(checkin.value.pending_bonus) || 0)
const checkedToday = computed(() => !!checkin.value.checked_today)
const violentLabel = computed(() => {
  void locale.value
  return t('phase2_checkin_toggle', { amount: '5.00' }) || '激活【5倍暴力分享签到】（今日最高 ¥5.00）'
})
const checkinBtnText = computed(() => {
  void locale.value
  if (checkedToday.value) {
    const mode =
      checkin.value.today_mode === 'violent'
        ? t('phase2_checkin_mode_violent') || '暴力'
        : t('phase2_checkin_mode_normal') || '普通'
    return t('phase2_checkin_done_btn', { mode }) || ('今日已签到(' + mode + ') · 再复制推广')
  }
  return violent.value
    ? t('phase2_checkin_violent_btn_short') || '立即执行【暴力分享签到】'
    : t('phase2_checkin_normal_btn_short') || '仅执行【普通打卡】'
})
const checkinTip = computed(() => {
  void locale.value
  if (checkedToday.value) return ''
  return violent.value
    ? t('phase2_checkin_violent_tip') || '一键复制今日密令 · 锁定制胜7天全勤'
    : t('phase2_checkin_normal_tip') || '今日仅得 1 元 · 放弃 7 天大奖资格'
})

function onViolent(e) {
  violent.value = !!(e && e.detail && e.detail.value)
}

function goHome() {
  uni.switchTab({ url: '/pages/home/home' })
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

async function onCheckin() {
  if (checkedToday.value) {
    await onCopyPromo()
    return
  }
  busy.value = true
  try {
    let data = await doCheckin(violent.value, false)
    if (data && data.need_confirm) {
      uni.showModal({
        title: t('phase2_confirm_violent_title') || '确认放弃暴力分享？',
        content: t('phase2_confirm_violent_msg') || '普通打卡今日仅得 ¥1，并放弃 7 天暴击资格。',
        confirmText: t('phase2_btn_persist_1') || '坚持领1元',
        cancelText: t('phase2_btn_reselect_violent') || '改选暴力',
        success: async (res) => {
          if (!res.confirm) {
            violent.value = true
            return
          }
          busy.value = true
          try {
            data = await doCheckin(false, true)
            await afterCheckin(data)
          } catch (e2) {
            uni.showToast({ title: (e2 && e2.message) || '签到失败', icon: 'none' })
          } finally {
            busy.value = false
          }
        },
      })
      return
    }
    await afterCheckin(data)
  } catch (e) {
    uni.showToast({ title: (e && e.message) || t('phase2_toast_checkin_fail') || '签到失败', icon: 'none' })
  } finally {
    busy.value = false
  }
}

async function afterCheckin(data) {
  if (data && data.share && data.share.share_text) {
    try {
      await copyText(data.share.share_text)
    } catch (e) {}
  }
  if (data && data.profile) profile.value = data.profile
  else await refresh()
  const ev = (data && data.events && data.events[0]) || null
  if (ev && (ev.title || ev.message)) {
    uni.showModal({
      title: ev.title || (t('phase2_toast_checkin_ok') || '签到成功'),
      content: String(ev.message || '').replace(/<[^>]+>/g, ''),
      showCancel: false,
    })
  } else {
    uni.showToast({ title: t('phase2_toast_checkin_ok') || '签到成功 · 密令已复制', icon: 'none' })
  }
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
    radar.value = (data && data.list) || []
  } catch (e) {
    radar.value = []
  }
}

async function refresh() {
  loading.value = true
  try {
    profile.value = await loadMasterProfile()
    await refreshRadar()
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
.hero { margin-bottom: 14px; }
.title { display: block; font-size: 22px; font-weight: 800; color: var(--text-main, #1f1714); }
.sub { display: block; margin-top: 6px; font-size: 13px; color: var(--text-muted, #8a7a6e); }
.card {
  background: var(--bg-card, #fff);
  border-radius: 16px;
  padding: 16px;
  margin-bottom: 12px;
  box-shadow: 0 4px 14px rgba(40, 20, 10, 0.05);
}
.card.muted { text-align: center; color: var(--text-muted, #9a8574); }
.lock { text-align: center; padding: 28px 18px; }
.lock-ico { display: block; font-size: 36px; }
.lock-t { display: block; margin-top: 10px; font-size: 18px; font-weight: 800; }
.lock-d { display: block; margin: 10px 0 16px; font-size: 13px; color: var(--text-muted, #8a7a6e); line-height: 1.5; }
.btn, .btn-ck {
  background: var(--primary, #c61114);
  color: #fff;
  font-weight: 800;
  border-radius: 12px;
}
.honor-head { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; }
.honor-title { font-size: 16px; font-weight: 800; color: var(--text-main, #1f1714); }
.honor-tap { font-size: 11px; color: #c45a1a; font-weight: 700; }
.prog-meta {
  display: flex; justify-content: space-between; margin: 12px 0 6px;
  font-size: 12px; color: var(--text-muted, #8a7a6e); font-weight: 700;
}
.prog-track {
  height: 8px; border-radius: 999px; background: #f0e6dc; overflow: hidden; margin-bottom: 14px;
}
.prog-track.thin { height: 6px; margin: 10px 0 12px; }
.prog-fill {
  height: 100%; border-radius: 999px;
  background: linear-gradient(90deg, #f0b04a, #e63022);
}
.prog-fill.fire { background: linear-gradient(90deg, #ff9100, #e53935); }
.tier {
  border: 1px solid rgba(224, 122, 34, 0.18);
  border-radius: 12px;
  padding: 12px;
  margin-bottom: 10px;
  background: #fffaf5;
}
.tier.reached { border-color: rgba(0, 200, 83, 0.35); background: #f3fff6; }
.tier.current { border-color: #c61114; box-shadow: 0 0 0 1px rgba(198, 17, 20, 0.12); }
.tier.locked { opacity: 0.72; }
.tier-main { display: flex; justify-content: space-between; gap: 8px; align-items: center; }
.tier-name { display: block; font-size: 15px; font-weight: 800; color: var(--text-main, #1f1714); }
.tier-req { display: block; margin-top: 4px; font-size: 12px; color: var(--text-muted, #8a7a6e); }
.tier-badge {
  flex-shrink: 0; font-size: 11px; font-weight: 800; padding: 4px 10px; border-radius: 999px;
  background: #eee; color: #6a5648;
}
.tier.reached .tier-badge { background: #c8e6c9; color: #2e7d32; }
.tier.current .tier-badge { background: #c61114; color: #fff; }
.tier-rewards { display: flex; gap: 10px; margin-top: 10px; }
.rw { flex: 1; background: #fff; border-radius: 10px; padding: 8px 10px; }
.rw-lab { display: block; font-size: 11px; color: var(--text-muted, #9a8574); }
.rw-val { display: block; margin-top: 4px; font-size: 15px; font-weight: 800; color: #c61114; }
.rw-sub { display: block; font-size: 11px; color: #9a8574; }
.honor-hint {
  display: block; margin-top: 4px; font-size: 12px; color: var(--text-muted, #8a7a6e); line-height: 1.45;
}
.ck-head { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; }
.ck-title { font-size: 16px; font-weight: 800; color: #c61114; }
.ck-streak { font-size: 12px; font-weight: 700; color: var(--text-muted, #8a7a6e); }
.ck-ledger { display: block; margin-top: 10px; font-size: 13px; color: var(--text-main, #1f1714); line-height: 1.5; }
.frozen {
  display: block; margin-bottom: 10px; padding: 8px 10px; border-radius: 10px;
  background: #fff3e0; color: #e65100; font-size: 12px; font-weight: 700;
}
.violent-row { display: flex; align-items: center; gap: 10px; margin: 8px 0 12px; }
.violent-lab { flex: 1; font-size: 13px; font-weight: 700; color: var(--text-main, #1f1714); }
.pending {
  margin-bottom: 10px; padding: 10px; border-radius: 10px; background: #fff8e1;
  font-size: 12px; color: #f57f17; font-weight: 700;
}
.btn-ck { width: 100%; padding: 12px 0; }
.btn-ck.done { background: #5d4037; }
.ck-tip { display: block; margin-top: 8px; text-align: center; font-size: 12px; color: var(--text-muted, #9a8574); }
.radar-title { display: block; font-size: 16px; font-weight: 800; margin-bottom: 12px; }
.empty { text-align: center; color: var(--text-muted, #9a8574); font-size: 13px; padding: 12px 0; }
.radar-row {
  display: flex; gap: 10px; align-items: center; padding: 12px 0;
  border-bottom: 1px solid rgba(40, 20, 10, 0.06);
}
.radar-main { flex: 1; min-width: 0; }
.radar-mobile { display: block; font-size: 14px; font-weight: 800; color: var(--text-main, #1f1714); }
.radar-prog { display: block; margin-top: 4px; font-size: 12px; color: var(--text-muted, #8a7a6e); }
.mini-track { margin-top: 6px; height: 4px; border-radius: 999px; background: #f0e6dc; overflow: hidden; }
.mini-fill { height: 100%; background: linear-gradient(90deg, #f0b04a, #c61114); }
.urge {
  margin: 0; background: #fff8f0; color: #c45a1a; border: 1px solid #f0b04a; font-weight: 700;
}
</style>
