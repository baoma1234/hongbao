<template>
  <view class="page">
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
          <text class="m-act" v-if="canManageMember(m)">管理</text>
        </view>
        <view v-if="!members.length" class="empty">暂无成员</view>
        <text class="hint tip" v-if="canEdit && members.length">长按或点「管理」可禁言 / 移出</text>
      </view>
    </view>

    <view class="card danger" v-if="myRole < 3">
      <button class="btn leave" @click="onLeave">退出群聊</button>
    </view>
    <view class="card" v-else>
      <text class="hint">群主不能直接退群，请先转让群主（请用 /888 完成）</text>
    </view>

    <!-- 成员操作 -->
    <view class="mask" v-if="memberSheet" @click="closeMemberSheets">
      <view class="sheet" @click.stop>
        <view class="sheet-title">{{ memberTargetName }}</view>
        <button class="sheet-btn" @click="openMuteSheet">禁言</button>
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
  </view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { onLoad, onShow } from '@dcloudio/uni-app'
import { fetchProfile, getToken } from '../../utils/auth.js'
import { avatarLetter } from '../../utils/chat.js'
import {
  fetchGroupInfo,
  fetchGroupMembers,
  imConnect,
  kickGroupMember,
  leaveGroup,
  muteGroupMember,
  setGroupMuteAll,
  updateGroup,
} from '../../utils/im.js'

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
  // 群主可管所有非自己；管理员只能管普通成员
  if (mr >= 3) return tr < 3
  if (mr >= 2) return tr < 2
  return false
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
  if (canManageMember(m)) {
    memberTarget.value = m
    memberSheet.value = true
    muteSheet.value = false
  }
}

function onMemberLongPress(m) {
  if (!canManageMember(m)) return
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
  padding: 24rpx;
  box-sizing: border-box;
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
  font-size: 28rpx;
  color: #2a1f18;
  border-bottom: 1px solid rgba(224, 122, 34, 0.12);
  margin-bottom: 12rpx;
}
.btn {
  margin-top: 12rpx;
  background: linear-gradient(#fff, #fff) padding-box,
    linear-gradient(145deg, #ffe9b0, #f0b04a, #e07a22) border-box;
  border: 1.5px solid transparent;
  color: #3d2e22;
  font-weight: 700;
  border-radius: 12rpx;
}
.btn.leave {
  background: #fff;
  border: 1.5px solid #c61114;
  color: #c61114;
}
.member {
  display: flex;
  gap: 16rpx;
  align-items: center;
  padding: 14rpx 0;
  border-bottom: 1px solid rgba(40, 20, 10, 0.05);
}
.mav {
  width: 64rpx;
  height: 64rpx;
  border-radius: 14rpx;
  background: #fff5f3;
  color: #c61114;
  font-weight: 800;
  display: flex;
  align-items: center;
  justify-content: center;
}
.mmain {
  flex: 1;
  display: flex;
  align-items: center;
  gap: 10rpx;
  min-width: 0;
}
.mnick {
  font-size: 28rpx;
  color: #2a1f18;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.mrole, .mmute {
  flex-shrink: 0;
  font-size: 20rpx;
  padding: 2rpx 10rpx;
  border-radius: 999rpx;
  font-weight: 700;
}
.mrole { background: #c61114; color: #fff; }
.mmute { background: #eee; color: #8a7a6e; }
.m-act {
  flex-shrink: 0;
  font-size: 22rpx;
  color: #c61114;
  font-weight: 700;
  padding: 8rpx 12rpx;
}
.empty, .hint {
  color: #9a8574;
  font-size: 24rpx;
  padding: 8rpx 0;
}
.hint.tip { margin-top: 8rpx; }
.danger { padding-bottom: 8rpx; }
.mask {
  position: fixed;
  inset: 0;
  z-index: 100;
  background: rgba(20, 10, 5, 0.4);
  display: flex;
  align-items: flex-end;
  justify-content: center;
}
.sheet {
  width: 100%;
  background: #fff;
  border-radius: 24rpx 24rpx 0 0;
  padding: 28rpx 24rpx 48rpx;
  box-sizing: border-box;
}
.sheet-title {
  text-align: center;
  font-size: 30rpx;
  font-weight: 800;
  color: #2a1f18;
  margin-bottom: 16rpx;
}
.sheet-btn {
  margin-top: 12rpx;
  background: #fff;
  border: 1.5px solid rgba(224, 122, 34, 0.25);
  color: #3d2e22;
  font-weight: 700;
  border-radius: 12rpx;
}
.sheet-btn.danger {
  border-color: #c61114;
  color: #c61114;
}
.sheet-btn.cancel {
  border-color: transparent;
  color: #9a8574;
  margin-top: 20rpx;
}
</style>
