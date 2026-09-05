/** 大厅游戏详情：与官方推荐群对齐（接龙四档 / 扫雷 / 牛牛 / 全员自由发宝） */

const JIELONG_BASE = {
  badge: 'hot',
  badgeText: '热门',
  hero: 'detail-01.png',
  intro:
    '经典接龙玩法，按顺序抢红包并接力发包。手气与策略并重，连击接龙可触发更高奖励倍率，适合群聊热闹互动。',
  rules: [
    '按顺序轮流抢红包，抢完需在规定时间续发下一包。',
    '未续包或超时将退出当前接龙，已得奖励保留。',
    '共有 20 / 50 / 100 / 500 四档红宝场次，按自身实力选择。',
    '公平随机算法开奖，全程可验。',
  ],
  startRoute: 'group',
}

function jielongDetail(tier) {
  const t = String(tier)
  return {
    ...JIELONG_BASE,
    id: 'jielong' + t,
    num: 1,
    title: '红宝接龙' + t + '群',
    groupMatch: new RegExp('红宝接龙\\s*' + t + '群'),
    sessions: [{ tag: t + '红宝场', mult: t, entry: t, max: '—' }],
    fixedSession: 0,
  }
}

export const LOBBY_GAME_DETAILS = {
  jielong20: jielongDetail(20),
  jielong50: jielongDetail(50),
  jielong100: jielongDetail(100),
  jielong500: jielongDetail(500),
  /** 兼容旧链接 */
  jielong: {
    ...JIELONG_BASE,
    id: 'jielong',
    num: 1,
    title: '红包接龙',
    groupMatch: /红宝接龙\s*20群/,
    sessions: [
      { tag: '20红宝场', mult: '20', entry: '20', max: '—', groupMatch: /红宝接龙\s*20群/ },
      { tag: '50红宝场', mult: '50', entry: '50', max: '—', groupMatch: /红宝接龙\s*50群/ },
      { tag: '100红宝场', mult: '100', entry: '100', max: '—', groupMatch: /红宝接龙\s*100群/ },
      { tag: '500红宝场', mult: '500', entry: '500', max: '—', groupMatch: /红宝接龙\s*500群/ },
    ],
    startRoute: 'group',
  },
  saolei: {
    id: 'saolei',
    num: 2,
    title: '红包扫雷',
    badge: 'hot',
    badgeText: '热门',
    hero: 'detail-02.png',
    groupMatch: /扫雷/,
    intro:
      '经典扫雷玩法，点击格子找出数字或避开地雷，成功避开所有地雷即可获得倍率奖励。紧张刺激，考验运气与策略！',
    rules: [
      '点击格子，如果是地雷则游戏结束。',
      '数字表示周围地雷数量，帮助判断安全格。',
      '成功翻开所有安全格后获得对应奖励。',
      '场次金额区间 10–1000 红宝，自由选择入场金额。',
    ],
    sessions: [{ tag: '自由场', mult: '10-1000', entry: '10-1000', max: '—' }],
    startRoute: 'group',
  },
  niuniu: {
    id: 'niuniu',
    num: 3,
    title: '红包牛牛',
    badge: '',
    badgeText: '',
    hero: 'detail-03.png',
    groupMatch: /牛牛/,
    intro:
      '经典牛牛比牌玩法，五张牌凑牛型比大小。牌型越大奖励越高，支持快速多局对战，节奏紧凑、刺激上头。',
    rules: [
      '系统自动发牌，五张牌组成牛型比大小。',
      '牛九、牛牛等特殊牌型有额外倍率加成。',
      '每局独立结算，赢家通吃或按规则分配奖池。',
      '当前开放标准牛牛场，进入群聊即可开局。',
    ],
    sessions: [{ tag: '牛牛场', mult: '标准', entry: '标准', max: '—' }],
    startRoute: 'group',
  },
  /** 全员自动发包群 = 全员自由发宝群10-1000 */
  freeall: {
    id: 'freeall',
    num: 4,
    title: '全员自由发宝群10-1000',
    badge: '',
    badgeText: '',
    hero: 'detail-04.png',
    groupMatch: /全员自由发宝|全员自动发包/,
    intro:
      '全员自由发包玩法，群内任意成员可按规则发宝与抢包。场次金额 10–1000 红宝自由选择，热闹互动、节奏自定。',
    rules: [
      '进入「全员自由发宝群10-1000」即可参与。',
      '按群内规则自由发包、抢包，金额区间 10–1000 红宝。',
      '公平随机算法开奖，全程可验。',
      '请遵守群规，理性娱乐。',
    ],
    sessions: [{ tag: '自由场', mult: '10-1000', entry: '10-1000', max: '—' }],
    startRoute: 'group',
  },
  /** 兼容旧 id */
  battle: null,
  yxx: {
    id: 'yxx',
    num: 6,
    title: '趣味鱼虾蟹',
    badge: '',
    badgeText: '',
    hero: 'detail-06.png',
    comingSoon: true,
    intro:
      '经典鱼虾蟹骰子玩法，押中图案即可获奖。链上哈希公平开奖，支持多面押注与实时奖池，轻松上手、节奏飞快。',
    rules: [
      '选择鱼/虾/蟹/葫芦/鸡/虎等图案下注。',
      '每局链上哈希映射骰子结果，公开可验。',
      '押中对应图案按倍率获得奖励。',
      '玩法即将开放，敬请期待。',
    ],
    sessions: [{ tag: '敬请期待', mult: '—', entry: '—', max: '—' }],
    startRoute: 'yxx',
  },
}

LOBBY_GAME_DETAILS.battle = LOBBY_GAME_DETAILS.freeall

export function getLobbyGameDetail(id) {
  const key = String(id || '').trim()
  return LOBBY_GAME_DETAILS[key] || null
}
