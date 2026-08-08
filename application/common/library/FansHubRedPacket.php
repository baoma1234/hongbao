<?php

namespace app\common\library;

use think\Db;

/**
 * 红包后台/配置公共逻辑
 */
class FansHubRedPacket
{
    public static function defaultConfig()
    {
        return [
            'min_amount'                => '10.00',
            'min_count'                 => '5',
            'max_count'                 => '10',
            'vip_min_count'             => '5',
            'vip_max_count'             => '10',
            'platform_fee_rate'         => '0.0300',
            'agent_rebate_rate_default' => '0.0100',
            'agent_rebate_rate_vip'     => '0.0100',
            'invite_rebate_rate'        => '0.0050',
            'expire_seconds'                  => '60',
            'mine_expire_seconds'             => '180',
            'platform_user_id'                => '56960815',
            'group_robot_user_id'             => '74282747',
            'mine_compensate_rate_5'          => '1.5000',
            'mine_compensate_rate_7'          => '1.2000',
            'mine_compensate_rate_9'          => '1.0000',
            'mine_platform_fee_rate'          => '0.0300',
            'mine_agent_rebate_rate_default'  => '0.0100',
            'mine_agent_rebate_rate_vip'      => '0.0100',
            'mine_invite_rebate_rate'         => '0.0050',
            'mine_platform_user_id'           => '56960815',
            // 普通用户群红宝（普通均分 / 随机）：与扫雷、拼手气配置隔离
            'user_rp_expire_seconds'            => '1800',
            'user_rp_min_amount'                => '10.00',
            'user_rp_min_count'                 => '1',
            'user_rp_max_count'                 => '100',
            'user_rp_platform_fee_rate'         => '0.0300',
            'user_rp_agent_rebate_rate_default' => '0.0100',
            'user_rp_agent_rebate_rate_vip'     => '0.0100',
            'user_rp_invite_rebate_rate'        => '0.0050',
            'user_rp_platform_user_id'          => '56960815',
            // 接龙红包（单独配置；最少续发）
            'relay_expire_seconds'             => '1800',
            'relay_min_amount'                 => '10.00',
            'relay_min_count'                  => '5',
            'relay_max_count'                  => '10',
            'relay_vip_min_count'              => '5',
            'relay_vip_max_count'              => '10',
            'relay_platform_fee_rate'          => '0.0300',
            'relay_agent_rebate_rate_default'  => '0.0100',
            'relay_agent_rebate_rate_vip'      => '0.0100',
            'relay_invite_rebate_rate'         => '0.0050',
            'relay_platform_user_id'           => '56960815',
            // 红宝尾数牛牛（单独全局配置）
            'niuniu_share_price'              => '100.00',
            'niuniu_buy_seconds'              => '120',
            'niuniu_claim_seconds'            => '60',
            'niuniu_fee_rate'                 => '0.0300',
            'niuniu_pool_rate'                => '0.6000',
            'niuniu_secondary_rate'           => '0.4000',
            'niuniu_platform_user_id'         => '56960815',
            'niuniu_drand_api'                => 'https://api.drand.sh',
            'niuniu_drand_period'             => '30',
            'niuniu_enabled_global'           => '1',
            'niuniu_rule_text'                => "1. 参与得1份资格，消耗100积分进入奖池，单人份数不限\n2. 购入结束后手动点击领取红包才展示尾数牛数；红包仅用于比对，金额不入账\n3. 牛牛瓜分可分配池60%，牛7-9瓜分40%；牛1-6无奖金\n4. 无牛牛时60%并入次级；无次级时40%并入牛牛；全低流局扣费后退回\n5. 倒计时结束0份则作废，不扣手续费",
            'skin_width'                      => '750',
            'skin_height'                     => '1000',
        ];
    }

    public static function configMap()
    {
        $defaults = self::defaultConfig();
        $rows = [];
        try {
            $list = Db::name('chat_red_packet_config')->select();
            foreach ($list as $row) {
                $rows[(string)$row['cfg_key']] = (string)$row['cfg_value'];
            }
        } catch (\Throwable $e) {
            // table may not exist yet
        }
        return array_merge($defaults, $rows);
    }

