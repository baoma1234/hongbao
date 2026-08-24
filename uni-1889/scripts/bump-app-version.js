/**
 * APK / IPA 发版：versionName +0.1，versionCode +1
 * 例：0.1.0 → 0.2.0 → … → 0.9.0 → 1.0.0
 *
 * 用法：npm run bump:app
 * 打原生包前执行一次；勿在仅 build:h5 时跑。
 */
const fs = require('fs')
const path = require('path')

const root = path.resolve(__dirname, '..')
const manifestPath = path.join(root, 'src', 'manifest.json')
const pkgPath = path.join(root, 'package.json')

function bumpVersionName(name) {
  const s = String(name || '').trim()
  const m = s.match(/^(\d+)\.(\d+)(?:\.(\d+))?$/)
  if (!m) {
    throw new Error('invalid versionName: ' + s)
  }
  let major = parseInt(m[1], 10)
  let minor = parseInt(m[2], 10) + 1
  if (minor >= 10) {
    major += 1
    minor = 0
  }
  // 保持三段式，与当前 manifest 一致
  return major + '.' + minor + '.0'
}

function bumpVersionCode(code) {
  const n = parseInt(String(code || '0'), 10)
  if (!Number.isFinite(n) || n < 0) {
    throw new Error('invalid versionCode: ' + code)
  }
  return String(n + 1)
}

function patchManifest(text, versionName, versionCode) {
  let out = text
  if (!/"versionName"\s*:\s*"[^"]+"/.test(out)) {
    throw new Error('manifest.json missing versionName')
  }
  if (!/"versionCode"\s*:\s*"[^"]+"/.test(out)) {
    throw new Error('manifest.json missing versionCode')
  }
  out = out.replace(/"versionName"\s*:\s*"[^"]+"/, '"versionName" : "' + versionName + '"')
  out = out.replace(/"versionCode"\s*:\s*"[^"]+"/, '"versionCode" : "' + versionCode + '"')
  return out
}

const raw = fs.readFileSync(manifestPath, 'utf8')
const man = JSON.parse(raw)
const oldName = String(man.versionName || '')
const oldCode = String(man.versionCode || '')
const nextName = bumpVersionName(oldName)
const nextCode = bumpVersionCode(oldCode)

fs.writeFileSync(manifestPath, patchManifest(raw, nextName, nextCode), 'utf8')

const pkg = JSON.parse(fs.readFileSync(pkgPath, 'utf8'))
pkg.version = nextName
fs.writeFileSync(pkgPath, JSON.stringify(pkg, null, 2) + '\n', 'utf8')

console.log('app version: ' + oldName + ' (' + oldCode + ') → ' + nextName + ' (' + nextCode + ')')
