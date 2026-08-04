<script setup>
import { onLaunch, onShow } from '@dcloudio/uni-app'
import { getToken } from './utils/auth.js'
import { imConnect, imDisconnect } from './utils/im.js'

onLaunch(() => {
  const token = getToken()
  if (!token) {
    // 启动页为 login；已登录则进大厅
    return
  }
  uni.reLaunch({ url: '/pages/home/home' })
  imConnect().catch(() => {})
})

onShow(() => {
  if (getToken()) {
    imConnect().catch(() => {})
  } else {
    imDisconnect()
  }
})
</script>

<style>
page {
  background-color: #f6f1ea;
}
</style>
