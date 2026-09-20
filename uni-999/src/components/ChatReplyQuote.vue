<template>
  <view
    v-if="reply"
    class="chat-reply-quote"
    :class="{ me: isMe }"
    hover-class="chat-reply-quote--on"
    @click.stop="onTap"
  >
    <view class="chat-reply-quote-bar" aria-hidden="true" />
    <view class="chat-reply-quote-main">
      <text class="chat-reply-quote-name">{{ displayName }}</text>
      <text class="chat-reply-quote-text">{{ displayPreview }}</text>
    </view>
  </view>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  reply: { type: Object, default: null },
  isMe: { type: Boolean, default: false },
})

const emit = defineEmits(['jump'])

const displayName = computed(() => {
  const r = props.reply || {}
  return String(r.nickname || '').trim() || ('用户' + ((r.from_user_id | 0) || ''))
})

const displayPreview = computed(() => {
  const r = props.reply || {}
  const c = String(r.content || r.preview || '').trim()
  if (c) return c.length > 80 ? c.slice(0, 80) + '…' : c
  const mt = r.msg_type | 0
  if (mt === 4) return '[图片]'
  if (mt === 5) return '[视频]'
  if (mt === 6) return '[表情]'
  if (mt === 7) return '[文件]'
  if (mt === 2) return '[红包]'
  return '[消息]'
})

function onTap() {
  emit('jump', props.reply)
}
</script>

<style scoped>
.chat-reply-quote {
  display: flex;
  flex-direction: row;
  align-items: stretch;
  max-width: 100%;
  margin: 0 0 6px;
  padding: 6px 8px;
  border-radius: 6px;
  background: rgba(0, 0, 0, 0.06);
  box-sizing: border-box;
  overflow: hidden;
}
.chat-reply-quote.me {
  background: rgba(0, 0, 0, 0.08);
}
.chat-reply-quote--on {
  opacity: 0.85;
}
.chat-reply-quote-bar {
  flex-shrink: 0;
  width: 3px;
  margin-right: 8px;
  border-radius: 2px;
  background: #07c160;
}
.chat-reply-quote.me .chat-reply-quote-bar {
  background: rgba(255, 255, 255, 0.85);
}
.chat-reply-quote-main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.chat-reply-quote-name {
  font-size: 12px;
  line-height: 1.3;
  color: #07c160;
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.chat-reply-quote.me .chat-reply-quote-name {
  color: rgba(255, 255, 255, 0.95);
}
.chat-reply-quote-text {
  font-size: 12px;
  line-height: 1.35;
  color: #888;
  overflow: hidden;
  text-overflow: ellipsis;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  word-break: break-word;
}
.chat-reply-quote.me .chat-reply-quote-text {
  color: rgba(255, 255, 255, 0.78);
}
</style>
