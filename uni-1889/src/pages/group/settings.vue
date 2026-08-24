<template>
  <view class="chat-group-settings-page">
    <TopBar />
    <view class="chat-hero-hd">
      <view class="chat-hero-back" @click="goBack">
          <text class="chat-hero-back-char">‹</text>
        </view>
      <view class="chat-hero-title">群设置</view>
      <view class="chat-hero-spacer" />
    </view>

    <scroll-view scroll-y class="chat-sub-main" :show-scrollbar="true">
      <view class="chat-setting-card chat-setting-profile">
        <view
          class="chat-setting-avatar-btn"
          :class="{ 'can-edit': canEdit }"
          @click="pickGroupAvatar"
        >
          <image
            class="chat-setting-avatar-img"
            :src="groupAvatar || avatarSrc('')"
            mode="aspectFill"
          />
          <text v-if="canEdit" class="chat-setting-avatar-edit">{{ avatarBusy ? '上传中' : '更换' }}</text>
        </view>
        <view class="chat-setting-profile-main">
          <view class="chat-setting-name">{{ groupName }}</view>
          <view class="chat-setting-meta">
            {{ memberCount }} 名成员{{ roleText ? ' · ' + roleText : '' }}{{ muteAll ? ' · 全员禁言' : '' }}
          </view>
        </view>
      </view>

      <view v-if="canEdit" class="chat-setting-edit">
        <text class="chat-setting-label">群名称</text>
        <input class="chat-setting-input" v-model="editNameVal" maxlength="64" placeholder="输入群名称" />
        <text class="chat-setting-label">群公告（聊天页置顶）</text>
        <textarea
          class="chat-setting-textarea"
          v-model="editNoticeVal"
          maxlength="500"
          placeholder="输入群公告，成员进入聊天可见"
        />
        <button type="button" class="chat-setting-save-btn" @click="saveProfile">保存修改</button>
      </view>
      <view v-else-if="notice" class="chat-setting-hint">群公告：{{ notice }}</view>
      <view v-else class="chat-setting-hint">暂无群公告</view>

      <view v-if="canEdit" class="chat-setting-row" @click="openAddMembers">
        <text>添加群成员</text>
        <text class="chat-setting-arrow">›</text>
      </view>

      <view v-if="!memberHidden" class="chat-setting-row" @click="openMembersPane">
        <text>查看群成员</text>
        <text class="chat-setting-arrow">›</text>
      </view>
      <view v-else class="chat-setting-hint">成员列表已隐藏</view>

      <view class="chat-setting-row chat-setting-toggle-row" @click="toggleNotifyMute">
        <text>消息不提醒</text>
        <view class="chat-switch" :class="{ 'is-on': notifyMute }" @click.stop="toggleNotifyMute">
          <view class="chat-switch-track" />
          <view class="chat-switch-knob" />
        </view>
      </view>
      <view class="chat-setting-hint">开启后本群不推送、不播放提示音</view>

      <view v-if="canEdit" class="chat-setting-row chat-setting-toggle-row" @click="toggleMuteAll">
        <text>全员禁言</text>
        <view class="chat-switch" :class="{ 'is-on': muteAll }" @click.stop="toggleMuteAll">
          <view class="chat-switch-track" />
          <view class="chat-switch-knob" />
        </view>
      </view>

      <view v-if="canEdit" class="chat-setting-block" id="chatForbidModesBlock">
        <view class="chat-setting-block-title">禁止模式</view>
        <view class="chat-forbid-modes">
          <label
            v-for="item in forbidItems"
            :key="item.key"
            class="chat-forbid-item"
            @click.prevent="toggleForbid(item.key)"
          >
            <view class="chat-forbid-check" :class="{ on: !!forbidFlags[item.key] }">
              {{ forbidFlags[item.key] ? '✓' : '' }}
            </view>
            <text>{{ item.label }}</text>
          </label>
        </view>
        <text class="chat-setting-label">禁言输入提示</text>
        <input
          class="chat-setting-input"
          v-model="forbidHint"
          maxlength="120"
          placeholder="留空则自动生成，如：仅可发/抢红包操作"
        />
        <view class="chat-setting-hint">禁止发文字时输入框显示的文案；留空按允许操作自动生成</view>
        <button
          type="button"
          class="chat-setting-save-btn"
          :disabled="forbidSaving"
          style="margin-top:8px"
          @click="saveForbid"
        >{{ forbidSaving ? '保存中…' : '保存禁止设置' }}</button>
        <view class="chat-setting-hint">可多选或全不选，不影响管理员</view>
      </view>

      <button
        v-if="myRole < 3"
        type="button"
        class="chat-setting-leave-btn"
        @click="onLeave"
      >退出群组</button>
      <button
        v-if="myRole >= 3"
        type="button"
        class="chat-setting-leave-btn chat-setting-dissolve-btn"
        :disabled="!canDissolve"
        @click="onDissolve"
      >{{ dissolveBtnText }}</button>
      <view v-if="myRole >= 3 && !canDissolve" class="chat-setting-hint">
        建群满 60 分钟后，群主可解散本群
      </view>
    </scroll-view>

    <!-- 群成员：对齐 888 #chatGroupMembersPane -->
    <view
      v-if="membersPane"
      class="chat-group-invite-overlay chat-group-members-overlay"
      :style="appOverlayStyle"
      aria-hidden="false"
    >
      <view class="chat-hero-hd">
        <view class="chat-hero-back" @click="closeMembersPane">
          <text class="chat-hero-back-char">‹</text>
        </view>
        <view class="chat-hero-title">群成员</view>
        <view class="chat-hero-spacer" />
      </view>
      <view class="chat-sub-main">
        <view class="chat-sub-toolbar">
          <button
            v-if="canEdit"
            type="button"
            class="chat-add-member-btn"
            @click="fromMembersToInvite"
          >添加群成员</button>
          <input
            class="chat-search-input"
            type="text"
            v-model="memberKeyword"
            placeholder="搜索成员昵称/ID"
            confirm-type="search"
            :adjust-position="true"
            :hold-keyboard="true"
            @confirm="reloadMembers"
          />
        </view>
        <view class="chat-member-scroll-host">
          <scroll-view class="chat-member-list" scroll-y :show-scrollbar="true" :style="memberListStyle">
            <view
              v-for="m in filteredMembers"
              :key="m.user_id"
              class="chat-member-item"
              @click.stop="onMemberTap(m)"
            >
              <view class="chat-member-avatar">
                <image
                  class="chat-member-avatar-img"
                  :src="avatarSrc(m.avatar_url || m.avatar || '')"
                  mode="aspectFill"
                />
              </view>
              <view class="chat-member-main">
                <view class="chat-member-name">{{ m.nickname || ('ID' + m.user_id) }}</view>
                <view class="chat-member-sub">ID {{ m.user_id }}</view>
              </view>
              <view class="chat-member-tags">
                <text v-if="(m.role | 0) === 3" class="chat-member-tag owner">群主</text>
                <text v-else-if="(m.role | 0) === 2" class="chat-member-tag admin">管理员</text>
                <text v-if="m.is_muted" class="chat-member-tag muted">禁言</text>
              </view>
            </view>
            <view v-if="!filteredMembers.length && !membersLoading" class="chat-empty">暂无成员</view>
            <view v-if="membersLoading" class="chat-empty">加载中…</view>
            <view v-if="canEdit && filteredMembers.length" class="chat-setting-hint" style="padding:8px 12px">点成员可禁言 / 移出 / 设管理</view>
          </scroll-view>
        </view>
      </view>
    </view>

    <!-- 添加成员：对齐 888 invite pane -->
    <view v-if="addSheet" class="chat-group-invite-overlay" :style="appOverlayStyle" aria-hidden="false">
      <view class="chat-hero-hd">
        <view class="chat-hero-back" @click="closeAddSheet">
          <text class="chat-hero-back-char">‹</text>
        </view>
        <view class="chat-hero-title">添加群成员</view>
        <view class="chat-hero-spacer" />
      </view>
      <view class="chat-sub-main">
        <view class="chat-sub-toolbar">
          <input
            class="chat-search-input"
            type="text"
            v-model="inviteKeyword"
            placeholder="搜索用户名/手机号/ID"
            confirm-type="search"
            :adjust-position="true"
            :hold-keyboard="true"
            @confirm="reloadCandidates"
          />
        </view>
        <view class="chat-member-scroll-host">
          <scroll-view class="chat-member-list chat-invite-list" scroll-y :show-scrollbar="true" :style="memberListStyle">
            <view
              v-for="u in filteredCandidates"
              :key="u.user_id"
              class="chat-member-item chat-invite-item"
              @click="toggleCandidate(u)"
            >
              <view class="chat-forbid-check chat-invite-box" :class="{ on: !!selectedIds[u.user_id] }">
                {{ selectedIds[u.user_id] ? '✓' : '' }}
              </view>
              <view class="chat-member-avatar">
                <image
                  class="chat-member-avatar-img"
                  :src="avatarSrc(u.avatar_url || u.avatar || '')"
                  mode="aspectFill"
                />
              </view>
              <view class="chat-member-main">
                <view class="chat-member-name">{{ u.nickname || ('ID' + u.user_id) }}</view>
                <view class="chat-member-sub">ID {{ u.user_id }}</view>
              </view>
            </view>
            <view v-if="!filteredCandidates.length && !candLoading" class="chat-empty">暂无可添加好友（仅好友可进群）</view>
            <view v-if="candLoading" class="chat-empty">加载中…</view>
          </scroll-view>
        </view>
        <view class="chat-invite-ft">
          <button
            type="button"
            class="chat-invite-confirm-btn"
            :disabled="addSaving || !selectedCount"
            @click="confirmAddMembers"
          >
            {{ addSaving ? '添加中…' : ('确认添加 (' + selectedCount + ' 人)') }}
          </button>
        </view>
      </view>
    </view>

    <!-- 成员操作：必须盖在成员列表 overlay 之上（z-index > 13100） -->
    <view class="chat-action-sheet chat-member-action-sheet" :class="{ open: memberSheet }" v-if="memberSheet" aria-hidden="false">
      <view class="chat-action-sheet-mask" @click="closeMemberSheets" />
      <view class="chat-action-sheet-panel" @click.stop>
        <view class="chat-action-sheet-title">{{ memberTargetName }}</view>
        <button
          v-if="canManageMember(memberTarget) && !(memberTarget && memberTarget.is_muted)"
          type="button"
          class="chat-action-item"
          @click="openMuteSheet"
        >单人禁言</button>
        <button
          v-if="canManageMember(memberTarget) && memberTarget && memberTarget.is_muted"
          type="button"
          class="chat-action-item"
          @click="doMute(0)"
        >取消禁言</button>
        <button
          v-if="canSetAdmin(memberTarget) && !(memberTarget && (memberTarget.role | 0) === 2)"
          type="button"
          class="chat-action-item"
          @click="doSetAdmin(true)"
        >设为管理员</button>
        <button
          v-if="canSetAdmin(memberTarget) && memberTarget && (memberTarget.role | 0) === 2"
          type="button"
          class="chat-action-item"
          @click="doSetAdmin(false)"
        >取消管理员</button>
        <button
          v-if="canManageMember(memberTarget)"
          type="button"
          class="chat-action-item danger"
          @click="confirmKick"
        >踢出群组</button>
        <button type="button" class="chat-action-item cancel" @click="closeMemberSheets">取消</button>
      </view>
    </view>

    <view class="chat-action-sheet chat-member-action-sheet" :class="{ open: muteSheet }" v-if="muteSheet" aria-hidden="false">
      <view class="chat-action-sheet-mask" @click="closeMemberSheets" />
      <view class="chat-action-sheet-panel" @click.stop>
        <view class="chat-action-sheet-title">选择禁言时长</view>
        <button
          v-for="opt in muteOptions"
          :key="opt.s"
          type="button"
          class="chat-action-item"
          @click="doMute(opt.s)"
        >{{ opt.n }}</button>
        <button type="button" class="chat-action-item cancel" @click="closeMemberSheets">关闭</button>
      </view>
    </view>
  </view>
