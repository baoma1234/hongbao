/**
 * Copy uni H5 build → public/999，并同步 /888 多语言包到 /999/i18n
 */
const fs = require('fs')
const path = require('path')

const src = path.resolve(__dirname, '../dist/build/h5')
const dest = path.resolve(__dirname, '../../public/999')
const localeSrc = path.resolve(__dirname, '../../public/888/i18n')
const localeDest = path.join(dest, 'i18n')

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

console.log('OK copied to', dest)
