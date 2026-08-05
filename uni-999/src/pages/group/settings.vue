<template>
  <view class="page chat-friend-page">
    <view class="chat-hero-hd">
      <view class="chat-hero-back" @click="goBack">
        <svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true">
          <path fill="currentColor" d="M15.41 16.59L10.83 12l4.58-4.59L14 6l-6 6 6 6 1.41-1.41z" />
        </svg>
      </view>
      <view class="chat-hero-title">群设置</view>
      <view class="chat-hero-spacer" />
    </view>

    <view class="head card">
      <view class="av">{{ letter }}</view>
      <view class="head-main">
        <text class="name">{{ groupName }}</text>
        <text class="meta">{{ memberCount }} 人{{ roleText ? ' · ' + roleText : '' }}{{ muteAll ? ' · 全员禁言' : '' }}</text>
      </view>
    </view>

    <view class="card" v-if="notice">
      <text class="lab">群公告</text>
      <text class="notice">{{ notice }}</text>
    </view>

    <view class="card" v-if="canEdit">
      <text class="lab">管理</text>
      <view class="row-switch" @click="toggleMuteAll">
        <text>全员禁言</text>
        <switch :checked="muteAll" color="#c61114" @change="onMuteSwitch" @click.stop />
      </view>
      <button class="btn" @click="editName">修改群名</button>
      <button class="btn" @click="editNotice">修改公告</button>
      <button class="btn" @click="openAddMembers">添加群成员</button>
    </view>

    <view class="card" v-if="canEdit">
      <text class="lab">禁止模式（普通成员）</text>
      <view v-for="item in forbidItems" :key="item.key" class="row-switch">
        <text>{{ item.label }}</text>
        <switch
          :checked="!!forbidFlags[item.key]"
          color="#c61114"
          :data-key="item.key"
          @change="onForbidSwitchEvent"
        />
      </view>
      <button class="btn" :disabled="forbidSaving" @click="saveForbid">
        {{ forbidSaving ? '保存中…' : '保存禁止设置' }}
      </button>
    </view>

    <view class="card">
      <view class="lab-row">
        <text class="lab">群成员</text>
        <text class="hint" v-if="memberHidden">成员列表已隐藏</text>
      </view>
      <view v-if="!memberHidden">
        <view
          v-for="m in members"
          :key="m.user_id"
          class="member"
          @longpress="onMemberLongPress(m)"
          @click="onMemberTap(m)"
        >
          <view class="mav">{{ avatarLetter(m.nickname) }}</view>
          <view class="mmain">
            <text class="mnick">{{ m.nickname || ('ID' + m.user_id) }}</text>
            <text class="mrole" v-if="roleLabel(m.role)">{{ roleLabel(m.role) }}</text>
            <text class="mmute" v-if="m.is_muted">禁言中</text>
          </view>
          <text class="m-act" v-if="canManageMember(m) || canSetAdmin(m)">管理</text>
        </view>
        <view v-if="!members.length" class="empty">暂无成员</view>
        <text class="hint tip" v-if="canEdit && members.length">长按或点「管理」可禁言 / 移出 / 设管理</text>
      </view>
    </view>

    <view class="card danger" v-if="myRole < 3">
      <button class="btn leave" @click="onLeave">退出群聊</button>
    </view>
    <view class="card" v-else>
      <text class="hint">群主不能直接退群，请先转让群主</text>
    </view>

    <!-- 成员操作 -->
    <view class="mask" v-if="memberSheet" @click="closeMemberSheets">
      <view class="sheet" @click.stop>
        <view class="sheet-title">{{ memberTargetName }}</view>
        <button class="sheet-btn" @click="openMuteSheet">禁言</button>
        <button
          v-if="canSetAdmin(memberTarget)"
          class="sheet-btn"
          @click="toggleAdminFromSheet"
        >
          {{ adminActionLabel }}
        </button>
        <button class="sheet-btn danger" @click="confirmKick">移出群组</button>
        <button class="sheet-btn cancel" @click="closeMemberSheets">取消</button>
      </view>
    </view>
    <view class="mask" v-if="muteSheet" @click="closeMemberSheets">
      <view class="sheet" @click.stop>
        <view class="sheet-title">选择禁言时长</view>
        <button
          v-for="opt in muteOptions"
          :key="opt.s"
          class="sheet-btn"
          @click="doMute(opt.s)"
        >{{ opt.n }}</button>
        <button class="sheet-btn cancel" @click="closeMemberSheets">关闭</button>
      </view>
    </view>

    <!-- 添加成员 -->
    <view class="mask" v-if="addSheet" @click="closeAddSheet">
      <view class="sheet add-sheet" @click.stop>
        <view class="sheet-title">添加群成员</view>
        <scroll-view scroll-y class="cand-scroll">
          <view
            v-for="u in candidates"
            :key="u.user_id"
            class="cand-row"
            @click="toggleCandidate(u)"
          >
            <view class="cand-check" :class="{ on: selectedIds[u.user_id] }">
              {{ selectedIds[u.user_id] ? '✓' : '' }}
            </view>
            <view class="mav">{{ avatarLetter(u.nickname) }}</view>
            <text class="mnick">{{ u.nickname || ('ID' + u.user_id) }}</text>
          </view>
          <view v-if="!candidates.length && !candLoading" class="empty">暂无可添加好友</view>
          <view v-if="candLoading" class="empty">加载中…</view>
        </scroll-view>
        <button class="sheet-btn primary" :disabled="addSaving" @click="confirmAddMembers">
          {{ addSaving ? '添加中…' : ('添加' + (selectedCount ? ' (' + selectedCount + ')' : '')) }}
        </button>
        <button class="sheet-btn cancel" @click="closeAddSheet">取消</button>
      </view>
    </view>
  </view>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import { onLoad, onShow } from '@dcloudio/uni-app'
