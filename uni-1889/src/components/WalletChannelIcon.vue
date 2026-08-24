<template>
  <image
    v-if="src && !failed"
    class="wallet-channel-icon"
    :src="src"
    mode="aspectFit"
    :style="imgStyle"
    @error="onError"
  />
  <view v-else class="wallet-channel-icon wallet-channel-icon--placeholder" :style="imgStyle">
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

const imgStyle = {
  width: '36px',
  height: '36px',
  display: 'block',
  flexShrink: '0',
}

const src = computed(() => {
  if (props.icon) {
    const raw = String(props.icon).trim()
    if (!raw) return ''
    if (/^https?:\/\//i.test(raw) || raw.startsWith('data:')) {
      return channelIconUrl({ icon: raw, name: props.name }) || raw
    }
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
