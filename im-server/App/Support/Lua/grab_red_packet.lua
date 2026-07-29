-- 抢红包原子脚本
-- KEYS[1] = queue list
-- KEYS[2] = grabbed set
-- KEYS[3] = meta hash (expire_at field)
-- ARGV[1] = user_id
-- ARGV[2] = now_ts
-- 返回: JSON string {code, msg, amount_cent, remain}

local queue = KEYS[1]
local grabbed = KEYS[2]
local meta = KEYS[3]
local uid = ARGV[1]
local now = tonumber(ARGV[2]) or 0

local expire_at = tonumber(redis.call('HGET', meta, 'expire_at') or '0') or 0
if expire_at > 0 and now > expire_at then
  return cjson.encode({code=410, msg='expired', amount_cent=0, remain=0})
end

if redis.call('SISMEMBER', grabbed, uid) == 1 then
  return cjson.encode({code=409, msg='already', amount_cent=0, remain=redis.call('LLEN', queue)})
end

local amount = redis.call('LPOP', queue)
if not amount then
  return cjson.encode({code=410, msg='empty', amount_cent=0, remain=0})
end

redis.call('SADD', grabbed, uid)
local remain = redis.call('LLEN', queue)
return cjson.encode({code=0, msg='ok', amount_cent=tonumber(amount), remain=remain})
