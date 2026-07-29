<?php

namespace app\common\library\finance;

/**
 * 财务接口配置：pid 即代表一个财务后台。
 */
class FinanceConfig
{
    public static function getSiteDefinitions()
    {
        $raw = config('finance_sites');
        return is_array($raw['sites'] ?? null) ? $raw['sites'] : [];
    }

    public static function getCommon()
    {
        $raw = config('finance_sites');
        return is_array($raw['common'] ?? null) ? $raw['common'] : [];
    }

    public static function getDefaultPid()
    {
        $raw = config('finance_sites');
        $pid = (int)($raw['default_pid'] ?? 1);
        return self::isValidPid($pid) ? $pid : self::getFirstEnabledPid();
    }

    public static function getEnabledPids()
    {
        $pids = [];
        foreach (self::getSiteDefinitions() as $pid => $site) {
            if (!empty($site['enabled'])) {
                $pids[] = (int)$pid;
            }
        }
        return $pids;
    }

    public static function getFirstEnabledPid()
    {
        $pids = self::getEnabledPids();
        if (empty($pids)) {
            throw new \Exception('未配置任何启用的财务后台，请编辑 application/extra/finance_sites.php');
        }
        return $pids[0];
    }

    public static function isValidPid($pid)
    {
        $pid = (int)$pid;
        if ($pid <= 0) {
            return false;
        }
        $site = self::getSiteDefinitions()[$pid] ?? null;
        return !empty($site) && !empty($site['enabled']);
    }

    public static function resolvePid($pid = null)
    {
        if ($pid !== null && $pid !== '') {
            $pid = (int)$pid;
            if (!self::isValidPid($pid)) {
                throw new \Exception('无效或未启用的财务后台 pid：' . $pid);
            }
            return $pid;
        }
        return self::getDefaultPid();
    }

    /**
     * 合并公共配置 + pid 对应后台配置，生成完整运行参数
     */
    public static function forPid($pid = null)
    {
        $pid = self::resolvePid($pid);
        $site = self::getSiteDefinitions()[$pid];
        $common = self::getCommon();
        $baseUrl = rtrim((string)($site['base_url'] ?? ''), '/');
        if ($baseUrl === '') {
            throw new \Exception("pid {$pid} 未配置 base_url");
        }

        $sitecode = (string)($site['sitecode'] ?? '');
        $config = array_merge($common, $site, [
            'pid'                => $pid,
            'base_url'           => $baseUrl,
            'login_url'          => $baseUrl . '/auth/oauth/token',
            'merch_list_url'     => $baseUrl . '/api/go-gateway-internal/admin/finance/merchAgentSetting/listV2',
            'withdraw_all_url'   => $baseUrl . '/api/finance/withdrawAll/index',
            'session_id_key'     => 'SESSIONID-3-' . $sitecode,
            'referer_login'      => $baseUrl . '/login',
            'referer_merch_full' => $baseUrl . ($site['referer_merch'] ?? '/financialManagementNew/paymentSettings?activeName=finance_merch_withdraw'),
            'referer_withdraw_full' => $baseUrl . ($site['referer_withdraw'] ?? '/financialManagementNew/paymentSettings?activeName=finance_withdraw_index'),
        ]);

        return $config;
    }

    /** @deprecated 兼容旧代码，返回默认站点配置 */
    public static function get()
    {
        return self::forPid();
    }

    public static function getUrgeConfig($pid = null)
    {
        $config = self::forPid($pid);
        return [
            'pid'            => $config['pid'],
            'withdraw_table' => $config['withdraw_table'],
            'merch_table'    => $config['merch_table'],
            'schedule_table' => $config['schedule_table'],
            'urge_bot_token' => $config['urge_bot_token'],
            'urge_chat_id'   => $config['urge_chat_id'],
        ];
    }

}
