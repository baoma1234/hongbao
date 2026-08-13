-- 加速按时间清理聊天：fa_chat_messages(createtime, id)
-- 推荐用幂等脚本：php scripts/patch_chat_messages_createtime_index.php
-- 或先检查索引是否存在再执行本 SQL

SET NAMES utf8mb4;

-- ALTER TABLE `fa_chat_messages`
--   ADD INDEX `idx_createtime_id` (`createtime`, `id`);
