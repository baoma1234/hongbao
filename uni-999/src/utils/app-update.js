/**
 * App 内更新检测（仅 APP-PLUS；H5/Safari 跳过）
 * 冷启动拉 config 后比对 versionCode；安卓 / iOS 分端。
 */
import { getAppVersionInfo } from './app-prefs.js'

const DISMISS_KEY = 'hb_app_update_dismissed'
let checking = false
let prompted = false

function platformKey() {
  try {
    const p = String((uni.getSystemInfoSync() || {}).platform || '').toLowerCase()
    if (p === 'ios') return 'ios'
    if (p === 'android') return 'android'
  } catch (e) {}
  return ''
}

function readDismissed() {
  try {
    const raw = uni.getStorageSync(DISMISS_KEY)
    if (!raw) return null
    return typeof raw === 'string' ? JSON.parse(raw) : raw
  } catch (e) {
    return null
  }
}

function writeDismissed(platform, code) {
  try {
    uni.setStorageSync(
      DISMISS_KEY,
      JSON.stringify({ platform: String(platform || ''), code: Number(code) || 0, at: Date.now() })
    )
  } catch (e) {}
}

function openDownload(url) {
  const u = String(url || '').trim()
  if (!u) {
    uni.showToast({ title: '未配置下载地址', icon: 'none' })
    return
  }
  // #ifdef APP-PLUS
  try {
    plus.runtime.openURL(u)
    return
  } catch (e) {}
  // #endif
  try {
    uni.setClipboardData({
      data: u,
      success: () => uni.showToast({ title: '链接已复制', icon: 'none' }),
    })
  } catch (e2) {}
}

/**
 * @param {object|null} cfg  fetchConfig() 返回体（含 app_update）
 * @param {{ force?: boolean }} [opts] force=true 忽略「稍后」记忆（用于设置页手动检查）
 */
export async function checkAppUpdate(cfg, opts = {}) {
  // #ifndef APP-PLUS
  return { skipped: true, reason: 'not_app' }
  // #endif
  // #ifdef APP-PLUS
  if (checking) return { skipped: true, reason: 'busy' }
  if (prompted && !opts.force) return { skipped: true, reason: 'already' }

  const update = cfg && cfg.app_update
  if (!update || !update.enabled) {
    return { skipped: true, reason: 'disabled' }
  }

  const plat = platformKey()
  if (plat !== 'android' && plat !== 'ios') {
    return { skipped: true, reason: 'unknown_platform' }
  }

  const remote = update[plat] || {}
  const latestCode = Number(remote.version_code) || 0
  if (latestCode <= 0) {
    return { skipped: true, reason: 'no_remote_code' }
  }

  const local = getAppVersionInfo()
  const localCode = Number(local.versionCode) || 0
  if (localCode >= latestCode) {
    return { ok: true, upToDate: true, localCode, latestCode }
  }

  const force = !!remote.force
  if (!force && !opts.force) {
    const dismissed = readDismissed()
    if (
      dismissed &&
      String(dismissed.platform) === plat &&
      Number(dismissed.code) === latestCode
    ) {
      return { skipped: true, reason: 'dismissed' }
    }
  }

  const name = String(remote.version_name || '').trim() || String(latestCode)
  const note = String(remote.note || '').trim()
  const lines = [
    '发现新版本 ' + name + '（' + latestCode + '）',
    '当前 ' + (local.versionName || '') + '（' + localCode + '）',
  ]
  if (note) lines.push('', note)

  checking = true
  prompted = true
  return await new Promise((resolve) => {
    uni.showModal({
      title: force ? '请更新后继续使用' : '发现新版本',
      content: lines.join('\n'),
      showCancel: !force,
      cancelText: '稍后',
      confirmText: '立即更新',
      success: (res) => {
        if (res && res.confirm) {
          openDownload(remote.download_url)
          resolve({ ok: true, updated: true, force, localCode, latestCode })
          return
        }
        if (!force) {
          writeDismissed(plat, latestCode)
        } else {
          // 强制更新：关掉后再弹一次（下一冷启动仍会拦）
          prompted = false
          setTimeout(() => {
            checkAppUpdate(cfg, { force: true }).catch(() => {})
          }, 600)
        }
        resolve({ ok: true, dismissed: true, force, localCode, latestCode })
      },
      fail: () => {
        resolve({ ok: false })
      },
      complete: () => {
        checking = false
      },
    })
  })
  // #endif
}
