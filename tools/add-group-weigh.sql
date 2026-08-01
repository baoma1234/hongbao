-- 官方社群排序字段（其他服务器执行一次）
-- php tools/_add_group_weigh.php 也可
ALTER TABLE `fa_chat_groups`
  ADD COLUMN `weigh` INT NOT NULL DEFAULT 0 COMMENT '官方社群排序，越大越靠前' AFTER `is_recommend`;

UPDATE `fa_chat_groups` SET `weigh` = `id` WHERE IFNULL(`is_recommend`,0)=1 AND IFNULL(`weigh`,0)=0;
