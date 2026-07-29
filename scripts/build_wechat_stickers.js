/**
 * 下载 wechat-emojis 资源并生成 public/888/data/stickers.json
 * node scripts/build_wechat_stickers.js
 */
const fs = require('fs');
const path = require('path');
const https = require('https');

const ROOT = path.join(__dirname, '..');
const OUT_DIR = path.join(ROOT, 'public', '888', 'stickers', 'wechat');
const MANIFEST = path.join(ROOT, 'public', '888', 'data', 'stickers.json');
const PKG = require(path.join(ROOT, 'node_modules', 'wechat-emojis', 'wechatEmoji.js'));
const CDN = 'https://unpkg.com/wechat-emojis@1.0.2/';

const CATEGORY_LABEL = {
  face: '笑脸',
  gesture: '手势',
  animal: '动物',
  blessing: '祝福',
  other: '其他',
};

function get(url) {
  return new Promise((resolve, reject) => {
    https.get(url, (res) => {
      if (res.statusCode >= 300 && res.statusCode < 400 && res.headers.location) {
        return get(res.headers.location).then(resolve, reject);
      }
      if (res.statusCode !== 200) {
        reject(new Error('HTTP ' + res.statusCode + ' ' + url));
        res.resume();
        return;
      }
      const chunks = [];
      res.on('data', (c) => chunks.push(c));
      res.on('end', () => resolve(Buffer.concat(chunks)));
    }).on('error', reject);
  });
}

async function main() {
  const all = PKG.getAllEmojis();
  const byCat = {};
  for (const item of all) {
    const cat = item.category || 'other';
    if (!byCat[cat]) byCat[cat] = [];
    const rel = String(item.path || '').replace(/^assets\//, '');
    const local = path.join(OUT_DIR, rel);
    const relPath = ('stickers/wechat/' + rel).replace(/\\/g, '/');
    byCat[cat].push({ code: item.name, url: relPath });
    fs.mkdirSync(path.dirname(local), { recursive: true });
    if (!fs.existsSync(local)) {
      const buf = await get(CDN + item.path.replace(/\\/g, '/'));
      fs.writeFileSync(local, buf);
      process.stdout.write('.');
    }
  }
  const manifest = {
    version: 1,
    packs: [{
      id: 'wechat',
      name: '经典表情',
      categories: Object.keys(CATEGORY_LABEL).filter((k) => byCat[k] && byCat[k].length).map((id) => ({
        id,
        name: CATEGORY_LABEL[id],
        items: byCat[id],
      })),
    }],
  };
  fs.mkdirSync(path.dirname(MANIFEST), { recursive: true });
  fs.writeFileSync(MANIFEST, JSON.stringify(manifest, null, 2), 'utf8');
  console.log('\nstickers:', all.length, '->', MANIFEST);
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
