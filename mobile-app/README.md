# 红宝 App 壳（Capacitor）

用 WebView 加载线上 H5 大厅，业务仍在服务器的 `/888/`，改 H5 不用重发 App（除非改壳本身）。

## 1. 改正式地址

编辑 `app.config.json`：

```json
{
  "appId": "com.qianghongbao.app",
  "appName": "红宝",
  "h5Url": "https://你的正式域名/888/",
  "allowNavigation": ["你的正式域名", "*.你的正式域名"]
}
```

也可用环境变量临时覆盖：

```bash
set H5_URL=https://example.com/888/
npm run sync
```

## 2. 安装依赖

```bash
cd mobile-app
npm install
```

## 3. 添加安卓工程并打开 Android Studio

本机需安装：

- [Android Studio](https://developer.android.com/studio)（自带 SDK / JDK）
- 首次打开 Android Studio 完成 SDK 下载

```bash
npm run android:add
npm run android:open
```

在 Android Studio：

1. 等待 Gradle Sync 完成  
2. **Build → Build Bundle(s) / APK(s) → Build APK(s)** 得到调试包  
3. 正式分发：**Build → Generate Signed Bundle / APK**  
   - 内测侧载选 **APK**  
   - Google Play 选 **Android App Bundle (.aab)**  
4. 创建并保管好 keystore（丢失无法覆盖安装升级）

输出目录一般在：

`android/app/release/` 或 Android Studio 提示的路径。

## 4. 打 IPA（必须 Mac + Apple 开发者账号）

```bash
npm run ios:add
npm run ios:open
```

用 Xcode：配 Bundle ID、证书、Archive 导出 IPA / 上传 App Store Connect。

Windows 无法本地签正式 IPA。

## 5. 常用命令

| 命令 | 作用 |
|------|------|
| `npm run build` | 构建本地兜底页到 `dist/` |
| `npm run sync` | 构建并同步到 android/ios |
| `npm run android:open` | 同步后打开 Android Studio |
| `npm start` | 浏览器预览兜底页（会跳转 h5Url） |

## 6. 后台下载链接

打出 APK 后，把安装包放到 CDN/对象存储，再把后台配置里的：

`application/extra/fanshub.php` → `app_download_url`

改成真实下载地址（领取弹窗「下载官方红宝聊天App」会用到）。

## 7. 注意

- H5 与 API 必须 **HTTPS**，IM 用 **WSS**  
- `appId`（包名）上架后不要改  
- 壳已处理安卓返回键；站外链接可在 H5 里调 `window.openExternal?.(url)`（原生环境）  
- 当前环境若未装 JDK/Android SDK，可先 `npm install` + `npx cap add android` 生成工程，再到装了 Android Studio 的机器上打包
