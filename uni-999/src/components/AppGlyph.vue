<template>
  <text class="app-glyph" :class="'app-glyph--' + size" aria-hidden="true">{{ char }}</text>
</template>

<script setup>
import { computed } from 'vue'

/** App 端不渲染原生 <svg>，用字形代替菜单/快捷入口图标 */
const GLYPHS = {
  qr: '▦',
  scan: '⌖',
  recharge: '⚡',
  withdraw: '⬇',
  payee: '💳',
  ledger: '☰',
  fission: '🧧',
  info: '☺',
  password: '🔑',
  paypassword: '🔐',
  settings: '⚙',
  search: '⌕',
  plus: '＋',
}

const props = defineProps({
  name: { type: String, required: true },
  size: { type: String, default: 'md' }, // sm | md
})

const char = computed(() => GLYPHS[props.name] || '•')
</script>

<style scoped>
.app-glyph {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  line-height: 1;
  font-weight: 700;
  color: inherit;
  user-select: none;
}
.app-glyph--md {
  font-size: 18px;
}
.app-glyph--sm {
  font-size: 15px;
}
</style>
