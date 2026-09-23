/**
 * OG视讯 · 转账钱包（与后端 /api/fanshub/og* 对齐）
 *
 * 字段映射：
 * - profile.user_id  → player_id = u{user_id}
 * - profile.nickname → og_nickname（仅字母数字下划线；中文回退 n{user_id}）
 * - profile.hongbao  → 存入扣款来源
 */
import { apiRequest } from './auth.js'

/** 查询当前用户与 OG 的映射快照 */
export function ogPlayer() {
  return apiRequest('ogplayer', 'POST', {})
}

/** 注册当前用户到 OG（已注册则幂等成功） */
export function ogRegister() {
  return apiRequest('ogregister', 'POST', {})
}

/**
 * 从本站红宝存入 OG
 * @param {number|string} amount
 * @returns {Promise<{transaction_id:string,transfer_amount:string,balance:string,hongbao:number,rs_code:string}>}
 * 成功 rs_code=S-100（或 S-101 重复已入账）；失败抛错（含 S-104 等）
 */
export function ogDeposit(amount) {
  const n = Number(amount)
  if (!(n > 0)) {
    return Promise.reject(new Error('请输入有效存入金额'))
  }
  return apiRequest('ogdeposit', 'POST', { amount: n })
}

/**
 * 从 OG 提出到本站红宝
 * @param {number|string} amount
 * @returns {Promise<{transaction_id:string,transfer_amount:string,balance:string,hongbao:number,rs_code:string}>}
 * 成功 S-100/S-101；S-103 余额不足会抛错
 */
export function ogWithdraw(amount) {
  const n = Number(amount)
  if (!(n > 0)) {
    return Promise.reject(new Error('请输入有效提出金额'))
  }
  return apiRequest('ogwithdraw', 'POST', { amount: n })
}

/**
 * OG 转账历史（自动带当前用户 player_id，并同步本站流水）
 * @param {{ fetch_id?: number, limit?: number, transaction_id?: string, sync?: boolean }} [opts]
 * @returns {Promise<{records:Array,local:Array,last_fetch_id:number,player_id:string,synced:number}>}
 */
export function ogTransferHistory(opts = {}) {
  const body = {
    fetch_id: opts.fetch_id != null ? Number(opts.fetch_id) || 1 : 1,
    limit: opts.limit != null ? Number(opts.limit) || 100 : 100,
    sync: opts.sync === false ? 0 : 1,
  }
  const txid = String(opts.transaction_id || '').trim()
  if (txid) body.transaction_id = txid
  return apiRequest('ogtransferhistory', 'POST', body)
}

/**
 * OG 可用游戏列表（正式/沙箱 game_id 不同，列表会缓存约 5 分钟）
 * @param {{ game_id?: number, game_name?: string, game_type?: string, refresh?: boolean }} [opts]
 * @returns {Promise<{records:Array<{game_id:number,game_type:string,game_name:string,image:string}>,sandbox:boolean,fetched_at:number}>}
 */
export function ogGameList(opts = {}) {
  const body = {
    refresh: opts.refresh ? 1 : 0,
  }
  if (opts.game_id != null && opts.game_id !== '') body.game_id = Number(opts.game_id) || 0
  const name = String(opts.game_name || '').trim()
  if (name) body.game_name = name
  const type = String(opts.game_type || '').trim()
  if (type) body.game_type = type
  return apiRequest('oggamelist', 'POST', body)
}

/**
 * OG 限红组列表（正式/沙箱 id 不同；全量缓存约 5 分钟）
 * @param {{ id?: number, refresh?: boolean }} [opts]
 * @returns {Promise<{records:Array<{id:number,min_limit:string,max_limit:string}>,sandbox:boolean,fetched_at:number}>}
 */
export function ogBetLimit(opts = {}) {
  const body = {
    refresh: opts.refresh ? 1 : 0,
  }
  if (opts.id != null && opts.id !== '') body.id = Number(opts.id) || 0
  return apiRequest('ogbetlimit', 'POST', body)
}

/**
 * 进入 OG 游戏，返回 game_link（前端自行打开 webview / 外链）
 * @param {{ game_id: number, betlimit?: number, lang?: string, extra?: string }} opts
 * @returns {Promise<{game_link:string,game_id:number,betlimit:number,player_id:string,token:string}>}
 */
export function ogLaunch(opts = {}) {
  const gameId = Number(opts.game_id) || 0
  if (gameId <= 0) {
    return Promise.reject(new Error('请选择游戏'))
  }
  const body = { game_id: gameId }
  const bet = Number(opts.betlimit) || 0
  if (bet > 0) body.betlimit = bet
  const lang = String(opts.lang || '').trim()
  if (lang) body.lang = lang
  const extra = String(opts.extra || '').trim()
  if (extra) body.extra = extra
  return apiRequest('oglaunch', 'POST', body)
}

/**
 * 查询当前用户 OG 筹码余额（顺带返回本站 hongbao）
 * @returns {Promise<{player_id:string,current_balance:string,og_balance:string,hongbao:number}>}
 */
export function ogBalance() {
  return apiRequest('ogbalance', 'POST', {})
}
