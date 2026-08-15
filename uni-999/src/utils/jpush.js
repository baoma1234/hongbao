/**
 * 极光推送薄封装（可选原生插件）
 * - 未集成 JPush 原生插件时：开关只存本地，不报错
 * - 接入步骤见注释；打包需配置 AppKey 并勾选对应原生插件
 *
 * 推荐：DCloud 插件市场「极光推送」或官方 jpush-hbuilder-demo，
 * 云打包后通过 uni.requireNativePlugin('JG-JPush') / plus.Push 使用。
 */

import { isPushEnabled, setPushEnabled } from './app-prefs.js'

let jpush = null

function tryGetJPush() {
  if (jpush) return jpush
  // #ifdef APP-PLUS
  try {
    if (typeof uni !== 'undefined' && uni.requireNativePlugin) {
      jpush = uni.requireNativePlugin('JG-JPush') || uni.requireNativePlugin('JPush-Module') || null
    }
  } catch (e) {
    jpush = null
  }
  // #endif
  return jpush
}

export function applyPushPreference(enabled) {
  setPushEnabled(!!enabled)
  // #ifdef APP-PLUS
  const jp = tryGetJPush()
  if (!jp) return { ok: true, wired: false }
  try {
    if (enabled) {
      if (typeof jp.resumePush === 'function') jp.resumePush()
      else if (typeof jp.setPushTime === 'function') {
        /* keep default */
      }
    } else if (typeof jp.stopPush === 'function') {
      jp.stopPush()
    }
    return { ok: true, wired: true }
  } catch (e) {
    return { ok: false, wired: true, error: (e && e.message) || String(e) }
  }
  // #endif
  // #ifndef APP-PLUS
  return { ok: true, wired: false }
  // #endif
}

export function initPushOnLaunch() {
  // #ifdef APP-PLUS
  if (!isPushEnabled()) return
  const jp = tryGetJPush()
  if (!jp) return
  try {
    if (typeof jp.init === 'function') jp.init()
    if (typeof jp.setDebug === 'function') jp.setDebug(false)
  } catch (e) {}
  // #endif
}

export function isJPushPluginPresent() {
  return !!tryGetJPush()
}
