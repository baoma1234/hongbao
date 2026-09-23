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
