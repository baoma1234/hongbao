<template>
  <image
    v-if="src && !failed"
    class="wallet-channel-icon"
    :src="src"
    mode="aspectFit"
    @error="onError"
  />
  <view v-else class="wallet-channel-icon wallet-channel-icon--placeholder">
    {{ letter }}
  </view>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { channelIconUrl } from '../utils/wallet.js'

const props = defineProps({
  channel: { type: Object, default: null },
  icon: { type: String, default: '' },
  name: { type: String, default: '' },
})

const failed = ref(false)

const src = computed(() => {
  if (props.icon) {
    const raw = String(props.icon).trim()
    if (!raw) return ''
    // 已是绝对地址则直接用；相对路径走通道解析逻辑
    if (/^https?:\/\//i.test(raw) || raw.startsWith('data:')) return raw
    return channelIconUrl({ icon: raw, name: props.name })
  }
  return channelIconUrl(props.channel)
})

const letter = computed(() => {
  const n = String(props.name || (props.channel && props.channel.name) || '?').trim()
  return n.charAt(0) || '?'
})

watch(src, () => {
  failed.value = false
})

function onError() {
  failed.value = true
}
</script>
