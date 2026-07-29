<?php
/**
 * 旧本地 SHA-256 已作废。请使用 Im\Support\TronFair / TronBlockClient。
 * 保留本文件仅避免历史脚本 require 时报错。
 */
namespace Im\Support;

class FairProof
{
  public static function create($packetNo, $packetType, $poolCent, $count, $mineDigit, array $cents)
  {
    throw new \RuntimeException('local FairProof abolished; use TronFair');
  }

  public static function publicView(array $packet, array $records = [])
  {
    return TronFair::publicView($packet, $records);
  }
}
