-- 加速按时间清理聊天：fa_chat_messages(createtime, id)
-- 可重复执行前先检查索引是否存在
-- mysql ... < sql/chat_messages_createtime_index.sql

SET NAMES utf8mb4;

ALTER TABLE `fa_chat_messages`
  ADD INDEX `idx_createtime_id` (`createtime`, `id`);
