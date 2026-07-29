<?php

namespace app\common\library;

use think\Config;
use think\Log;

/**
 * SugarCRM API 对接（/sugarcrm/plist）
 *
 * ========== KZ 签名规则（官方文档 4. How to generate sign）==========
 *
 * Sample request parameters:
 *   'a'=>1, 'b'=>'222', 'c'=>33
 * Sample encryption key:
 *   abcde12345
 * Sample string for sign:
 *   a=1&b=222&c=33&sKey=abcde12345
 * Sample sign value:
 *   770560eca1a39eadab5c01af7d1cecf1
 * = strtolower( MD5("a=1&b=222&c=33&sKey=abcde12345") )
 *
 * 规则：
 * 1. 业务参数按「接口文档 Request 表字段顺序」依次拼接（All params must follow sequence accordingly）
 * 2. 只拼有值的字段：key=value，用 & 连接（值转字符串，不做 urlencode）
 * 3. 末尾追加 &sKey={KZ 提供的密钥}（sKey 只参与签名，不 POST）
 * 4. MD5 后转小写 → POST 字段 sign（follow by param[sign]）
 * 5. Header：X-ENV + X-KZAPI-LANGUAGE + Content-Type: application/x-www-form-urlencoded
 *
 * plist 字段顺序见 $plistSignKeys
 * ================================================================
 */
class SugarCrm
{
    /** @var self|null */
    protected static $instance;

    /** plist 签名 / 提交参数顺序（文档 Request 表；签名必须包含与 POST 相同的字段） */
    protected static $plistSignKeys = [
        'min_upd',
        'max_upd',
        'min_regdt',
        'max_regdt',
        'playername',
        'pages',
        'pageLength',
    ];

    protected $apiUrl;
    protected $xEnv;
    protected $sKey;
    protected $lang;
    protected $timeout;

    public function __construct(array $cfg = [])
    {
        $fanshub = Config::get('fanshub') ?: [];
        $this->apiUrl = rtrim((string)($cfg['api_url'] ?? $fanshub['sugarcrm_api_url'] ?? 'https://sgcrm.rsei686nnw5n.com'), '/');
        $this->xEnv = trim((string)($cfg['x_env'] ?? $fanshub['sugarcrm_x_env'] ?? '555bioprod'));
        // 密钥去首尾空白，避免复制粘贴带入空格导致 Invalid Signature
        $this->sKey = trim((string)($cfg['skey'] ?? $fanshub['sugarcrm_skey'] ?? '9eT8zVu3z1ubzUPxkpFlDuZpX6A1q42'));
        $this->lang = trim((string)($cfg['lang'] ?? $fanshub['sugarcrm_lang'] ?? 'cn'));
        $this->timeout = max(3, min(30, (int)($cfg['timeout'] ?? $fanshub['sugarcrm_timeout'] ?? 8)));
    }

    public static function instance(array $cfg = [])
    {
        if (!empty($cfg)) {
            return new self($cfg);
        }
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function enabled()
    {
        $fanshub = Config::get('fanshub') ?: [];
        return !empty($fanshub['sugarcrm_enabled']);
    }

    /**
     * 对照 KZ 官方样例自检（必须为 true）
     */
    public static function assertOfficialSample()
    {
        // 样例按给出的参数顺序 a→b→c（非字典序）
        $data = self::buildSignDataStatic(['a' => 1, 'b' => '222', 'c' => 33], 'abcde12345', null);
        return $data['sign'] === '770560eca1a39eadab5c01af7d1cecf1'
            && $data['sign_string'] === 'a=1&b=222&c=33&sKey=abcde12345';
    }

    public function findByPlayername($playername, array $logContext = [])
    {
        $playername = trim((string)$playername);
        if ($playername === '') {
            return null;
        }
        $res = $this->getMemberList([
            'playername' => $playername,
            'pages'      => 1,
            'pageLength' => 10,
        ], array_merge($logContext, ['playername' => $playername]));
        if ($res === false) {
            return false;
        }
        if (!is_array($res) || (int)($res['c'] ?? -1) !== 0) {
            if (is_array($res) && stripos((string)($res['m'] ?? ''), 'signature') !== false) {
                return false;
            }
            return null;
        }
        $list = $res['d'] ?? [];
        if (!is_array($list) || !$list) {
            return null;
        }
        $needle = strtolower($playername);
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $uname = strtolower(trim((string)($row['username'] ?? '')));
            if ($uname !== '' && $uname === $needle) {
                return $row;
            }
        }
        $first = $list[0];
        return is_array($first) ? $first : null;
    }

