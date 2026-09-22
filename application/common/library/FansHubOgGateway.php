<?php

namespace app\common\library;

/**
 * OG视讯网关（商户配置读取；具体登录/转账 API 后续对接）
 */
class FansHubOgGateway
{
    /**
     * @return array<string,mixed>
     */
    public static function config()
    {
        $cfg = FansHubService::config();
        if (!is_array($cfg)) {
            $cfg = [];
        }
        return [
            'enabled'          => !empty($cfg['og_enabled']),
            'sandbox'          => !empty($cfg['og_sandbox']),
            'merchant_code'    => trim((string)($cfg['og_merchant_code'] ?? '')),
            'agent_id'         => trim((string)($cfg['og_agent_id'] ?? '')),
            'api_key'          => trim((string)($cfg['og_api_key'] ?? '')),
            'api_secret'       => trim((string)($cfg['og_api_secret'] ?? '')),
            'api_base_url'     => rtrim(trim((string)($cfg['og_api_base_url'] ?? '')), '/'),
            'sandbox_base_url' => rtrim(trim((string)($cfg['og_sandbox_base_url'] ?? '')), '/'),
            'currency'         => strtoupper(trim((string)($cfg['og_currency'] ?? 'CNY'))) ?: 'CNY',
            'language'         => trim((string)($cfg['og_language'] ?? 'zh')) ?: 'zh',
            'callback_url'     => trim((string)($cfg['og_callback_url'] ?? '')),
            'return_url'       => trim((string)($cfg['og_return_url'] ?? '')),
            'timeout'          => max(3, min(120, (int)($cfg['og_timeout'] ?? 15))),
            'remark'           => trim((string)($cfg['og_remark'] ?? '')),
        ];
    }

    public static function isEnabled()
    {
        $c = self::config();
        return !empty($c['enabled']);
    }

    /** 当前环境实际请求根地址 */
    public static function baseUrl()
    {
        $c = self::config();
        if (!empty($c['sandbox']) && $c['sandbox_base_url'] !== '') {
            return $c['sandbox_base_url'];
        }
        return $c['api_base_url'];
    }

    /**
     * 凭证是否齐全（不含 enabled）
     */
    public static function credentialsReady()
    {
        $c = self::config();
        if ($c['merchant_code'] === '' || $c['api_key'] === '') {
            return false;
        }
        if (self::baseUrl() === '') {
            return false;
        }
        return true;
    }
}
