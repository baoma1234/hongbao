<?php

namespace app\common\library\finance;

use think\Log;

/**
 * 财务接口登录与 HTTP 请求
 */
class FinanceAuth
{
    protected $config;

    public function __construct(array $config = null)
    {
        $this->config = $config ?: FinanceConfig::forPid();
    }

    public function login()
    {
        $loginInfo = $this->doLoginAndCache();
        if (empty($loginInfo)) {
            throw new \Exception('登录失败，未获取到access_token');
        }
        return $loginInfo;
    }

    public function sendAuthRequest($url, $postData, $type, array $extraHeaders = [])
    {
        $loginInfo = $this->getLoginInfoOrFail(true);
        $result = $this->sendCurlRequest($url, $postData, $type, $loginInfo, $extraHeaders);

        if ($this->isLoginExpiredResponse($result)) {
            Log::write('[pid=' . ($this->config['pid'] ?? '') . '] 登录态失效，尝试自动重新登录', 'warning');
            cache($this->getCacheKey(), null);
            $loginInfo = $this->doLoginAndCache();
            if (empty($loginInfo)) {
                throw new \Exception('登录态失效且自动重新登录失败');
            }
            $result = $this->sendCurlRequest($url, $postData, $type, $loginInfo, $extraHeaders);
            if ($this->isLoginExpiredResponse($result)) {
                throw new \Exception($result['msg'] ?? '登录态失效，请重新登录');
            }
        }

        return $result;
    }

    protected function getLoginInfoOrFail($autoLogin = false)
    {
        $loginInfo = cache($this->getCacheKey());
        if (empty($loginInfo)) {
            if ($autoLogin) {
                $loginInfo = $this->doLoginAndCache();
                if (!empty($loginInfo)) {
                    return $loginInfo;
                }
            }
            throw new \Exception('登录信息已过期，请先访问 /index/login/login?pid=' . ($this->config['pid'] ?? ''));
        }
        return $loginInfo;
    }

    protected function doLoginAndCache()
    {
        $sessionIdKey = $this->config['session_id_key'] ?? 'SESSIONID-3-' . ($this->config['sitecode'] ?? '');

        $preLoginResult = $this->sendCurlRequest(
            $this->config['login_url'],
            json_encode($this->buildLoginPayload(false), JSON_UNESCAPED_UNICODE),
            'login'
        );

        $preSession = [
            'session_id'     => $preLoginResult['real_session_id'] ?? '',
            'session_id_key' => $preLoginResult['session_id_key'] ?? $sessionIdKey,
        ];

        $loginResult = $this->sendCurlRequest(
            $this->config['login_url'],
            json_encode($this->buildLoginPayload(true), JSON_UNESCAPED_UNICODE),
            'login',
            $preSession
        );

        if (empty($loginResult['access_token'])) {
            return null;
        }

        $realSessionId = $loginResult['real_session_id'] ?? $preSession['session_id'];
        $sessionIdKey = $loginResult['session_id_key'] ?? $preSession['session_id_key'];
        if (empty($realSessionId)) {
            return null;
        }

        $loginInfo = [
            'access_token'      => $loginResult['access_token'],
            'refresh_token'     => $loginResult['refresh_token'] ?? '',
            'unit_id'           => $loginResult['unitId'] ?? '',
            'user_id'           => $loginResult['user_id'] ?? '',
            'username'          => $loginResult['username'] ?? '',
            'current_back_type' => $loginResult['currentBackType'] ?? '3',
            'session_id'        => $realSessionId,
            'session_id_key'    => $sessionIdKey,
            'login_time'        => time(),
            'pid'               => $this->config['pid'] ?? '',
        ];

        cache($this->getCacheKey(), $loginInfo, $this->config['cache_expire']);
        Log::write('[pid=' . ($this->config['pid'] ?? '') . '] 登录成功', 'info');

        return $loginInfo;
    }

    protected function buildLoginPayload($withGaCode = false)
    {
        $payload = [
            'username'   => $this->config['username'],
            'password'   => $this->config['password'],
            'randomStr'  => $this->generateRandomStr(),
            'code'       => $this->config['code'],
            'grant_type' => $this->config['grant_type'],
            'scope'      => $this->config['scope'],
        ];

        if ($withGaCode) {
            $payload['gaCode'] = $this->getGaCode();
        }

        return $payload;
    }

    protected function getGaCode()
    {
        require_once APP_PATH . 'common/library/GoogleAuthenticator.php';
        $ga = new \PHPGangsta_GoogleAuthenticator();
        return $ga->getCode($this->config['ga_secret']);
    }

    protected function isLoginExpiredResponse($result)
    {
        if (!is_array($result)) {
            return false;
        }
        $code = $result['code'] ?? null;
        return in_array($code, [1116000030, '1116000030'], true)
            || (isset($result['msg']) && strpos($result['msg'], '登录失效') !== false);
    }