import { fetchProfile, getToken } from '../../utils/auth.js'
import { avatarLetter } from '../../utils/chat.js'
import {
  addGroupMembers,
  fetchGroupInfo,
  fetchGroupMembers,
  groupCandidates,
  imConnect,
  kickGroupMember,
  leaveGroup,
  muteGroupMember,
  setGroupAdmin,
  setGroupForbid,
  setGroupMuteAll,
  updateGroup,
} from '../../utils/im.js'
import '../../styles/chat.bundle.css'
import '../../styles/chat-uni-adapter.css'
import '../../styles/friend-uni-adapter.css'

function goBack() {
  uni.navigateBack({ fail: () => uni.switchTab({ url: '/pages/messages/messages' }) })
}

const groupId = ref(0)
const group = ref({})
const myRole = ref(0)
const myId = ref(0)
const muteAll = ref(false)
const memberCount = ref(0)
const memberHidden = ref(false)
const members = ref([])
const loading = ref(false)
const memberSheet = ref(false)
const muteSheet = ref(false)
const memberTarget = ref(null)
const forbidFlags = reactive({ text: false, image: false, emoji: false, video: false, rp: false })
const forbidSaving = ref(false)
const addSheet = ref(false)
const candidates = ref([])
const candLoading = ref(false)
const addSaving = ref(false)
const selectedIds = reactive({})

const forbidItems = [
  { key: 'text', label: '禁止发言' },
  { key: 'image', label: '禁止发图' },
  { key: 'emoji', label: '禁止发表情' },
  { key: 'video', label: '禁止发视频' },
  { key: 'rp', label: '禁止发红包' },
]

const muteOptions = [
  { s: 600, n: '10 分钟' },
  { s: 3600, n: '1 小时' },
  { s: 86400, n: '24 小时' },
  { s: 0, n: '取消禁言' },
]

const groupName = computed(() => group.value.name || ('群 ' + groupId.value))
const notice = computed(() => String(group.value.notice || '').trim())
const letter = computed(() => avatarLetter(groupName.value))
const canEdit = computed(() => (myRole.value | 0) >= 2)
const roleText = computed(() => roleLabel(myRole.value))
const memberTargetName = computed(() => {
  const m = memberTarget.value
  if (!m) return '成员'
  return m.nickname || ('ID' + m.user_id)
})
const selectedCount = computed(() => Object.keys(selectedIds).filter((k) => selectedIds[k]).length)
const adminActionLabel = computed(() => {
  const m = memberTarget.value
  if (m && (m.role | 0) === 2) return '取消管理员'
  return '设为管理员'
})

function roleLabel(role) {
  const r = role | 0
  if (r === 3) return '群主'
  if (r === 2) return '管理员'
  return ''
}

function canManageMember(m) {
  if (!canEdit.value || !m) return false
  const tid = m.user_id | 0
  if (!tid || tid === (myId.value | 0)) return false
  const tr = m.role | 0
  const mr = myRole.value | 0
  if (mr >= 3) return tr < 3
  if (mr >= 2) return tr < 2
  return false
}

function canSetAdmin(m) {
  if (!m || (myRole.value | 0) < 3) return false
  const tid = m.user_id | 0
  if (!tid || tid === (myId.value | 0)) return false
  return (m.role | 0) < 3
}

