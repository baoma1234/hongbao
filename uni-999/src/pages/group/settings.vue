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
        <view v-for="m in members" :key="m.user_id" class="member">
          <view class="mav">{{ avatarLetter(m.nickname) }}</view>
          <view class="mmain">
            <text class="mnick">{{ m.nickname || ('ID' + m.user_id) }}</text>
            <text class="mrole" v-if="roleLabel(m.role)">{{ roleLabel(m.role) }}</text>
            <text class="mmute" v-if="m.is_muted">禁言中</text>
          </view>
        </view>
        <view v-if="!members.length" class="empty">暂无成员</view>
      </view>
    </view>

    <view class="card danger" v-if="myRole < 3">
      <button class="btn leave" @click="onLeave">退出群聊</button>
    </view>
    <view class="card" v-else>
      <text class="hint">群主不能直接退群，请先转让群主（请用 /888 完成）</text>
    </view>
  </view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { onLoad, onShow } from '@dcloudio/uni-app'
import { avatarLetter } from '../../utils/chat.js'
import { getToken } from '../../utils/auth.js'
import {
  fetchGroupInfo,
  fetchGroupMembers,
  imConnect,
  leaveGroup,
  setGroupMuteAll,
  updateGroup,
} from '../../utils/im.js'

const groupId = ref(0)
const group = ref({})
const myRole = ref(0)
const muteAll = ref(false)
const memberCount = ref(0)
const memberHidden = ref(false)
const members = ref([])
const loading = ref(false)

const groupName = computed(() => group.value.name || ('群 ' + groupId.value))
const notice = computed(() => String(group.value.notice || '').trim())
const letter = computed(() => avatarLetter(groupName.value))
const canEdit = computed(() => (myRole.value | 0) >= 2)
const roleText = computed(() => roleLabel(myRole.value))

function roleLabel(role) {
  const r = role | 0
  if (r === 3) return '群主'
  if (r === 2) return '管理员'
  return ''
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
    const packet = await fetchGroupInfo(groupId.value)
    const data = (packet && packet.data) || packet || {}
    group.value = data.group || {}
    myRole.value = data.my_role | 0
    muteAll.value = !!data.mute_all
    memberCount.value = data.member_count | 0
    memberHidden.value = !!data.member_list_hidden
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
.empty, .hint {
  color: #9a8574;
  font-size: 24rpx;
  padding: 8rpx 0;
}
.danger { padding-bottom: 8rpx; }
</style>
