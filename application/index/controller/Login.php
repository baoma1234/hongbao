<?php

namespace app\index\controller;

use app\common\library\finance\FinanceAuth;
use app\common\library\finance\FinanceConfig;
use app\common\library\finance\MerchSync;
use app\common\library\finance\WithdrawSync;
use think\Controller;
use think\Log;

/**
 * 财务接口入口（登录 / 商户同步 / 订单同步）
 * pid 即代表一个财务后台；pid=all 表示全部启用后台
 */
class Login extends Controller
{
    public function login()
    {
        $pidParam = $this->request->param('pid', '');
        if ($pidParam === 'all') {
            return $this->loginAll();
        }

        try {
            $pid = FinanceConfig::resolvePid($pidParam ?: null);
            $loginInfo = (new FinanceAuth(FinanceConfig::forPid($pid)))->login();
            return json(['code' => 0, 'msg' => '登录成功', 'data' => ['pid' => $pid, 'login' => $loginInfo]]);
        } catch (\Exception $e) {
            Log::write('登录失败：' . $e->getMessage(), 'error');
            return json(['code' => -1, 'msg' => $e->getMessage(), 'data' => []]);
        }
    }

    public function loginAll()
    {
        $results = [];
        foreach (FinanceConfig::getEnabledPids() as $pid) {
            try {
                $loginInfo = (new FinanceAuth(FinanceConfig::forPid($pid)))->login();
                $results[$pid] = ['code' => 0, 'msg' => 'ok', 'username' => $loginInfo['username'] ?? ''];
            } catch (\Exception $e) {
                $results[$pid] = ['code' => -1, 'msg' => $e->getMessage()];
            }
        }
        return json(['code' => 0, 'msg' => '批量登录完成', 'data' => $results]);
    }

    public function merchList()
    {
        try {
            $pid = FinanceConfig::resolvePid($this->request->param('pid'));
            $data = (new MerchSync($pid))->sync($pid);
            return json(['code' => 0, 'msg' => 'success', 'data' => $data]);
        } catch (\Exception $e) {
            Log::write('商户同步失败：' . $e->getMessage(), 'error');
            return json(['code' => -1, 'msg' => $e->getMessage(), 'data' => []]);
        }
    }

    public function unpaidOrder()
    {
        try {
            $pidParam = $this->request->param('pid', '');
            if ($pidParam === 'all') {
                $all = [];
                foreach (FinanceConfig::getEnabledPids() as $pid) {
                    $all[$pid] = (new WithdrawSync($pid))->sync();
                }
                return json(['code' => 0, 'msg' => 'success', 'data' => $all]);
            }

            $pid = FinanceConfig::resolvePid($pidParam ?: null);
            $data = (new WithdrawSync($pid))->sync();
            return json(['code' => 0, 'msg' => 'success', 'data' => $data]);
        } catch (\Exception $e) {
            Log::write('未支付订单同步失败：' . $e->getMessage(), 'error');
            return json(['code' => -1, 'msg' => $e->getMessage(), 'data' => []]);
        }
    }
}