function applyForbid(fm) {
  const src = fm || {}
  forbidItems.forEach((it) => {
    forbidFlags[it.key] = !!(src[it.key])
  })
}

function promptText(title, cur) {
  return new Promise((resolve) => {
    // #ifdef H5
    if (typeof window !== 'undefined' && typeof window.prompt === 'function') {
      const next = window.prompt(title, cur || '')
      resolve(next === null ? null : String(next))
      return
    }
    // #endif
    uni.showModal({
      title,
      editable: true,
      content: cur || '',
      success: (res) => {
        if (!res.confirm) resolve(null)
        else resolve(String(res.content || ''))
      },
      fail: () => resolve(null),
    })
  })
}

async function loadInfo() {
  if (!groupId.value) return
  loading.value = true
  try {
    await imConnect()
    if (!myId.value) {
      try {
        const p = await fetchProfile()
        myId.value = (p && (p.user_id || p.id)) | 0
      } catch (e0) {}
    }
    const packet = await fetchGroupInfo(groupId.value)
    const data = (packet && packet.data) || packet || {}
    group.value = data.group || {}
    myRole.value = data.my_role | 0
    muteAll.value = !!data.mute_all
    memberCount.value = data.member_count | 0
    memberHidden.value = !!data.member_list_hidden
    if (data.my_user_id) myId.value = data.my_user_id | 0
    applyForbid(data.forbid_modes || (data.policy && data.policy.forbid_modes) || {})
    if (group.value.name) {
      uni.setNavigationBarTitle({ title: group.value.name })
    }
    if (!memberHidden.value) {
      const mp = await fetchGroupMembers(groupId.value)
      const md = (mp && mp.data) || mp || {}
      members.value = md.list || []
      if (md.member_count != null) memberCount.value = md.member_count | 0
      if (md.my_role != null) myRole.value = md.my_role | 0
      if (md.mute_all != null) muteAll.value = !!md.mute_all
    } else {
      members.value = []
    }
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '加载失败', icon: 'none' })
  } finally {
    loading.value = false
  }
}

function closeMemberSheets() {
  memberSheet.value = false
  muteSheet.value = false
  memberTarget.value = null
}

function onMemberTap(m) {
  if (canManageMember(m) || canSetAdmin(m)) {
    memberTarget.value = m
    memberSheet.value = true
    muteSheet.value = false
  }
}

function onMemberLongPress(m) {
  if (!canManageMember(m) && !canSetAdmin(m)) return
  memberTarget.value = m
  memberSheet.value = true
  muteSheet.value = false
}

function openMuteSheet() {
  memberSheet.value = false
  muteSheet.value = true
}

async function doMute(seconds) {
  const m = memberTarget.value
  if (!m) return
  try {
    await muteGroupMember(groupId.value, m.user_id, seconds)
    uni.showToast({ title: seconds > 0 ? '已禁言' : '已取消禁言', icon: 'none' })
    closeMemberSheets()
    await loadInfo()
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '操作失败', icon: 'none' })
  }
}

async function doSetAdmin(isAdmin) {
  const m = memberTarget.value
  if (!m || !canSetAdmin(m)) return
  try {
    await setGroupAdmin(groupId.value, m.user_id, !!isAdmin)
    uni.showToast({ title: isAdmin ? '已设为管理员' : '已取消管理员', icon: 'none' })
    closeMemberSheets()
    await loadInfo()
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '操作失败', icon: 'none' })
  }
}

function toggleAdminFromSheet() {
  const m = memberTarget.value
  if (!m) return
  doSetAdmin((m.role | 0) !== 2)
}

function confirmKick() {
  const m = memberTarget.value
  if (!m) return
  uni.showModal({
    title: '移出群组',
    content: '确定将「' + memberTargetName.value + '」移出群组？',
    success: async (res) => {
      if (!res.confirm) return
      try {
        await kickGroupMember(groupId.value, m.user_id)
        uni.showToast({ title: '已移出', icon: 'none' })
        closeMemberSheets()
        await loadInfo()
      } catch (e) {
        uni.showToast({ title: (e && e.message) || '操作失败', icon: 'none' })
      }
    },
  })
}

async function applyMute(enabled) {
  try {
    await setGroupMuteAll(groupId.value, enabled)
    muteAll.value = !!enabled
    uni.showToast({ title: enabled ? '已开启全员禁言' : '已关闭全员禁言', icon: 'none' })
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '操作失败', icon: 'none' })
    await loadInfo()
  }
}

