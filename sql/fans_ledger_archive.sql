-- 资金流水冷表（推荐用脚本自动建：php scripts/archive_fans_ledger.php --days=90）
-- 与热表同结构，额外 archived_at；保留原 id。

CREATE TABLE IF NOT EXISTS `fa_fans_ledger_archive` LIKE `fa_fans_ledger`;
-- 若缺列再执行：
-- ALTER TABLE `fa_fans_ledger_archive`
--   ADD COLUMN `archived_at` int unsigned NOT NULL DEFAULT 0 COMMENT 'archived unix' AFTER `createtime`,
--   ADD KEY `idx_archived_at` (`archived_at`),
--   ADD KEY `idx_createtime_id` (`createtime`,`id`);