</template>

<script setup>
import { safeNavigateBack, HOME_TAB } from '../../utils/nav.js'
import { computed, reactive, ref } from 'vue'
import { onLoad, onShow } from '@dcloudio/uni-app'
import TopBar from '../../components/TopBar.vue'
import { fetchProfile, getToken, uploadCommonFile } from '../../utils/auth.js'
import { avatarSrc } from '../../utils/chat.js'
import { applySafeAreaCssVars, measureChatOverlayTop } from '../../utils/safe-area.js'
import {
  addGroupMembers,
  fetchGroupInfo,
  fetchGroupMembers,
  groupCandidates,
  imConnect,
  kickGroupMember,
  leaveGroup,
  dissolveGroup,
  muteGroupMember,
  setGroupAdmin,
  setGroupForbid,
  setGroupMuteAll,
  setGroupNotifyMute,
  updateGroup,
} from '../../utils/im.js'
import { setGroupNotifyMuted } from '../../utils/group-notify-mute.js'
import '../../styles/chat-group-settings.css'
import '../../styles/chat-group-settings-parity.css'

function goBack() {
  safeNavigateBack(HOME_TAB)
}

/** App 浮层：固定像素高度（部分 WebView 忽略 fixed+bottom:0，会只剩顶栏高度导致设置页透出来） */
const appOverlayStyle = ref({})
const memberListStyle = ref({})
function refreshOverlayTop() {
  const r = applySafeAreaCssVars()
  const top = (r && r.overlayTop) || measureChatOverlayTop()
  // #ifdef APP-PLUS
  let wh = 640
  try {
    const sys = uni.getSystemInfoSync() || {}
    wh = Number(sys.windowHeight || sys.screenHeight || 0) || 640
  } catch (e) {}
  const overlayH = Math.max(240, Math.floor(wh - top))
  appOverlayStyle.value = {
    top: top + 'px',
    '--chat-overlay-top': top + 'px',
    left: '0px',
    right: '0px',
    bottom: '0px',
    height: overlayH + 'px',
    width: '100%',
    position: 'fixed',
    zIndex: 13100,
    backgroundColor: '#f3f4f6',
    display: 'flex',
    flexDirection: 'column',
    overflow: 'hidden',
  }
  // 顶栏约 48 + 工具条(添加按钮+搜索约 96 / 仅搜索约 52) + 邀请底栏约 72
  const tool = addSheet.value ? 52 : canEdit.value ? 96 : 52
  const ft = addSheet.value ? 72 : 0
  const listH = Math.max(180, Math.floor(overlayH - 48 - tool - ft))
  memberListStyle.value = {
    height: listH + 'px',
    position: 'relative',
    top: 'auto',
    bottom: 'auto',
    left: 'auto',
    right: 'auto',
  }
  // #endif
  // #ifndef APP-PLUS
  appOverlayStyle.value = {}
  memberListStyle.value = {}
  // #endif
}

