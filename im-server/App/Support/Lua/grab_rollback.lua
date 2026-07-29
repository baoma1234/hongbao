-- 抢红包失败时原子回滚：仅当用户仍在 grabbed 中才退回金额并解除占坑
-- KEYS[1] = queue list
-- KEYS[2] = grabbed set
-- ARGV[1] = user_id
-- ARGV[2] = amount_cent
-- 返回: 1=已回滚 0=无需回滚（用户已不在 grabbed）

local queue = KEYS[1]
local grabbed = KEYS[2]
local uid = ARGV[1]
local amount = ARGV[2]

if redis.call('SISMEMBER', grabbed, uid) == 1 then
  redis.call('LPUSH', queue, amount)
  redis.call('SREM', grabbed, uid)
  return 1
end
return 0
