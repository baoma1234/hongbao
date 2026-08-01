-- 余额并入红宝（其他服务器可执行；也可用 php tools/merge_balance_to_hongbao.php）
-- 建议先备份

INSERT INTO fa_fans_ledger
  (user_id, type, rights_change, balance_change, hongbao_change, rights_after, balance_after, hongbao_after, remark, channel, admin_id, createtime)
SELECT
  user_id,
  'balance_merge_hongbao',
  0,
  -ROUND(balance, 2),
  ROUND(balance, 2),
  rights,
  0,
  ROUND(IFNULL(hongbao,0) + balance, 2),
  '余额并入红宝',
  'system',
  0,
  UNIX_TIMESTAMP()
FROM fa_fans_account
WHERE IFNULL(balance, 0) <> 0;

UPDATE fa_fans_account
SET hongbao = ROUND(IFNULL(hongbao, 0) + IFNULL(balance, 0), 2),
    balance = 0
WHERE IFNULL(balance, 0) <> 0;
