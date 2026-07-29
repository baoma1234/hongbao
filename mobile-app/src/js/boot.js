/**
 * 本地兜底页：正式包通常由 capacitor.config 的 server.url 直接加载线上 H5。
 * 若未配置远程地址 / 离线调试，则从这里跳转。
 */
import { Capacitor } from '@capacitor/core';
import { App } from '@capacitor/app';
import { StatusBar, Style } from '@capacitor/status-bar';
import { SplashScreen } from '@capacitor/splash-screen';
import { Browser } from '@capacitor/browser';

const DEFAULT_H5 = 'https://555fx1.bio/888/';

function normalizeUrl(url) {
  const u = String(url || '').trim();
  if (!u) return DEFAULT_H5;
  return u.replace(/\/?$/, '/');
}

async function setupNativeChrome() {
  if (!Capacitor.isNativePlatform()) return;
  try {
    await StatusBar.setStyle({ style: Style.Dark });
    await StatusBar.setBackgroundColor({ color: '#FFFFFF' });
  } catch (_) {}
  try {
    await SplashScreen.hide();
  } catch (_) {}

  // 安卓返回键：优先网页 history，否则退出确认
  App.addListener('backButton', ({ canGoBack }) => {
    if (canGoBack) {
      window.history.back();
      return;
    }
    App.exitApp();
  });

  // 站外链接用系统浏览器（若 H5 通过 postMessage 通知壳）
  window.openExternal = async (url) => {
    if (!url) return;
    await Browser.open({ url: String(url) });
  };
}

function showFallback(h5Url, errMsg) {
  const tip = document.getElementById('bootTip');
  const urlEl = document.getElementById('bootUrl');
  const retry = document.getElementById('bootRetry');
  if (tip) tip.textContent = errMsg || '无法连接大厅，请检查网络后重试';
  if (urlEl) urlEl.textContent = h5Url;
  if (retry) {
    retry.hidden = false;
    retry.onclick = () => {
      window.location.href = h5Url;
    };
  }
}

async function main() {
  await setupNativeChrome();

  // 构建时可由 Vite define 注入；默认读 query / 常量
  const fromQuery = new URLSearchParams(location.search).get('h5');
  const h5Url = normalizeUrl(fromQuery || window.__H5_URL__ || DEFAULT_H5);

  const tip = document.getElementById('bootTip');
  const urlEl = document.getElementById('bootUrl');
  if (tip) tip.textContent = '正在进入官方大厅…';
  if (urlEl) urlEl.textContent = h5Url;

  // 原生包已配置 server.url 时不会长期停在本页；本地预览则跳转
  if (!Capacitor.isNativePlatform()) {
    window.location.replace(h5Url);
    return;
  }

  // 原生兜底：若仍落到本地页，尝试跳到 H5
  setTimeout(() => {
    try {
      window.location.href = h5Url;
    } catch (e) {
      showFallback(h5Url, (e && e.message) || '跳转失败');
    }
  }, 400);

  setTimeout(() => {
    if (location.pathname.indexOf('index.html') >= 0 || location.protocol === 'capacitor:') {
      showFallback(h5Url);
    }
  }, 8000);
}

main();
