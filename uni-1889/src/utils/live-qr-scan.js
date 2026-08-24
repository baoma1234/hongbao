/**
 * H5 摄像头连续扫码（getUserMedia + jsQR），对齐 888 qr-friend.js
 */
import { assetBase } from './i18n.js'
import { parseFriendPayload } from './friend-scan.js'

function loadScriptOnce(src) {
  return new Promise((resolve, reject) => {
    // #ifdef H5
    if (typeof document === 'undefined') {
      reject(new Error('no document'))
      return
    }
    if (typeof window !== 'undefined' && window.jsQR) {
      resolve()
      return
    }
    const exist = document.querySelector('script[data-src="' + src + '"]')
    if (exist) {
      const wait = () => {
        if (window.jsQR) resolve()
        else setTimeout(wait, 40)
      }
      wait()
      return
    }
    const s = document.createElement('script')
    s.src = src
    s.async = true
    s.setAttribute('data-src', src)
    s.onload = () => resolve()
    s.onerror = () => reject(new Error('扫码库加载失败'))
    document.head.appendChild(s)
    // #endif
    // #ifndef H5
    reject(new Error('H5 only'))
    // #endif
  })
}

export async function ensureJsQr() {
  await loadScriptOnce(assetBase() + 'static/vendor/jsQR.js')
  if (typeof window === 'undefined' || !window.jsQR) throw new Error('扫码库未加载')
  return window.jsQR
}

/**
 * @param {{ video: HTMLVideoElement, canvas?: HTMLCanvasElement, onCode: (raw: string) => void, onError?: (e: Error) => void }} opts
 * @returns {{ stop: () => void }}
 */
export async function startLiveQrScan(opts) {
  // #ifdef H5
  const video = opts && opts.video
  if (!video) throw new Error('扫码组件缺失')
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    throw new Error('当前环境不支持摄像头，请改用相册识别')
  }
  const jsQRFn = await ensureJsQr()
  const canvas = (opts && opts.canvas) || document.createElement('canvas')
  const stream = await navigator.mediaDevices.getUserMedia({
    audio: false,
    video: { facingMode: { ideal: 'environment' } },
  })
  video.srcObject = stream
  video.setAttribute('playsinline', 'true')
  video.muted = true
  await video.play()

  let timer = null
  let stopped = false
  const tick = () => {
    if (stopped) return
    try {
      if (video.readyState < 2) return
      const w = video.videoWidth
      const h = video.videoHeight
      if (!w || !h) return
      canvas.width = w
      canvas.height = h
      const ctx = canvas.getContext('2d')
      ctx.drawImage(video, 0, 0, w, h)
      const imageData = ctx.getImageData(0, 0, w, h)
      const code = jsQRFn(imageData.data, imageData.width, imageData.height, {
        inversionAttempts: 'dontInvert',
      })
      if (code && code.data) {
        const id = parseFriendPayload(code.data)
        if (id) {
          stop()
          opts.onCode(code.data)
        }
      }
    } catch (e) {
      /* ignore frame errors */
    }
  }

  function stop() {
    if (stopped) return
    stopped = true
    if (timer) {
      clearInterval(timer)
      timer = null
    }
    try {
      stream.getTracks().forEach((t) => t.stop())
    } catch (e) {}
    try {
      video.srcObject = null
    } catch (e2) {}
  }

  timer = setInterval(tick, 350)
  return { stop }
  // #endif
  // #ifndef H5
  throw new Error('H5 only')
  // #endif
}
