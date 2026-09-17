<template>
  <view class="chat-fold-text">
    <template v-if="!seg.foldable">
      <template v-for="(p, i) in seg.parts" :key="'a' + i">
        <view v-if="p.t === 'br'" class="content-br" />
        <text
          v-else
          :class="{ 'content-link': p.t === 'link' }"
          @click.stop="onPartClick(p)"
        >{{ p.v }}</text>
      </template>
    </template>
    <template v-else>
      <template v-for="(p, i) in seg.headParts" :key="'h' + i">
        <view v-if="p.t === 'br'" class="content-br" />
        <text
          v-else
          :class="{ 'content-link': p.t === 'link' }"
          @click.stop="onPartClick(p)"
        >{{ p.v }}</text>
      </template>

      <view
        class="msg-fold-quote"
        :class="{ 'is-expanded': expanded }"
        @click.stop="toggle"
      >
        <view class="msg-fold-quote-body">
          <template v-for="(p, i) in midShowParts" :key="'m' + i">
            <view v-if="p.t === 'br'" class="content-br" />
            <text
              v-else
              :class="{ 'content-link': p.t === 'link' }"
              @click.stop="onPartClick(p)"
            >{{ p.v }}</text>
          </template>
        </view>
        <text class="msg-fold-chevron" aria-hidden="true">{{ expanded ? '∧' : '∨' }}</text>
      </view>

      <template v-for="(p, i) in seg.tailParts" :key="'t' + i">
        <view v-if="p.t === 'br'" class="content-br" />
        <text
          v-else
          :class="{ 'content-link': p.t === 'link' }"
          @click.stop="onPartClick(p)"
        >{{ p.v }}</text>
      </template>
    </template>
  </view>
</template>

<script setup>
import { computed } from 'vue'
import { buildLongMsgFoldSegments } from '../utils/chat.js'

const props = defineProps({
  text: { type: String, default: '' },
  expanded: { type: Boolean, default: false },
  fromUserId: { type: [Number, String], default: 0 },
})

const emit = defineEmits(['toggle', 'open-link'])

const seg = computed(() => buildLongMsgFoldSegments(props.text, { fromUserId: props.fromUserId }))

const midShowParts = computed(() => {
  if (!seg.value.foldable) return []
  return props.expanded ? seg.value.midParts : seg.value.midPreviewParts
})

function toggle() {
  if (!seg.value.foldable) return
  emit('toggle')
}

function onPartClick(p) {
  if (!p || p.t !== 'link') return
  emit('open-link', p.v)
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
  color: inherit;
}
.msg-fold-chevron {
  position: absolute;
  right: 6px;
  bottom: 4px;
  color: #e08a4a;
  font-size: 15px;
  font-weight: 600;
  line-height: 1;
  padding: 4px;
}
</style>