function onMuteSwitch(e) {
  const on = !!(e && e.detail && e.detail.value)
  applyMute(on)
}

function toggleMuteAll() {
  applyMute(!muteAll.value)
}

function onForbidSwitch(key, e) {
  forbidFlags[key] = !!(e && e.detail && e.detail.value)
}

function onForbidSwitchEvent(e) {
  const key =
    (e && e.currentTarget && e.currentTarget.dataset && e.currentTarget.dataset.key) ||
    (e && e.target && e.target.dataset && e.target.dataset.key) ||
    ''
  if (!key) return
  onForbidSwitch(key, e)
}

async function saveForbid() {
  if (forbidSaving.value) return
  forbidSaving.value = true
  try {
    const modes = {}
    forbidItems.forEach((it) => {
      modes[it.key] = forbidFlags[it.key] ? 1 : 0
    })
    const packet = await setGroupForbid(groupId.value, modes)
    const data = (packet && packet.data) || packet || {}
    applyForbid(data.forbid_modes || modes)
    uni.showToast({ title: '禁止设置已更新', icon: 'success' })
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '保存失败', icon: 'none' })
    await loadInfo()
  } finally {
    forbidSaving.value = false
  }
}

function closeAddSheet() {
  addSheet.value = false
  candidates.value = []
  Object.keys(selectedIds).forEach((k) => {
    delete selectedIds[k]
  })
}

async function openAddMembers() {
  addSheet.value = true
  candLoading.value = true
  Object.keys(selectedIds).forEach((k) => {
    delete selectedIds[k]
  })
  try {
    const packet = await groupCandidates(groupId.value)
    const data = (packet && packet.data) || packet || {}
    candidates.value = data.list || data.candidates || []
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '加载好友失败', icon: 'none' })
    candidates.value = []
  } finally {
    candLoading.value = false
  }
}

function toggleCandidate(u) {
  const id = u.user_id | 0
  if (!id) return
  if (selectedIds[id]) delete selectedIds[id]
  else selectedIds[id] = true
}

async function confirmAddMembers() {
  const ids = Object.keys(selectedIds)
    .filter((k) => selectedIds[k])
    .map((k) => parseInt(k, 10))
    .filter(Boolean)
  if (!ids.length) {
    uni.showToast({ title: '请选择好友', icon: 'none' })
    return
  }
  addSaving.value = true
  try {
    await addGroupMembers(groupId.value, ids)
    uni.showToast({ title: '已添加', icon: 'success' })
    closeAddSheet()
    await loadInfo()
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '添加失败', icon: 'none' })
  } finally {
    addSaving.value = false
  }
}

async function editName() {
  const next = await promptText('修改群名', group.value.name || '')
  if (next === null) return
  const name = String(next).trim().slice(0, 64)
  if (!name) {
    uni.showToast({ title: '群名不能为空', icon: 'none' })
    return
  }
  try {
    await updateGroup(groupId.value, { name })
    uni.showToast({ title: '已更新', icon: 'none' })
    await loadInfo()
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '更新失败', icon: 'none' })
  }
}

async function editNotice() {
  const next = await promptText('修改群公告', group.value.notice || '')
  if (next === null) return
  try {
    await updateGroup(groupId.value, { notice: String(next).trim() })
    uni.showToast({ title: '已更新', icon: 'none' })
    await loadInfo()
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '更新失败', icon: 'none' })
  }
}

function onLeave() {
  uni.showModal({
    title: '退出群聊',
    content: '确定退出「' + groupName.value + '」？',
    success: async (res) => {
      if (!res.confirm) return
      try {
        await leaveGroup(groupId.value)
        uni.showToast({ title: '已退出', icon: 'none' })
        setTimeout(() => {
          uni.switchTab({ url: '/pages/messages/messages' })
        }, 400)
      } catch (e) {
        uni.showToast({ title: (e && e.message) || '退出失败', icon: 'none' })
      }
    },
  })
}

onLoad((query) => {
  if (!getToken()) {
    uni.reLaunch({ url: '/pages/login/login' })
    return
  }
  groupId.value = parseInt(query.group || query.id || '0', 10) || 0
})

onShow(() => {
  if (groupId.value) loadInfo()
})
</script>

