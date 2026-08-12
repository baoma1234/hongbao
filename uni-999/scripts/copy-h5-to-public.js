/**
 * Copy uni H5 build → public/999，并同步 /888 多语言包到 /999/i18n
 * 注入苹果 apple-touch-icon（OSS 高清图）
 */
const fs = require('fs')
const path = require('path')

const src = path.resolve(__dirname, '../dist/build/h5')
const dest = path.resolve(__dirname, '../../public/999')
const localeSrc = path.resolve(__dirname, '../../public/888/i18n')
const localeDest = path.join(dest, 'i18n')
const APPLE_TOUCH_ICON =
  'https://888jhdhifhbchashjdl.oss-accelerate.aliyuncs.com/uploads/brand/apple-touch-icon-1024.png'

function rmDir(dir) {
  if (!fs.existsSync(dir)) return
  fs.rmSync(dir, { recursive: true, force: true })
}

function copyDir(from, to) {
  fs.mkdirSync(to, { recursive: true })
  for (const name of fs.readdirSync(from)) {
    const a = path.join(from, name)
    const b = path.join(to, name)
    if (fs.statSync(a).isDirectory()) copyDir(a, b)
    else fs.copyFileSync(a, b)
  }
}

function ensureAppleTouchIcon(htmlPath) {
  if (!fs.existsSync(htmlPath)) return
  let html = fs.readFileSync(htmlPath, 'utf8')
  if (html.indexOf('rel="apple-touch-icon"') >= 0) return
  const tags =
    '\n    <meta name="apple-mobile-web-app-capable" content="yes" />\n' +
    '    <meta name="apple-mobile-web-app-title" content="抢红包" />\n' +
    '    <link rel="apple-touch-icon" sizes="180x180" href="' +
    APPLE_TOUCH_ICON +
    '" />\n' +
    '    <link rel="apple-touch-icon" sizes="1024x1024" href="' +
    APPLE_TOUCH_ICON +
    '" />\n' +
    '    <link rel="icon" type="image/png" href="' +
    APPLE_TOUCH_ICON +
    '" />\n'
  if (html.indexOf('</title>') >= 0) {
    html = html.replace('</title>', '</title>' + tags)
  } else if (html.indexOf('<head>') >= 0) {
    html = html.replace('<head>', '<head>' + tags)
  } else {
    return
  }
  fs.writeFileSync(htmlPath, html)
}

if (!fs.existsSync(src)) {
  console.error('Build output missing:', src)
  process.exit(1)
}
rmDir(dest)
copyDir(src, dest)

if (fs.existsSync(localeSrc)) {
  copyDir(localeSrc, localeDest)
  console.log('OK i18n synced to', localeDest)
} else {
  console.warn('WARN missing locale source:', localeSrc)
}

ensureAppleTouchIcon(path.join(dest, 'index.html'))
console.log('OK copied to', dest)
