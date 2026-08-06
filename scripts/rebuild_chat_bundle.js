const fs = require('fs');
const path = require('path');
const dir = path.join(__dirname, '../public/888/js/chat');
const names = ['01-core.js', '02-room.js', '03-rp.js', '04-net.js'];
const parts = names.map((f) => fs.readFileSync(path.join(dir, f), 'utf8'));
const code =
  '(function (global) {\n' +
  '"use strict";\n' +
  parts.map((p, i) => '/* === ' + names[i] + ' === */\n' + p).join('\n') +
  '\n})(window);\n';
fs.writeFileSync(path.join(dir, 'chat.bundle.js'), code, 'utf8');
console.log('rebuilt chat.bundle.js', Buffer.byteLength(code), 'bytes');
