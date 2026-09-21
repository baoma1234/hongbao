/**
 * Copy uni H5 build → public/999，并同步 uni-999/src/static/i18n 到 /999/i18n
 * 注入苹果 apple-touch-icon（OSS 高清图）
 */
const fs = require('fs')
const path = require('path')

const src = path.resolve(__dirname, '../dist/build/h5')
const dest = path.resolve(__dirname, '../../public/999')
const localeSrc = path.resolve(__dirname, '../src/static/i18n')
const localeDest = path.join(dest, 'i18n')
const APPLE_TOUCH_ICON =
  'https://888jhdhifhbchashjdl.oss-accelerate.aliyuncs.com/uploads/brand/apple-touch-icon-1024-v2.png'

function rmDir(dir) {
  if (!fs.existsSync(dir)) return
  try {
    fs.rmSync(dir, { recursive: true, force: true })
  } catch (e) {
    // Windows: 目录被占用时无法整夹删除，改为逐文件覆盖
    if (e && (e.code === 'EBUSY' || e.code === 'EPERM' || e.code === 'ENOTEMPTY')) {
      console.warn('WARN rmDir busy, overwrite instead:', dir, e.code)
      return false
    }
    throw e
  }
  return true
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
  // 统一主屏幕名 / 书签标题
  html = html.replace(
    /<meta\s+name="apple-mobile-web-app-title"\s+content="[^"]*"\s*\/?>/i,
    '<meta name="apple-mobile-web-app-title" content="红宝" />'
  )
  html = html.replace(/<title>[^<]*<\/title>/i, '<title>红宝</title>')
  if (html.indexOf('rel="apple-touch-icon"') < 0) {
    const tags =
      '\n    <meta name="apple-mobile-web-app-capable" content="yes" />\n' +
      '    <meta name="apple-mobile-web-app-title" content="红宝" />\n' +
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
    }
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
injectSpaPathGuard(path.join(dest, 'index.html'))

// index.html 禁止缓存；/999 SPA：假路径回退，避免 /999/pages/chat/ 被 PHP 404
fs.writeFileSync(
  path.join(dest, '.htaccess'),
  '# Prevent index.html cache mixing old/new CSS hashes\n' +
    '<IfModule mod_headers.c>\n' +
    '  <FilesMatch "^(index\\.html)$">\n' +
    '    Header set Cache-Control "no-cache, no-store, must-revalidate"\n' +
    '    Header set Pragma "no-cache"\n' +
    '    Header set Expires "0"\n' +
    '  </FilesMatch>\n' +
    '  <FilesMatch "\\.(js|css|png|jpg|jpeg|gif|svg|woff2?|ttf|ico)$">\n' +
    '    Header set Cache-Control "public, max-age=31536000, immutable"\n' +
    '  </FilesMatch>\n' +
    '</IfModule>\n' +
    '\n' +
    '# uni-app hash 路由：真实目录不存在的 /999/pages/* 回退到 index.html\n' +
    '<IfModule mod_rewrite.c>\n' +
    '  RewriteEngine On\n' +
    '  RewriteBase /999/\n' +
    '  RewriteRule ^index\\.html$ - [L]\n' +
    '  RewriteCond %{REQUEST_FILENAME} !-f\n' +
    '  RewriteCond %{REQUEST_FILENAME} !-d\n' +
    '  RewriteRule . /999/index.html [L]\n' +
    '</IfModule>\n'
)

console.log('OK copied to', dest)

function injectSpaPathGuard(htmlPath) {
  if (!fs.existsSync(htmlPath)) return
  let html = fs.readFileSync(htmlPath, 'utf8')
  if (html.indexOf('data-spa-path-guard') >= 0) return
  const snip =
    '\n    <script data-spa-path-guard="1">\n' +
    '      (function () {\n' +
    '        try {\n' +
    '          var p = String(location.pathname || "")\n' +
    '          // /999/pages/xxx → /999/#/pages/xxx （避免 Nginx/PHP 把分包路径当真实目录 404）\n' +
    '          var m = p.match(/^\\/999\\/(pages\\/.+)$/)\n' +
    '          if (!m) return\n' +
    '          var rest = m[1].replace(/\\/+$/, "")\n' +
    '          if (!rest) return\n' +
    '          var q = String(location.search || "")\n' +
    '          var h = String(location.hash || "")\n' +
    '          if (h && h.indexOf("#/") === 0) return\n' +
    '          location.replace("/999/#/" + rest + q)\n' +
    '        } catch (e) {}\n' +
    '      })()\n' +
    '    </script>\n'
  if (html.indexOf('<head>') >= 0) {
    html = html.replace('<head>', '<head>' + snip)
  } else {
    html = snip + html
  }
  fs.writeFileSync(htmlPath, html)
}
