<template>
  <view class="linkified-text" :class="rootClass">
    <text
      v-for="(p, i) in parts"
      :key="i"
      :class="p.type === 'link' ? 'notice-http-link' : 'notice-http-plain'"
      @click.stop="onPartClick(p)"
    >{{ p.value }}</text>
  </view>
</template>

<script setup>
import { computed } from 'vue'
import { splitHttpUrlParts } from '../utils/linkify.js'
import { openExternalHttpUrl } from '../utils/wallet.js'

const props = defineProps({
  text: { type: String, default: '' },
  rootClass: { type: [String, Array, Object], default: '' },
})

const parts = computed(() => splitHttpUrlParts(props.text))

function onPartClick(p) {
  if (!p || p.type !== 'link') return
  openExternalHttpUrl(p.value)
}
</script>

<style scoped>
.linkified-text {
  white-space: pre-wrap;
  word-break: break-word;
}
.notice-http-plain {
  color: inherit;
}
.notice-http-link {
  color: #12b7f5;
  text-decoration: underline;
}
</style>