<style scoped>
.page {
  min-height: 100vh;
  background: #f6f1ea;
  padding: 0 0 40rpx;
  box-sizing: border-box;
}
.page > .card,
.page > .head {
  margin-left: 24rpx;
  margin-right: 24rpx;
}
.card {
  background: #fff;
  border-radius: 16rpx;
  padding: 24rpx;
  margin-bottom: 20rpx;
  box-shadow: 0 4rpx 12rpx rgba(40, 20, 10, 0.04);
}
.head {
  display: flex;
  gap: 20rpx;
  align-items: center;
}
.av {
  width: 96rpx;
  height: 96rpx;
  border-radius: 20rpx;
  background: linear-gradient(160deg, #fff8ee, #ffe8cc);
  color: #b8751a;
  font-weight: 800;
  font-size: 40rpx;
  display: flex;
  align-items: center;
  justify-content: center;
}
.head-main { flex: 1; min-width: 0; }
.name {
  display: block;
  font-size: 34rpx;
  font-weight: 800;
  color: #2a1f18;
}
.meta {
  display: block;
  margin-top: 8rpx;
  font-size: 24rpx;
  color: #9a8574;
}
.lab {
  display: block;
  font-size: 24rpx;
  color: #8a7a6e;
  font-weight: 700;
  margin-bottom: 12rpx;
}
.lab-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12rpx;
}
.notice {
  font-size: 28rpx;
  color: #2a1f18;
  line-height: 1.5;
  white-space: pre-wrap;
}
.row-switch {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 16rpx 0;
  border-bottom: 1px solid rgba(180, 140, 100, 0.12);
  font-size: 28rpx;
  color: #2a1f18;
}
.btn {
  margin-top: 16rpx;
  background: #fff8f0;
  color: #8a4f1f;
  border: 1.5px solid #f0b04a;
  border-radius: 12rpx;
  font-weight: 700;
  font-size: 28rpx;
}
.btn[disabled] { opacity: 0.5; }
.btn.leave {
  background: #fff;
  color: #c61114;
  border-color: rgba(198, 17, 20, 0.35);
}
.member {
  display: flex;
  align-items: center;
  gap: 16rpx;
  padding: 16rpx 0;
  border-bottom: 1px solid rgba(180, 140, 100, 0.1);
}
.mav {
  width: 72rpx;
  height: 72rpx;
  border-radius: 50%;
  background: linear-gradient(135deg, #ffe082, #ffb300);
  color: #8a4b00;
  font-weight: 800;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.mmain { flex: 1; min-width: 0; }
.mnick {
  display: block;
  font-size: 28rpx;
  font-weight: 700;
  color: #2a1f18;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.mrole, .mmute {
  display: inline-block;
  margin-right: 8rpx;
  font-size: 20rpx;
  color: #c61114;
}
.m-act {
  font-size: 24rpx;
  color: #b8751a;
  font-weight: 700;
}
.empty {
  text-align: center;
  color: #9a8574;
  padding: 24rpx;
  font-size: 24rpx;
}
.hint {
  font-size: 22rpx;
  color: #9a8574;
}
.hint.tip { display: block; margin-top: 12rpx; }
.card.danger { background: transparent; box-shadow: none; }
.mask {
  position: fixed;
  inset: 0;
  z-index: 20000;
  background: rgba(20, 12, 8, 0.45);
  display: flex;
  align-items: flex-end;
  justify-content: center;
}
.sheet {
  width: 100%;
  max-width: 720rpx;
  background: #fffaf5;
  border-radius: 24rpx 24rpx 0 0;
  padding: 28rpx 28rpx 40rpx;
  box-sizing: border-box;
}
.sheet-title {
  font-size: 32rpx;
  font-weight: 800;
  text-align: center;
  margin-bottom: 20rpx;
}
.sheet-btn {
  width: 100%;
  margin-top: 12rpx;
  background: #fff;
  border: 1px solid rgba(180, 140, 100, 0.25);
  border-radius: 12rpx;
  font-weight: 700;
  color: #3d2e22;
}
.sheet-btn.danger { color: #c61114; }
.sheet-btn.cancel { color: #6a5648; background: #f6f1ea; }
.sheet-btn.primary {
  background: linear-gradient(135deg, #ffe082, #ffb300);
  color: #8a4b00;
  border: none;
}
.add-sheet { max-height: 78vh; }
.cand-scroll { max-height: 52vh; margin-bottom: 12rpx; }
.cand-row {
  display: flex;
  align-items: center;
  gap: 16rpx;
  padding: 14rpx 0;
  border-bottom: 1px solid rgba(180, 140, 100, 0.1);
}
.cand-check {
  width: 40rpx;
  height: 40rpx;
  border-radius: 8rpx;
  border: 2px solid #f0b04a;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 22rpx;
  color: #fff;
  flex-shrink: 0;
}
.cand-check.on {
  background: #c61114;
  border-color: #c61114;
}
</style>
