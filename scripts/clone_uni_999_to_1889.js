const fs = require('fs')
const path = require('path')

const root = path.resolve(__dirname, '..')
const src = path.join(root, 'uni-999')
const dest = path.join(root, 'uni-1889')
const skipDirs = new Set(['node_modules', 'dist', 'unpackage'])
const textExts = new Set([
  '.js', '.ts', '.json', '.vue', '.html', '.css', '.md', '.txt', '.yml', '.yaml', '.d.ts'
])

const replaceRules = [
  [/hongbao-uni-999/g, 'hongbao-uni-1889'],
  [/uni-999/g, 'uni-1889'],
  [/红宝999/g, '红宝1889'],
  [/UNIHONGBAO999/g, 'UNIHONGBAO1889'],
  [/fans_hub_999_/g, 'fans_hub_1889_'],
  [/u999_/g, 'u1889_'],
  [/\/999\//g, '/1889/'],
  [/\/999\b/g, '/1889'],
  [/public\/999/g, 'public/1889'],
]

function rmDir(dir) {
  if (!fs.existsSync(dir)) return
  fs.rmSync(dir, { recursive: true, force: true })
}

function copyDir(from, to) {
  fs.mkdirSync(to, { recursive: true })
  for (const name of fs.readdirSync(from)) {
    if (skipDirs.has(name)) continue
    const a = path.join(from, name)
    const b = path.join(to, name)
    const st = fs.statSync(a)
    if (st.isDirectory()) {
      copyDir(a, b)
    } else {
      fs.copyFileSync(a, b)
    }
  }
}

function walk(dir, cb) {
  for (const name of fs.readdirSync(dir)) {
    const p = path.join(dir, name)
    const st = fs.statSync(p)
    if (st.isDirectory()) walk(p, cb)
    else cb(p)
  }
}

function replaceInFile(file) {
  const ext = path.extname(file).toLowerCase()
  if (!textExts.has(ext)) return
  let s = fs.readFileSync(file, 'utf8')
  let changed = false
  for (const [re, rep] of replaceRules) {
    const next = s.replace(re, rep)
    if (next !== s) changed = true
    s = next
  }
  if (changed) fs.writeFileSync(file, s)
}

if (!fs.existsSync(src)) {
  console.error('Missing source:', src)
  process.exit(1)
}

rmDir(dest)
copyDir(src, dest)
walk(dest, replaceInFile)
console.log('OK cloned', src, '->', dest)
