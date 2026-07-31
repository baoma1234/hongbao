-- 会话列表加速索引（可重复执行）
-- mysql ... < sql/chat_im_perf_indexes.sql

SET NAMES utf8mb4;

-- 私聊按发送方 / 接收方聚合最近消息（配合 UNION 冷启动路径）
ALTER TABLE `fa_chat_messages`
  ADD INDEX `idx_priv_from_conv` (`conversation_type`, `status`, `from_user_id`, `conversation_id`, `id`),
  ADD INDEX `idx_priv_to_conv` (`conversation_type`, `status`, `to_user_id`, `conversation_id`, `id`);
