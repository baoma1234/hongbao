/** 底部导航未读角标（消息页写入） */
let total = 0

export function getChatUnreadTotal() {
  return Math.max(0, total | 0)
}

export function setChatUnreadTotal(n) {
  total = Math.max(0, n | 0)
  try {
    uni.$emit && uni.$emit('fanshub-tab-unread', total)
  } catch (e) {}
  return total
}