    public static function get($key, $default = null)
    {
        $map = self::configMap();
        return array_key_exists($key, $map) ? $map[$key] : $default;
    }

    /**
     * @param array $data key=>value
     * @param array $remarks optional key=>remark
     */
    public static function saveConfig(array $data, array $remarks = [])
    {
        $now = time();
        $allowed = array_keys(self::defaultConfig());
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $val = trim((string)$data[$key]);
            $remark = (string)($remarks[$key] ?? '');
            $exist = Db::name('chat_red_packet_config')->where('cfg_key', $key)->find();
            if ($exist) {
                Db::name('chat_red_packet_config')->where('id', $exist['id'])->update([
                    'cfg_value'  => $val,
                    'remark'     => $remark !== '' ? $remark : $exist['remark'],
                    'updatetime' => $now,
                ]);
            } else {
                Db::name('chat_red_packet_config')->insert([
                    'cfg_key'    => $key,
                    'cfg_value'  => $val,
                    'remark'     => $remark,
                    'updatetime' => $now,
                ]);
            }
        }
        self::syncImRuntimeConfig();
    }

    /**
     * 同步到 IM 进程可读的 runtime 覆盖文件（重启 IM 后生效）
     */
    public static function syncImRuntimeConfig()
    {
        $map = self::configMap();
        $rp = [
            'expire_seconds'                 => max(1, (int)$map['expire_seconds']),
            'mine_expire_seconds'            => max(1, (int)($map['mine_expire_seconds'] ?? 180)),
            'platform_fee_rate'              => (float)$map['platform_fee_rate'],
            'agent_rebate_rate_default'      => (float)$map['agent_rebate_rate_default'],
            'agent_rebate_rate_vip'          => (float)$map['agent_rebate_rate_vip'],
            'invite_rebate_rate'             => (float)($map['invite_rebate_rate'] ?? 0.005),
            'platform_user_id'               => (int)$map['platform_user_id'],
            'group_robot_user_id'            => (int)($map['group_robot_user_id'] ?? 74282747),
            'mine_compensate_rate_5'         => max(0.01, (float)($map['mine_compensate_rate_5'] ?? 1.5)),
            'mine_compensate_rate_7'         => max(0.01, (float)($map['mine_compensate_rate_7'] ?? 1.2)),
            'mine_compensate_rate_9'         => max(0.01, (float)($map['mine_compensate_rate_9'] ?? 1.0)),
            'mine_platform_fee_rate'         => (float)($map['mine_platform_fee_rate'] ?? $map['platform_fee_rate']),
            'mine_agent_rebate_rate_default' => (float)($map['mine_agent_rebate_rate_default'] ?? $map['agent_rebate_rate_default']),
            'mine_agent_rebate_rate_vip'     => (float)($map['mine_agent_rebate_rate_vip'] ?? $map['agent_rebate_rate_vip']),
            'mine_invite_rebate_rate'        => (float)($map['mine_invite_rebate_rate'] ?? ($map['invite_rebate_rate'] ?? 0.005)),
            'mine_platform_user_id'          => (int)($map['mine_platform_user_id'] ?? $map['platform_user_id']),
            'user_rp_expire_seconds'            => max(1, (int)($map['user_rp_expire_seconds'] ?? 1800)),
            'user_rp_min_amount'                => (float)($map['user_rp_min_amount'] ?? $map['min_amount']),
            'user_rp_min_count'                 => max(1, (int)($map['user_rp_min_count'] ?? 1)),
            'user_rp_max_count'                 => max(1, (int)($map['user_rp_max_count'] ?? 100)),
            'user_rp_platform_fee_rate'         => (float)($map['user_rp_platform_fee_rate'] ?? $map['platform_fee_rate']),
            'user_rp_agent_rebate_rate_default' => (float)($map['user_rp_agent_rebate_rate_default'] ?? $map['agent_rebate_rate_default']),
            'user_rp_agent_rebate_rate_vip'     => (float)($map['user_rp_agent_rebate_rate_vip'] ?? $map['agent_rebate_rate_vip']),
            'user_rp_invite_rebate_rate'        => (float)($map['user_rp_invite_rebate_rate'] ?? ($map['invite_rebate_rate'] ?? 0.005)),
            'user_rp_platform_user_id'          => (int)($map['user_rp_platform_user_id'] ?? $map['platform_user_id']),
            'relay_expire_seconds'             => max(1, (int)($map['relay_expire_seconds'] ?? 1800)),
            'relay_min_amount'                 => (float)($map['relay_min_amount'] ?? $map['min_amount']),
            'relay_min_count'                  => max(1, (int)($map['relay_min_count'] ?? $map['min_count'] ?? 5)),
            'relay_max_count'                  => max(1, (int)($map['relay_max_count'] ?? $map['max_count'] ?? 10)),
            'relay_vip_min_count'              => max(1, (int)($map['relay_vip_min_count'] ?? $map['vip_min_count'] ?? 5)),
            'relay_vip_max_count'              => max(1, (int)($map['relay_vip_max_count'] ?? $map['vip_max_count'] ?? 10)),
            'relay_platform_fee_rate'          => (float)($map['relay_platform_fee_rate'] ?? $map['platform_fee_rate']),
            'relay_agent_rebate_rate_default'  => (float)($map['relay_agent_rebate_rate_default'] ?? $map['agent_rebate_rate_default']),
            'relay_agent_rebate_rate_vip'      => (float)($map['relay_agent_rebate_rate_vip'] ?? $map['agent_rebate_rate_vip']),
            'relay_invite_rebate_rate'         => (float)($map['relay_invite_rebate_rate'] ?? ($map['invite_rebate_rate'] ?? 0.005)),
            'relay_platform_user_id'           => (int)($map['relay_platform_user_id'] ?? $map['platform_user_id']),
            'niuniu_share_price'              => (float)($map['niuniu_share_price'] ?? 100),
            'niuniu_buy_seconds'              => max(30, (int)($map['niuniu_buy_seconds'] ?? 120)),
            'niuniu_claim_seconds'            => max(15, (int)($map['niuniu_claim_seconds'] ?? 60)),
            'niuniu_fee_rate'                 => (float)($map['niuniu_fee_rate'] ?? 0.03),
            'niuniu_pool_rate'                => (float)($map['niuniu_pool_rate'] ?? 0.6),
            'niuniu_secondary_rate'           => (float)($map['niuniu_secondary_rate'] ?? 0.4),
            'niuniu_platform_user_id'         => (int)($map['niuniu_platform_user_id'] ?? $map['platform_user_id']),
            'niuniu_drand_api'                => (string)($map['niuniu_drand_api'] ?? 'https://api.drand.sh'),
            'niuniu_drand_period'             => max(1, (int)($map['niuniu_drand_period'] ?? 30)),
            'niuniu_enabled_global'           => (int)($map['niuniu_enabled_global'] ?? 1),
            'niuniu_rule_text'                => (string)($map['niuniu_rule_text'] ?? ''),
            'max_count'                      => max(1, (int)$map['max_count']),
            'min_amount'                     => (float)$map['min_amount'],
            'min_count'                      => (int)$map['min_count'],
            'vip_min_count'                  => (int)$map['vip_min_count'],
            'vip_max_count'                  => (int)$map['vip_max_count'],
            'skin_width'                     => (int)$map['skin_width'],
            'skin_height'                    => (int)$map['skin_height'],
        ];
        $file = ROOT_PATH . 'im-server' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'red_packet_runtime.php';
        $export = var_export(['red_packet' => $rp, 'niuniu' => [
            'share_price'      => (float)$rp['niuniu_share_price'],
            'buy_seconds'      => (int)$rp['niuniu_buy_seconds'],
            'claim_seconds'    => (int)$rp['niuniu_claim_seconds'],
            'fee_rate'         => (float)$rp['niuniu_fee_rate'],
            'niuniu_rate'      => (float)$rp['niuniu_pool_rate'],
            'secondary_rate'   => (float)$rp['niuniu_secondary_rate'],
            'platform_user_id' => (int)$rp['niuniu_platform_user_id'],
            'drand_api'        => (string)$rp['niuniu_drand_api'],
            'drand_period'     => (int)$rp['niuniu_drand_period'],
            'enabled_global'   => (int)$rp['niuniu_enabled_global'],
            'rule_text'        => (string)$rp['niuniu_rule_text'],
            'robot_user_id'    => (int)($rp['group_robot_user_id'] ?? 74282747),
        ]], true);
        $php = "<?php\n// auto-generated by FansHubRedPacket::syncImRuntimeConfig — do not edit\nreturn {$export};\n";
        @file_put_contents($file, $php);
        return is_file($file);
    }

    public static function statusList()
    {
        return [
            1 => '进行中',
            2 => '已抢完',
            3 => '已过期',
            4 => '已关闭',
            5 => '已结算',
        ];
    }

    public static function typeList()
    {
        return [
            1 => '普通红包',
            2 => '拼手气',
            3 => '埋雷包',
            4 => '随机红包',
            5 => '接龙红包',
        ];
    }

    public static function settleTypeList()
    {
        return [
            'compensate'    => '赔付',
            'platform_fee'  => '平台抽水',
            'agent_rebate'  => '代理返点',
            'refund'        => '过期退回',
        ];
    }

    /**
     * 校验皮肤图尺寸（本地路径或 URL 转本地）
     * @return array{ok:bool,width:int,height:int,message:string}
     */
    public static function validateSkinImage($imagePath)
    {
        $wNeed = (int)self::get('skin_width', 750);
        $hNeed = (int)self::get('skin_height', 1000);
        $path = (string)$imagePath;
        if ($path === '') {
            return ['ok' => false, 'width' => 0, 'height' => 0, 'message' => '请上传皮肤图'];
        }
        $local = $path;
        if (preg_match('#^https?://#i', $path)) {
            $parsed = parse_url($path, PHP_URL_PATH);
            $local = ROOT_PATH . 'public' . str_replace('/', DIRECTORY_SEPARATOR, (string)$parsed);
        } elseif ($path[0] === '/') {
            $local = ROOT_PATH . 'public' . str_replace('/', DIRECTORY_SEPARATOR, $path);
        } else {
            $try = ROOT_PATH . 'public' . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
            if (is_file($try)) {
                $local = $try;
            }
        }
        if (!is_file($local)) {
            // URL 外链无法本地校验时放行但提示
            if (preg_match('#^https?://#i', $path)) {
                return ['ok' => true, 'width' => $wNeed, 'height' => $hNeed, 'message' => '外链图片未做尺寸校验'];
            }
            return ['ok' => false, 'width' => 0, 'height' => 0, 'message' => '皮肤文件不存在'];
        }
        $info = @getimagesize($local);
        if (!$info) {
            return ['ok' => false, 'width' => 0, 'height' => 0, 'message' => '无法读取图片尺寸'];
        }
        $w = (int)$info[0];
        $h = (int)$info[1];
        if ($w !== $wNeed || $h !== $hNeed) {
            return [
                'ok'      => false,
                'width'   => $w,
                'height'  => $h,
                'message' => "尺寸须为 {$wNeed}×{$hNeed}，当前 {$w}×{$h}",
            ];
        }
        return ['ok' => true, 'width' => $w, 'height' => $h, 'message' => 'ok'];
    }

    public static function userLabel($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return '-';
        }
        $u = Db::name('user')->where('id', $userId)->field('id,nickname,mobile')->find();
        if (!$u) {
            return 'ID' . $userId;
        }
        $name = trim((string)($u['nickname'] ?? ''));
        if ($name === '') {
            $name = (string)($u['mobile'] ?? '');
        }
        return ($name !== '' ? $name : '用户') . ' (#' . $userId . ')';
    }
}
