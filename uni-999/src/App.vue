<script setup>
import { onLaunch, onShow } from '@dcloudio/uni-app'
import { getToken } from './utils/auth.js'
import { initI18n } from './utils/i18n.js'
import { imConnect, imDisconnect } from './utils/im.js'
import { initSkin } from './utils/skin.js'
import './styles/hb.css'

onLaunch(async () => {
  initSkin()
  try {
    await initI18n()
  } catch (e) {}
  const token = getToken()
  if (!token) return
  uni.reLaunch({ url: '/pages/home/home' })
  imConnect().catch(() => {})
})

onShow(() => {
  initSkin()
  if (getToken()) {
    imConnect().catch(() => {})
  } else {
    imDisconnect()
  }
})
</script>

<style>
page {
  background-color: var(--bg-main, #f4f6f9);
  color: var(--text-main, #1a212d);
  --top-bar-height: 48px;
}
</style>