const groupId = ref(0)
const group = ref({})
const myRole = ref(0)
const myId = ref(0)
const muteAll = ref(false)
const notifyMute = ref(false)
const memberCount = ref(0)
const memberHidden = ref(false)
const members = ref([])
const membersPane = ref(false)
const membersLoading = ref(false)
const memberKeyword = ref('')
const memberSheet = ref(false)
const muteSheet = ref(false)
const memberTarget = ref(null)
const forbidFlags = reactive({ text: false, image: false, emoji: false, video: false, rp: false })
const forbidHint = ref('')
const forbidSaving = ref(false)
const addSheet = ref(false)
const candidates = ref([])
const inviteKeyword = ref('')
const candLoading = ref(false)
const addSaving = ref(false)
const selectedIds = reactive({})
const editNameVal = ref('')
const editNoticeVal = ref('')
const avatarBusy = ref(false)

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
const groupAvatar = computed(() => {
  const g = group.value || {}
  return avatarSrc(g.avatar_url || g.avatar || '')
})
const canEdit = computed(() => (myRole.value | 0) >= 2)
const roleText = computed(() => roleLabel(myRole.value))
const DISSOLVE_MIN_AGE_SEC = 60 * 60
const groupAgeSec = computed(() => {
  const created = (group.value && (group.value.createtime | 0)) || 0
  if (created <= 0) return 0
  return Math.max(0, Math.floor(Date.now() / 1000) - created)
})
const canDissolve = computed(() => (myRole.value | 0) >= 3 && groupAgeSec.value >= DISSOLVE_MIN_AGE_SEC)
const dissolveBtnText = computed(() => {
  if ((myRole.value | 0) < 3) return '解散群组'
  if (canDissolve.value) return '解散群组'
  const left = Math.max(0, DISSOLVE_MIN_AGE_SEC - groupAgeSec.value)
  const m = Math.ceil(left / 60)
  return '解散群组（还需约 ' + m + ' 分钟）'
})
const memberTargetName = computed(() => {
  const m = memberTarget.value
  if (!m) return '成员操作'
  return m.nickname || ('ID' + m.user_id)
})
const selectedCount = computed(() => Object.keys(selectedIds).filter((k) => selectedIds[k]).length)
const filteredMembers = computed(() => {
  const kw = String(memberKeyword.value || '').trim().toLowerCase()
  const rows = members.value || []
  if (!kw) return rows
  return rows.filter((m) => {
    const name = String(m.nickname || '').toLowerCase()
    const id = String(m.user_id || '')
    return name.indexOf(kw) >= 0 || id.indexOf(kw) >= 0
  })
})
const filteredCandidates = computed(() => {
  const kw = String(inviteKeyword.value || '').trim().toLowerCase()
  const rows = candidates.value || []
  if (!kw) return rows
  return rows.filter((u) => {
    const name = String(u.nickname || '').toLowerCase()
    const mobile = String(u.mobile || u.phone || '').toLowerCase()
    const id = String(u.user_id || '')
    return name.indexOf(kw) >= 0 || mobile.indexOf(kw) >= 0 || id.indexOf(kw) >= 0
  })
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
    forbidFlags[it.key] = !!src[it.key]
  })
}

