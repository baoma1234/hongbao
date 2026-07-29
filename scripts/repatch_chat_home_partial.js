const fs = require('fs');

function patch(path, pairs) {
  let s = fs.readFileSync(path, 'utf8');
  for (const [oldStr, newStr, label] of pairs) {
    if (s.includes(newStr.slice(0, Math.min(60, newStr.length))) && label !== 'force') {
      // already maybe partially there - still try exact old
    }
    if (!s.includes(oldStr)) {
      console.error('FAIL', path, label || 'block');
      process.exit(1);
    }
    s = s.replace(oldStr, newStr);
    console.log('OK', label || path);
  }
  fs.writeFileSync(path, s);
}

const core = 'c:/wwwroot/caijin.com_7111/public/888/js/chat/01-core.js';
patch(core, [[
`    listKeyword: '',
    listSearchTimer: null,
    senderCache: {},`,
`    listKeyword: '',
    listSearchTimer: null,
    homeTab: 'chat',
    recommendGroups: [],
    myGroups: [],
    friends: [],
    senderCache: {},`,
'state'
]]);

const room = 'c:/wwwroot/caijin.com_7111/public/888/js/chat/02-room.js';
patch(room, [[
`  function closeGroupSubPanes() {
    ['chatGroupSettingsPane', 'chatGroupMembersPane', 'chatGroupInvitePane'].forEach(function (id) {`,
`  function closeGroupSubPanes() {
    ['chatGroupSettingsPane', 'chatGroupMembersPane', 'chatGroupInvitePane', 'chatAddFriendPane'].forEach(function (id) {`,
'closeSub'
]]);

console.log('core/room patched');