    protected function sendCurlRequest($url, $postData, $type = 'login', $loginInfo = [], $extraHeaders = [])
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_TCP_NODELAY, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HEADER, true);

        $headers = $this->buildHeaders($type, $loginInfo);
        if (!empty($extraHeaders)) {
            $headers = array_merge($headers, $extraHeaders);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_COOKIE, $this->buildCookie($type, $loginInfo));

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlError) {
            throw new \Exception('CURL请求失败：' . $curlError . '，HTTP状态码：' . $httpCode);
        }

        $responseHeader = '';
        $responseBody = '';
        $separator = "\r\n\r\n";
        $pos = strpos($response, $separator);
        if ($pos !== false) {
            $responseHeader = substr($response, 0, $pos);
            $responseBody = substr($response, $pos + strlen($separator));
        } else {
            $separator = "\n\n";
            $pos = strpos($response, $separator);
            if ($pos !== false) {
                $responseHeader = substr($response, 0, $pos);
                $responseBody = substr($response, $pos + strlen($separator));
            } else {
                $responseBody = $response;
            }
        }

        $result = json_decode($responseBody, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('返回数据非JSON格式：' . $responseBody);
        }

        if ($type === 'login' && !empty($responseHeader)) {
            $sessionId = '';
            $sessionIdKey = '';
            preg_match('/Set-Cookie:\s*([^=]+)=([^;]+);/i', $responseHeader, $matches);
            if (count($matches) >= 3 && strpos($matches[1], 'SESSIONID-3-') === 0) {
                $sessionIdKey = $matches[1];
                $sessionId = $matches[2];
            }
            if (empty($sessionId)) {
                $headerLines = explode("\n", $responseHeader);
                foreach ($headerLines as $line) {
                    $line = trim($line);
                    if (strpos($line, 'Set-Cookie:') === 0 && strpos($line, 'SESSIONID-3-') !== false) {
                        preg_match('/([^=]+)=([^;]+);?/i', substr($line, strlen('Set-Cookie:')), $cookieMatches);
                        if (count($cookieMatches) >= 3) {
                            $sessionIdKey = trim($cookieMatches[1]);
                            $sessionId = trim($cookieMatches[2]);
                            break;
                        }
                    }
                }
            }
            $result['real_session_id'] = $sessionId;
            $result['session_id_key'] = $sessionIdKey;
        }

        return $result;
    }

    protected function buildHeaders($type = 'login', $loginInfo = [])
    {
        $baseUrl = rtrim($this->config['base_url'], '/');
        $sitecode = (string)($this->config['sitecode'] ?? '');
        $companycode = (string)($this->config['companycode'] ?? $sitecode);
        $childsitecode = (string)($this->config['childsitecode'] ?? $sitecode);

        $headers = [
            'accept: application/json, text/plain, */*',
            'accept-language: zh-CN,zh;q=0.9,zh-HK;q=0.8',
            'appsystem: Windows10',
            'browsertype: Chrome',
            'content-type: application/json',
            'device: ' . $this->config['device_id'],
            'devicebrand: unknown',
            'devicemodel: 148.0.0.0',
            'language: zh',
            'operatingsystem: Windows',
            'origin: ' . $baseUrl,
            'physicaldevicemodel: unknown',
            'priority: u=1, i',
            'sec-ch-ua: "Chromium";v="148", "Google Chrome";v="148", "Not/A)Brand";v="99"',
            'sec-ch-ua-mobile: ?0',
            'sec-ch-ua-platform: "Windows"',
            'sec-fetch-dest: empty',
            'sec-fetch-mode: cors',
            'sec-fetch-site: same-origin',
            'time-zone: UTC +8:00',
            'user-agent: ' . $this->config['user_agent'],
            'x-request-id: ' . $this->generateUuid(),
        ];

        if ($type === 'login') {
            $headers[] = 'companycode: -1';
            $headers[] = 'loginbacktype: -1';
            $headers[] = 'permtag: /login';
            $headers[] = 'referer: ' . ($this->config['referer_login'] ?? $baseUrl . '/login');
            $headers[] = 'sitecode: 0';
        } elseif ($type === 'merch_list') {
            $headers[] = 'childsitecode: ' . $childsitecode;
            $headers[] = 'companycode: ' . $companycode;
            $headers[] = 'loginbacktype: 3';
            $headers[] = 'permtag: finance_merch_withdraw';
            $headers[] = 'sitecode: ' . $sitecode;
            $headers[] = 'referer: ' . $this->config['referer_merch_full'];
        } elseif ($type === 'withdraw_all') {
            $headers[] = 'childsitecode: ' . $childsitecode;
            $headers[] = 'companycode: ' . $companycode;
            $headers[] = 'loginbacktype: 3';
            $headers[] = 'permtag: finance_withdraw_index';
            $headers[] = 'sitecode: ' . $sitecode;
            $headers[] = 'referer: ' . $this->config['referer_withdraw_full'];
        }

        return $headers;
    }

    protected function buildCookie($type = 'login', $loginInfo = [])
    {
        $cookie = $this->config['base_cookie'] ?? '';
        $authTypes = ['login', 'merch_list', 'withdraw_all'];

        if (in_array($type, $authTypes, true) && !empty($loginInfo['session_id'])) {
            $sessionIdKey = $loginInfo['session_id_key'] ?? ($this->config['session_id_key'] ?? 'SESSIONID-3-1187');
            if ($cookie !== '') {
                $cookie = preg_replace('/' . preg_quote($sessionIdKey, '/') . '=[^;]*;?/', '', $cookie);
            }
            $cookie = rtrim($cookie, ';') . ';' . $sessionIdKey . '=' . $loginInfo['session_id'];
        }

        return rtrim($cookie, ';');
    }

    protected function getCacheKey()
    {
        return $this->config['cache_prefix']
            . 'pid' . ($this->config['pid'] ?? 'default') . '_'
            . ($this->config['username'] ?? 'user');
    }

    protected function generateUuid()
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    protected function generateRandomStr()
    {
        return mt_rand(1000, 9999) . (string) round(microtime(true) * 1000);
    }
}