    public static function isMobileVerified(array $member)
    {
        $status = strtolower(trim((string)($member['mobilestatus'] ?? $member['mobileStatus'] ?? '')));
        if ($status === 'verified') {
            return true;
        }
        $mobile = strtolower(trim((string)($member['mobile'] ?? '')));
        return $mobile === 'verified';
    }

    public function getMemberList(array $params = [], array $logContext = [])
    {
        $endpoint = '/sugarcrm/plist';
        $url = $this->apiUrl . $endpoint;

        // 按文档字段顺序整理业务参数（空值跳过）
        $ordered = [];
        foreach (self::$plistSignKeys as $key) {
            if (!array_key_exists($key, $params)) {
                continue;
            }
            $val = $params[$key];
            if ($val === null || $val === '') {
                continue;
            }
            $ordered[$key] = $val;
        }

        $signData = self::buildSignDataStatic($ordered, $this->sKey, null);
        $post = $ordered;
        $post['sign'] = $signData['sign'];

        $logContext = array_merge([
            'action'           => 'plist',
            'playername'       => (string)($ordered['playername'] ?? ''),
            'sign_rule'        => 'kz_doc_sequence_md5_skey',
            'sign_base'        => $signData['sign_base'],
            'sign_string_mask' => $signData['sign_string_mask'],
            'sign_ok_sample'   => self::assertOfficialSample() ? '1' : '0',
            'x_env'            => $this->xEnv,
        ], $logContext);

        $response = $this->httpPost($url, $post, $logContext);
        if ($response === false || $response === '') {
            return false;
        }
        $json = json_decode($response, true);
        return is_array($json) ? $json : false;
    }

    /**
     * @param array       $params   已按文档顺序排好的业务参数（不含 sign/sKey）
     * @param string      $sKey
     * @param array|null  $keyOrder 若给定则严格按该顺序取键；null 则按 $params 当前顺序
     * @return array{sign:string,sign_base:string,sign_string:string,sign_string_mask:string}
     */
    public static function buildSignDataStatic(array $params, $sKey, $keyOrder = null)
    {
        unset($params['sign'], $params['sKey'], $params['skey']);
        $parts = [];
        if (is_array($keyOrder) && $keyOrder) {
            foreach ($keyOrder as $key) {
                if (!array_key_exists($key, $params)) {
                    continue;
                }
                $value = $params[$key];
                if ($value === null || $value === '') {
                    continue;
                }
                $parts[] = $key . '=' . (string)$value;
            }
        } else {
            foreach ($params as $key => $value) {
                if ($value === null) {
                    continue;
                }
                $parts[] = $key . '=' . (is_bool($value) ? ($value ? '1' : '0') : (string)$value);
            }
        }
        $signBase = implode('&', $parts);
        $signString = $signBase . '&sKey=' . (string)$sKey;
        return [
            'sign'             => strtolower(md5($signString)),
            'sign_base'        => $signBase,
            'sign_string'      => $signString,
            'sign_string_mask' => $signBase . '&sKey=***',
        ];
    }

    protected function httpPost($url, array $data, array $logContext = [])
    {
        $started = microtime(true);
        $ch = curl_init();
        $headers = [
            'X-ENV: ' . $this->xEnv,
            'X-KZAPI-LANGUAGE: ' . $this->lang,
            'Content-Type: application/x-www-form-urlencoded',
        ];
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&', PHP_QUERY_RFC3986));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        SugarCrmLog::record('POST', $url, $headers, $data, $response, $error, $httpCode, $started, $logContext);

        if ($response === false || $error !== '') {
            Log::error('SugarCRM API Request Failed: ' . $error);
            return false;
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            Log::error('SugarCRM API HTTP ' . $httpCode . ': ' . substr((string)$response, 0, 500));
            return false;
        }
        return $response;
    }
}