function toggleForbid(key) {
  forbidFlags[key] = !forbidFlags[key]
}

async function loadInfo() {
  if (!groupId.value) return
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
    notifyMute.value = !!(data.notify_mute | 0)
    setGroupNotifyMuted(groupId.value, notifyMute.value)
    memberCount.value = data.member_count | 0
    memberHidden.value = !!data.member_list_hidden
    if (data.my_user_id) myId.value = data.my_user_id | 0
    applyForbid(data.forbid_modes || (data.policy && data.policy.forbid_modes) || {})
    const pol = data.policy || {}
    forbidHint.value = String(pol.forbid_speak_hint || group.value.forbid_speak_hint || '').trim()
    editNameVal.value = group.value.name || ''
    editNoticeVal.value = group.value.notice || ''
    if (group.value.name) {
      uni.setNavigationBarTitle({ title: group.value.name })
    }
    if (memberHidden.value) {
      members.value = []
    }
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '加载失败', icon: 'none' })
  }
}

async function loadMembersList(keyword) {
  if (!groupId.value || memberHidden.value) {
    members.value = []
    return
  }
  membersLoading.value = true
  try {
    const mp = await fetchGroupMembers(groupId.value, keyword || '')
    const md = (mp && mp.data) || mp || {}
    members.value = md.list || []
    if (md.member_count != null) memberCount.value = md.member_count | 0
    if (md.my_role != null) myRole.value = md.my_role | 0
    if (md.mute_all != null) muteAll.value = !!md.mute_all
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '加载成员失败', icon: 'none' })
    members.value = []
  } finally {
    membersLoading.value = false
  }
}

