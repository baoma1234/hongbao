const fs = require('fs');
const bakPath = 'c:/wwwroot/caijin.com_7111/public/888/chat.js.bak';
const outDir = 'c:/wwwroot/caijin.com_7111/public/888/js/chat/';
const bak = fs.readFileSync(bakPath, 'utf8').split(/\n/);
const nl = fs.readFileSync(bakPath).includes(Buffer.from('\r\n')) ? '\r\n' : '\n';

const marks = {};
bak.forEach((line, i) => {
  const m = line.match(/^\s*function (renderList|getRpPacketType|startPing)\b/);
  if (m) marks[m[1]] = i + 1;
  if (/^\s*global\.FansHubChat\s*=/.test(line)) marks.FansHubChat = i + 1;
  if (/^\}\)\(window\);?\s*$/.test(line)) marks.close = i + 1;
  if (/^\s*var READ_KEY\b/.test(line)) marks.READ_KEY = i + 1;
});
console.log('marks', marks);

const start = marks.READ_KEY;
const cut1 = marks.renderList - 1;
const cut2 = marks.getRpPacketType - 1;
const cut3 = marks.startPing - 1;
let end = marks.close - 1;
for (let i = marks.FansHubChat - 1; i < bak.length; i++) {
  if (/^\s*\};\s*$/.test(bak[i])) {
    end = i + 1;
    break;
  }
}
console.log({ start, cut1, cut2, cut3, end });

function writePart(name, a, b, desc) {
  const lines = bak.slice(a - 1, b);
  fs.writeFileSync(outDir + name, `/* js/chat/${name} — ${desc} */${nl}${nl}${lines.join('\n')}`, 'utf8');
  console.log(
    'wrote',
    name,
    a + '-' + b,
    '| head:',
    (lines[0] || '').trim().slice(0, 50),
    '| tail:',
    (lines[lines.length - 1] || '').trim().slice(0, 50)
  );
}

writePart('01-core.js', start, cut1, 'state, utils, unread');
writePart('02-room.js', cut1 + 1, cut2, 'list, room, group, media');
writePart('03-rp.js', cut2 + 1, cut3, 'red packet send/grab');
writePart('04-net.js', cut3 + 1, end, 'ws, bindUi, exports');

const parts = ['01-core.js', '02-room.js', '03-rp.js', '04-net.js'].map((f) =>
  fs.readFileSync(outDir + f, 'utf8')
);
const code =
  '(function (global) {' + nl + "'use strict';" + nl + parts.join(nl) + nl + '})(window);';
fs.writeFileSync(outDir + '_syntax_check.js', code, 'utf8');
let bal = 0;
for (const ch of parts.join('')) {
  if (ch === '{') bal += 1;
  if (ch === '}') bal -= 1;
}
console.log('brace', bal);
