<template>
  <img
    v-if="src && !failed"
    class="wallet-channel-icon"
    :src="src"
    alt=""
    loading="lazy"
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
  if (props.icon) return String(props.icon).trim()
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