function closeMembersPane() {
  membersPane.value = false
  memberKeyword.value = ''
}

async function openMembersPane() {
  if (memberHidden.value) {
    uni.showToast({ title: '隐私群已隐藏成员列表', icon: 'none' })
    return
  }
  membersPane.value = true
  memberKeyword.value = ''
  refreshOverlayTop()
  await loadMembersList('')
}

function reloadMembers() {
  loadMembersList(memberKeyword.value)
}

function fromMembersToInvite() {
  closeMembersPane()
  openAddMembers()
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
    if (membersPane.value) await loadMembersList(memberKeyword.value)
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
    if (membersPane.value) await loadMembersList(memberKeyword.value)
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
        if (membersPane.value) await loadMembersList(memberKeyword.value)
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

function toggleMuteAll() {
  applyMute(!muteAll.value)
}

async function toggleNotifyMute() {
  const next = !notifyMute.value
  try {
    await setGroupNotifyMute(groupId.value, next)
    notifyMute.value = next
    setGroupNotifyMuted(groupId.value, next)
    uni.showToast({
      title: next ? '已开启消息不提醒' : '已关闭消息不提醒',
      icon: 'none',
    })
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '设置失败', icon: 'none' })
    await loadInfo()
  }
}

async function saveForbid() {
  if (forbidSaving.value) return
  forbidSaving.value = true
  try {
    const modes = {}
    forbidItems.forEach((it) => {
      modes[it.key] = forbidFlags[it.key] ? 1 : 0
    })
    const packet = await setGroupForbid(groupId.value, modes, forbidHint.value)
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

async function saveProfile() {
  const name = String(editNameVal.value || '').trim().slice(0, 64)
  if (!name) {
    uni.showToast({ title: '群名不能为空', icon: 'none' })
    return
  }
  try {
    await updateGroup(groupId.value, {
      name,
      notice: String(editNoticeVal.value || '').trim(),
    })
    uni.showToast({ title: '已更新', icon: 'none' })
    await loadInfo()
  } catch (e) {
    uni.showToast({ title: (e && e.message) || '更新失败', icon: 'none' })
  }
}

async function pickGroupAvatar() {
  if (!canEdit.value || avatarBusy.value) return
  try {
    const chosen = await new Promise((resolve, reject) => {
      uni.chooseImage({
        count: 1,
        sizeType: ['compressed'],
        sourceType: ['album', 'camera'],
        success: resolve,
        fail: reject,
      })
    })
    const filePath = String((chosen && chosen.tempFilePaths && chosen.tempFilePaths[0]) || '')
    if (!filePath) return
    avatarBusy.value = true
    uni.showLoading({ title: '上传中…', mask: true })
    const up = await uploadCommonFile(filePath)
    // 库内存相对 /uploads；展示走 OSS fullurl
    let path = String(up.url || '').trim()
    const fullRaw = String(up.fullurl || '').trim()
    if ((!path || path.indexOf('/uploads/') !== 0) && fullRaw) {
      try {
        path = new URL(fullRaw).pathname
      } catch (e) {
        if (fullRaw.indexOf('/uploads/') >= 0) path = fullRaw.slice(fullRaw.indexOf('/uploads/'))
      }
    }
    if (!path || path.indexOf('/uploads/') !== 0) throw new Error('上传失败')
    const packet = await updateGroup(groupId.value, { avatar: path })
    const data = (packet && packet.data) || packet || {}
    if (data.group) {
      group.value = data.group
    } else {
      group.value = Object.assign({}, group.value, {
        avatar: path,
        avatar_url: fullRaw || path,
      })
    }
    uni.showToast({ title: '群头像已更新', icon: 'none' })
  } catch (e) {
    const msg = (e && e.message) || ''
    if (!/cancel|deny|fail chooseImage/i.test(msg)) {
      uni.showToast({ title: msg || '上传失败', icon: 'none' })
    }
  } finally {
    avatarBusy.value = false
    try {
      uni.hideLoading()
    } catch (e2) {}
  }
}

function closeAddSheet() {
  addSheet.value = false
  candidates.value = []
  inviteKeyword.value = ''
  Object.keys(selectedIds).forEach((k) => {
    delete selectedIds[k]
  })
}

function reloadCandidates() {
  loadCandidates()
}

async function loadCandidates() {
  candLoading.value = true
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

async function openAddMembers() {
  addSheet.value = true
  inviteKeyword.value = ''
  Object.keys(selectedIds).forEach((k) => {
    delete selectedIds[k]
  })
  refreshOverlayTop()
  await loadCandidates()
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

function onDissolve() {
  if ((myRole.value | 0) < 3) {
    uni.showToast({ title: '仅群主可解散', icon: 'none' })
    return
  }
  if (!canDissolve.value) {
    uni.showToast({ title: '建群满 60 分钟后才能解散', icon: 'none' })
    return
  }
  uni.showModal({
    title: '解散群组',
    content: '确定解散「' + groupName.value + '」？解散后所有成员将退出，且不可恢复。',
    confirmColor: '#e53935',
    success: async (res) => {
      if (!res.confirm) return
      try {
        await dissolveGroup(groupId.value)
        uni.showToast({ title: '群组已解散', icon: 'none' })
        setTimeout(() => {
          uni.switchTab({ url: '/pages/messages/messages' })
        }, 400)
      } catch (e) {
        uni.showToast({ title: (e && e.message) || '解散失败', icon: 'none' })
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
  refreshOverlayTop()
  if (groupId.value) loadInfo()
})
</script>
