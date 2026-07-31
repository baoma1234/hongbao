-- 会话/未读/历史再加速（可重复执行需自行跳过已存在索引）
SET NAMES utf8mb4;

-- 历史 + 按会话未读 COUNT：覆盖 status 过滤
ALTER TABLE `fa_chat_messages`
  ADD INDEX `idx_conv_status_id` (`conversation_type`, `conversation_id`, `status`, `id`);

-- 已读游标按用户批量拉取（conversation.list 已读）
ALTER TABLE `fa_chat_conversation_read`
  ADD INDEX `idx_user_conv` (`user_id`, `conversation_type`, `conversation_id`);
