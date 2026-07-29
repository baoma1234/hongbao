-- 三资产互兑：股份(rights) / 红利(balance) / 红宝(hongbao)
SET NAMES utf8mb4;

ALTER TABLE `fa_fans_account`
  ADD COLUMN IF NOT EXISTS `hongbao` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '红宝余额' AFTER `balance`;

ALTER TABLE `fa_fans_ledger`
  ADD COLUMN IF NOT EXISTS `hongbao_change` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '红宝变动' AFTER `balance_change`,
  ADD COLUMN IF NOT EXISTS `hongbao_after` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '红宝结余' AFTER `balance_after`;
