<template>
  <view class="chat-fold-text">
    <template v-if="!seg.foldable">
      <template v-for="(p, i) in richParts" :key="'a' + i">
        <view v-if="p.t === 'br'" class="content-br" />
        <text
          v-else
          :class="{
            'content-link': p.t === 'link',
            'content-mention': p.t === 'mention',
            'is-me': p.t === 'mention' && p.me,
          }"
          @click.stop="onPartClick(p)"
        >{{ p.v }}</text>
      </template>
    </template>
    <template v-else>
      <template v-for="(p, i) in enrichParts(seg.headParts)" :key="'h' + i">
        <view v-if="p.t === 'br'" class="content-br" />
        <text
          v-else
          :class="{
            'content-link': p.t === 'link',
            'content-mention': p.t === 'mention',
            'is-me': p.t === 'mention' && p.me,
          }"
          @click.stop="onPartClick(p)"
        >{{ p.v }}</text>
      </template>

      <view
        class="msg-fold-quote"
        :class="{ 'is-expanded': expanded }"
        @click.stop="toggle"
      >
        <view class="msg-fold-quote-body">
          <template v-for="(p, i) in enrichParts(midShowParts)" :key="'m' + i">
            <view v-if="p.t === 'br'" class="content-br" />
            <text
              v-else
              :class="{
                'content-link': p.t === 'link',
                'content-mention': p.t === 'mention',
                'is-me': p.t === 'mention' && p.me,
              }"
              @click.stop="onPartClick(p)"
            >{{ p.v }}</text>
          </template>
        </view>
        <text class="msg-fold-chevron" aria-hidden="true">{{ expanded ? '∧' : '∨' }}</text>
      </view>

      <template v-for="(p, i) in enrichParts(seg.tailParts)" :key="'t' + i">
        <view v-if="p.t === 'br'" class="content-br" />
        <text
          v-else
          :class="{
            'content-link': p.t === 'link',
            'content-mention': p.t === 'mention',
            'is-me': p.t === 'mention' && p.me,
          }"
          @click.stop="onPartClick(p)"
        >{{ p.v }}</text>
      </template>
    </template>
  </view>
</template>

<script setup>
import { computed } from 'vue'
import { buildLongMsgFoldSegments } from '../utils/chat.js'
import { splitMentionParts } from '../utils/chat-mention.js'

const props = defineProps({
  text: { type: String, default: '' },
  expanded: { type: Boolean, default: false },
  fromUserId: { type: [Number, String], default: 0 },
  groupId: { type: [Number, String], default: 0 },
  noFold: { type: Boolean, default: false },
  /** @type {Array<{user_id?:number,nickname?:string}>} */
  mentions: { type: Array, default: () => [] },
  atAll: { type: Boolean, default: false },
  myUserId: { type: [Number, String], default: 0 },
})

const emit = defineEmits(['toggle', 'open-link', 'mention-tap'])

const mentionList = computed(() => {
  const my = props.myUserId | 0
  return (props.mentions || []).map((u) => {
    const uid = (u && (u.user_id || u.id)) | 0
    return Object.assign({}, u, { _me: my > 0 && uid === my })
  })
})

const seg = computed(() =>
  buildLongMsgFoldSegments(props.text, {
    fromUserId: props.fromUserId,
    groupId: props.groupId,
    noFold: !!props.noFold,
  })
)

const midShowParts = computed(() => {
  if (!seg.value.foldable) return []
  return props.expanded ? seg.value.midParts : seg.value.midPreviewParts
})

const richParts = computed(() => enrichParts(seg.value.parts || []))

function enrichParts(parts) {
  const list = Array.isArray(parts) ? parts : []
  if (!props.atAll && !(mentionList.value && mentionList.value.length)) {
    return list
  }
  const out = []
  for (let i = 0; i < list.length; i++) {
    const p = list[i]
    if (!p || p.t !== 'text') {
      out.push(p)
      continue
    }
    const chunks = splitMentionParts(p.v, mentionList.value, props.atAll)
    for (let j = 0; j < chunks.length; j++) {
      const c = chunks[j]
      if (c.t === 'mention') {
        out.push(c)
      } else {
        out.push({ t: 'text', v: c.v })
      }
    }
  }
  return out
}

function toggle() {
  if (!seg.value.foldable) return
  emit('toggle')
}

function onPartClick(p) {
  if (!p) return
  if (p.t === 'link') {
    emit('open-link', p.v)
    return
  }
  if (p.t === 'mention') {
    emit('mention-tap', p)
  }
}
</script>

<style scoped>
.chat-fold-text {
  display: block;
  word-break: break-word;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.content-br {
  display: block;
  width: 100%;
  height: 0;
  line-height: 0;
  overflow: hidden;
}
.content-link {
  color: #576b95;
  text-decoration: underline;
}
.content-mention {
  color: #576b95;
  font-weight: 600;
}
.content-mention.is-me {
  color: #07c160;
}
/* Telegram 可折叠引用块 */
.msg-fold-quote {
  position: relative;
  display: block;
  margin: 6px 0;
  padding: 7px 26px 7px 10px;
  background: rgba(255, 168, 100, 0.22);
  border-left: 3px solid #f0a46a;
  border-radius: 0 8px 8px 0;
  box-sizing: border-box;
}
.msg-fold-quote-body {
  display: block;
  word-break: break-word;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.msg-fold-chevron {
  position: absolute;
  right: 8px;
  top: 50%;
  transform: translateY(-50%);
  font-size: 12px;
  color: #c27a45;
  line-height: 1;
}
</style>
