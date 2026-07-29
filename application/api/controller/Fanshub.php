<?php

namespace app\api\controller;

use app\common\controller\Api;
use app\common\library\FansHubMobile;
use app\common\library\FansHubService;
use app\common\library\FansHubSliderCaptcha;
use app\common\library\FansHubSms;
use app\common\library\Sms as Smslib;
use app\common\model\User;
use think\exception\HttpResponseException;
use think\Validate;

/**
 * 555.bio 福利大厅 H5 API
 */
class Fanshub extends Api
{
    protected $noNeedLogin = ['config', 'sendsms', 'slidercaptcha', 'grabslider', 'login', 'comments', 'inviteleaderboard', 'jackpot', 'notices', 'rpfair'];
    protected $noNeedRight = '*';

    public function _initialize()
    {
        FansHubSms::boot();
        parent::_initialize();
        $action = strtolower($this->request->action());
        $exempt = ['config', 'comments', 'inviteleaderboard', 'slidercaptcha', 'grabslider', 'jackpot', 'notices', 'rpfair'];
        if (in_array($action, $exempt, true)) {
            return;
        }
        try {
            FansHubService::verifyApiSign($this->request);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->error($e->getMessage() ?: FansHubService::h5CopyText('api_sign_fail'));
        }
    }

    /**
     * 公开配置（门槛、链接等）
     */
    public function config()
    {
        $this->success('ok', FansHubService::publicConfig());
    }

    /**
     * 红宝公告动态（朋友圈风格）
     */
    public function notices()
    {
        $page = (int)$this->request->get('page', $this->request->post('page', 1));
        $limit = (int)$this->request->get('limit', $this->request->post('limit', 20));
        $category = (string)$this->request->get('category', $this->request->post('category', ''));
        $this->success('ok', FansHubService::noticeFeed($page, $limit, $category));
    }

    /**
     * 推广佣金汇总
     */
    public function commission()
    {
        try {
            $this->success('ok', FansHubService::commissionSummary($this->auth->id));
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->error($e->getMessage() ?: FansHubService::h5CopyText('api_operation_fail'));
        }
    }

    /**
     * 实时奖池（服务端同步）
     * tick=true：服务端按全局时钟随机加钱；前端每 2 秒拉同一份金额
     */
    public function jackpot()
    {
        $data = FansHubService::jackpotPayload(true, true);
        $this->success('ok', $data);
    }

    /**
     * 红包公平性验证（波场官方区块哈希）
     * GET /api/fanshub/rpfair?packet_no=RP...
     */
    public function rpfair()
    {
        $packetNo = trim((string)$this->request->param('packet_no', ''));
        if ($packetNo === '') {
            $this->error('请提供红包单号 packet_no');
        }
        $cached = \app\common\library\RedPacketTronFair::cacheGet($packetNo);
        if (is_array($cached) && !empty($cached['revealed']) && !empty($cached['block_id'])) {
            $this->success('ok', $cached);
        }
        $packet = \think\Db::name('chat_red_packets')->where('packet_no', $packetNo)->find();
        if (!$packet && $packetNo !== strtolower($packetNo)) {
            $packet = \think\Db::name('chat_red_packets')->where('packet_no', strtolower($packetNo))->find();
        }
        if (!$packet) {
            $this->error('红包不存在');
        }
        if (!in_array((int)$packet['packet_type'], [2, 3], true)) {
            $this->error('该红包玩法不支持公平性验证');
        }

        $tronStatus = (int)($packet['tron_status'] ?? 0);
        $finished = in_array((int)$packet['status'], [2, 3, 5], true) || (int)($packet['remain_count'] ?? 1) <= 0;
        // 已抢完/过期但未绑定波场哈希：查询时补开奖（避免验证页一直空）
        if ($finished && $tronStatus !== 2) {
            try {
                $r = \app\common\library\RedPacketTronFair::processReveal((int)$packet['id'], true);
                if (!empty($r['ok']) && !empty($r['data']) && !empty($r['data']['revealed'])) {
                    $this->success('ok', $r['data']);
                }
                $packet = \think\Db::name('chat_red_packets')->where('id', (int)$packet['id'])->find() ?: $packet;
                $tronStatus = (int)($packet['tron_status'] ?? 0);
            } catch (\Throwable $e) {
                // 继续返回当前状态，前端可提示待开奖
            }
        }

        $hasTron = trim((string)($packet['tron_block_id'] ?? '')) !== '' || (int)($packet['tron_block_num'] ?? 0) > 0;
        $legacyHash = trim((string)($packet['fair_hash'] ?? ''));
        if (!$hasTron && $legacyHash === '' && $tronStatus === 0 && !$finished) {
            $this->error('该红包暂无波场哈希（未开奖）');
        }
        $records = [];
        if ($tronStatus === 2 || (int)($packet['fair_revealed_at'] ?? 0) > 0) {
            $records = \think\Db::name('chat_red_packet_records')
                ->where('packet_id', (int)$packet['id'])
                ->order('id', 'asc')
                ->field('user_id,amount,amount_cent,tail_digit,is_best,is_worst,is_mine_hit,createtime')
                ->select();
        }
        $view = \app\common\library\RedPacketTronFair::publicView($packet, $records ?: []);
        if (!empty($view['revealed'])) {
            \app\common\library\RedPacketTronFair::cachePut($view);
        }
        $this->success('ok', $view);
    }

    /**
     * 邀请排行榜
     */
    public function inviteleaderboard()
    {
        $limit = (int)$this->request->get('limit', 20);
        $this->success('ok', FansHubService::inviteLeaderboard($limit));
    }

    /**
     * 滑块验证码挑战
     */
    public function slidercaptcha()
    {
        if (!FansHubSliderCaptcha::enabled()) {
            $this->success('ok', ['enabled' => false]);
        }
        try {
            $this->success('ok', FansHubSliderCaptcha::create());
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->error($e->getMessage() ?: FansHubService::h5CopyText('srv_slider_create_fail'));
        }
    }

    /**
     * 抢包风控滑块挑战（写入 IM Redis，供 WebSocket 抢包校验）
     * GET /api/fanshub/grabslider
     */
    public function grabslider()
    {
        try {
            $this->success('ok', \app\common\library\FansHubGrabSlider::create());
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->error($e->getMessage() ?: FansHubService::h5CopyText('srv_slider_create_fail'));
        }
    }

    /**
     * 发送短信验证码
     */
    public function sendsms()
    {
        $mobile = $this->normalizeMobileInput();
        if ($mobile === '') {
            $this->error(FansHubService::h5CopyText('api_mobile_invalid'));
        }
        $retryAfter = FansHubService::getSmsRetryAfter($mobile);
        if ($retryAfter > 0) {
            $this->error(
                FansHubService::h5CopyText('api_sms_too_frequent_wait', ['seconds' => $retryAfter]),
                ['retry_after' => $retryAfter]
            );
        }
        try {
            FansHubService::assertSmsIpAllowed();
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
        if (FansHubSliderCaptcha::enabled()) {
            $sliderToken = $this->request->post('slider_token', '');
            $sliderX = $this->request->post('slider_x', '');
            $sliderDuration = $this->request->post('slider_duration', 0);
            $sliderMax = $this->request->post('slider_max', 0);
            if (!FansHubSliderCaptcha::verify($sliderToken, $sliderX, $sliderDuration, $sliderMax)) {
                $this->error(FansHubService::h5CopyText('srv_slider_verify_fail'));
            }
        }
        $interval = FansHubService::smsSendInterval();
        if (FansHubService::config('sms_mock_enabled')) {
            FansHubService::markSmsSent($mobile);
            $mockCode = (string)FansHubService::config('sms_mock_code', '123456');
            $this->success(FansHubService::h5CopyText('api_sms_mock_title'), [
                'mock'        => true,
                'mock_code'   => $mockCode,
                'hint'        => FansHubService::h5CopyText('api_sms_mock_hint', ['code' => $mockCode]),
                'retry_after' => $interval,
            ]);
        }
        $ret = FansHubSms::sendLoginCode($mobile);
        if ($ret) {
            FansHubService::markSmsSent($mobile);
            $this->success(FansHubService::h5CopyText('api_sms_sent_ok'), ['retry_after' => $interval]);
        }
        $this->error(FansHubService::h5CopyText('api_sms_send_fail'));
    }

    /**
     * 手机号验证码登录
     */
    public function login()
    {
        $mobile = $this->normalizeMobileInput();
        $captcha = $this->request->post('captcha');
        $inviteCode = trim((string)$this->request->post('code', $this->request->get('code', '')));
        if ($inviteCode === '') {
            $inviteCode = trim((string)$this->request->post('invite', $this->request->get('invite', '')));
        }
        $deviceFp = $this->request->post('device_fp', '');
        if ($mobile === '' || !$captcha) {
            $this->error(FansHubService::h5CopyText('api_params_incomplete'));
        }
        try {
            $data = FansHubService::loginOrRegister($mobile, $captcha, $inviteCode, $deviceFp);
            $this->success('ok', $data);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->error($e->getMessage() ?: FansHubService::h5CopyText('api_operation_fail'));
        }
    }

    /**
     * 当前用户资料与资产
     */
    public function profile()
    {
        try {
            FansHubService::expireSecrets();
            $data = FansHubService::profilePayload($this->auth->id);
            $this->success('ok', $data);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->error($e->getMessage() ?: FansHubService::h5CopyText('api_operation_fail'));
        }
    }

    /**
     * 红宝（股份）→ 红利余额
     */
    public function exchange()
    {
        $count = (int)$this->request->post('count', 0);
        $channel = $this->request->post('channel', '');
        $requestKey = $this->request->post('request_id', '');
        try {
            $data = FansHubService::exchange($this->auth->id, $count, $channel, $requestKey);
            $this->success(FansHubService::h5CopyText('api_exchange_ok'), $data);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->error($e->getMessage() ?: FansHubService::h5CopyText('api_operation_fail'));
        }
    }

    /**
     * 红利余额 → 红宝（股份）
     */
    public function exchangebalance()
    {
        $amount = $this->request->post('amount', 0);
        $requestKey = $this->request->post('request_id', '');
        try {
            $data = FansHubService::exchangeBalanceToRights($this->auth->id, $amount, $requestKey);
            $this->success(FansHubService::h5CopyText('api_exchange_reverse_ok'), $data);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->error($e->getMessage() ?: FansHubService::h5CopyText('api_operation_fail'));
        }
    }

    /**
     * 三资产互兑：from/to = rights|balance|hongbao，amount 为转出数量
     */
    public function exchangeswap()
    {
        $from = $this->request->post('from', '');
        $to = $this->request->post('to', '');
        $amount = $this->request->post('amount', 0);
        $channel = $this->request->post('channel', 'swap');
        $requestKey = $this->request->post('request_id', '');
        try {
            $data = FansHubService::swapAssets($this->auth->id, $from, $to, $amount, $channel, $requestKey);
            $this->success(FansHubService::h5CopyText('api_exchange_swap_ok') ?: '兑换成功', $data);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->error($e->getMessage() ?: FansHubService::h5CopyText('api_operation_fail'));
        }
    }

    /**
     * 提交主站 UID（进入后台审核，核销通过后才正式绑定）
     */
    public function binduid()
    {
        $uid = $this->request->post('main_uid', '');
        try {
            $data = FansHubService::bindUid($this->auth->id, $uid);
            $this->success(FansHubService::h5CopyText('api_bind_ok'), $data);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->error($e->getMessage() ?: FansHubService::h5CopyText('api_operation_fail'));
        }
    }

    /**
     * 主站开户奖励
     */
    public function openaccount()
    {
        try {
            $data = FansHubService::openAccountReward($this->auth->id);
            $this->success(FansHubService::h5CopyText('api_open_account_ok'), $data);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->error($e->getMessage() ?: FansHubService::h5CopyText('api_operation_fail'));
        }
    }

    /**
     * 分享奖励
     */
    public function share()
    {
        try {
            if ($this->request->post('copy_only')) {
                $this->success('ok', FansHubService::buildSharePayload($this->auth->id));
                return;
            }
            $data = FansHubService::shareReward($this->auth->id);
            $this->success($data['message'] ?: FansHubService::h5CopyText('alert_share_reward_ok'), $data);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->error($e->getMessage() ?: FansHubService::h5CopyText('api_operation_fail'));
        }
    }

    /**
     * 生成密令
     */
    public function createsecret()
    {
        $requestKey = $this->request->post('request_id', '');
        try {
            $data = FansHubService::createSecret($this->auth->id, $requestKey);
            $cfg = FansHubService::publicConfig();
            $data['customer_service_url'] = $cfg['customer_service_url'];
            $data['app_download_url'] = $cfg['app_download_url'];
            $this->success(FansHubService::h5CopyText('api_secret_ok'), $data);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->error($e->getMessage() ?: FansHubService::h5CopyText('api_operation_fail'));
        }
    }

    /**
     * 留言列表（已下线）
     */
    public function comments()
    {
        $this->error('留言功能已关闭');
    }

    /**
     * 发表留言（已下线）
     */
    public function postcomment()
    {
        $this->error('留言功能已关闭');
    }

    /**
     * 二期签到
     */
    public function checkin()
    {
        $violent = $this->request->post('violent', 1) ? true : false;
        $confirmed = $this->request->post('confirmed', 0) ? true : false;
        try {
            $data = \app\common\library\FansHubPhase2::checkIn($this->auth->id, $violent, $confirmed);
            $this->success('ok', $data);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->error($e->getMessage() ?: FansHubService::h5CopyText('api_operation_fail'));
        }
    }

    /**
     * 战队催活雷达
     */
    public function teamradar()
    {
        try {
            $this->success('ok', \app\common\library\FansHubPhase2::teamRadar($this->auth->id));
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->error($e->getMessage() ?: FansHubService::h5CopyText('api_operation_fail'));
        }
    }

    /**
     * 催促下线文案
     */
    public function urgecopy()
    {
        $inviteeId = (int)$this->request->post('invitee_user_id', 0);
        if ($inviteeId <= 0) {
            $this->error(FansHubService::h5CopyText('api_params_incomplete'));
        }
        $text = \app\common\library\FansHubPhase2::urgeCopyText($this->auth->id, $inviteeId);
        $this->success('ok', ['text' => $text]);
    }

    /**
     * 自定义表情包列表
     */
    public function stickerlist()
    {
        $this->success('ok', \app\common\library\FansHubSticker::listPayload($this->auth->id));
    }

    /**
     * 上传自定义表情包（普通用户最多50个，托管管理员不限）
     */
    public function stickerupload()
    {
        $file = $this->request->file('file');
        try {
            $data = \app\common\library\FansHubSticker::upload($this->auth->id, $file);
            $this->success('上传成功', $data);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->error($e->getMessage() ?: FansHubService::h5CopyText('api_operation_fail'));
        }
    }

    /**
     * 删除自定义表情包
     */
    public function stickerdel()
    {
        $id = (int)$this->request->post('id', 0);
        try {
            \app\common\library\FansHubSticker::delete($this->auth->id, $id);
            $this->success('已删除', \app\common\library\FansHubSticker::listPayload($this->auth->id));
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->error($e->getMessage() ?: FansHubService::h5CopyText('api_operation_fail'));
        }
    }

    /**
     * 更新昵称（及可选头像 URL）
     */
    public function updateprofile()
    {
        $nickname = $this->request->post('nickname', null);
        $avatar = $this->request->post('avatar', null);
        $data = [];
        if ($nickname !== null) {
            $data['nickname'] = $nickname;
        }
        if ($avatar !== null) {
            $data['avatar'] = $avatar;
        }
        try {
            $profile = FansHubService::updateProfile($this->auth->id, $data);
            $this->success(FansHubService::h5CopyText('api_profile_ok'), $profile);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->error($e->getMessage() ?: FansHubService::h5CopyText('api_operation_fail'));
        }
    }

    /**
     * 上传头像
     */
    public function avatarupload()
    {
        $file = $this->request->file('file');
        try {
            $data = FansHubService::uploadAvatar($this->auth->id, $file);
            $this->success(FansHubService::h5CopyText('api_avatar_ok'), $data);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->error($e->getMessage() ?: FansHubService::h5CopyText('api_operation_fail'));
        }
    }

    /**
     * 修改密码（旧密码或短信验证码）
     */
    public function changepassword()
    {
        $mode = $this->request->post('mode', 'old');
        $newPassword = $this->request->post('new_password', '');
        $confirm = $this->request->post('confirm_password', '');
        $oldPassword = $this->request->post('old_password', '');
        $captcha = $this->request->post('captcha', '');
        if ($confirm !== '' && (string)$confirm !== (string)$newPassword) {
            $this->error(FansHubService::h5CopyText('api_password_mismatch'));
        }
        try {
            $data = FansHubService::changePassword(
                $this->auth->id,
                $newPassword,
                $mode,
                $oldPassword,
                $captcha
            );
            $this->success(FansHubService::h5CopyText('api_password_ok'), $data);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->error($e->getMessage() ?: FansHubService::h5CopyText('api_operation_fail'));
        }
    }

    /**
     * 退出登录
     */
    public function logout()
    {
        $this->auth->logout();
        $this->success(FansHubService::h5CopyText('api_logout_ok'));
    }

    /**
     * 钱包信息（余额/流水/提现门槛）
     */
    public function walletinfo()
    {
        $this->success('ok', \app\common\library\FansHubWallet::walletInfo($this->auth->id));
    }

    /**
     * 资金流水列表
     */
    public function walletledger()
    {
        $page = (int)$this->request->post('page', 1);
        $limit = (int)$this->request->post('limit', 20);
        $this->success('ok', \app\common\library\FansHubWallet::ledgerList($this->auth->id, $page, $limit));
    }

    /**
     * 充值通道列表
     */
    public function rechargechannels()
    {
        $this->success('ok', ['list' => \app\common\library\FansHubWallet::listChannels('recharge')]);
    }

    /**
     * 提现通道列表
     */
    public function withdrawchannels()
    {
        $this->success('ok', ['list' => \app\common\library\FansHubWallet::listChannels('withdraw')]);
    }

    /**
     * 提交充值
     */
    public function recharge()
    {
        $channelId = (int)$this->request->post('channel_id', 0);
        $amount = (float)$this->request->post('amount', 0);
        try {
            $data = \app\common\library\FansHubWallet::recharge($this->auth->id, $channelId, $amount);
            $this->success('充值申请已提交', $data);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->error($e->getMessage() ?: FansHubService::h5CopyText('api_operation_fail'));
        }
    }

    /**
     * 提交提现
     */
    public function withdraw()
    {
        $channelId = (int)$this->request->post('channel_id', 0);
        $amount = (float)$this->request->post('amount', 0);
        $accountInfo = $this->request->post('account_info/a', []);
        if (!is_array($accountInfo)) {
            $accountInfo = [];
        }
        try {
            $data = \app\common\library\FansHubWallet::withdraw($this->auth->id, $channelId, $amount, $accountInfo);
            $this->success('提现申请已提交', $data);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->error($e->getMessage() ?: FansHubService::h5CopyText('api_operation_fail'));
        }
    }

    /**
     * 规范化手机号（支持 E.164 或 国家码+本国号码）
     */
    protected function normalizeMobileInput()
    {
        $mobile = trim((string)$this->request->post('mobile', ''));
        $country = strtoupper(trim((string)$this->request->post('country_code', '')));
        if ($mobile === '') {
            return '';
        }
        if ($mobile[0] === '+') {
            if (!FansHubMobile::isValid($mobile)) {
                return '';
            }
            $country = FansHubMobile::detectCountryFromMobile($mobile);
            return FansHubMobile::normalize($mobile, $country);
        }
        if ($country === '') {
            $country = FansHubMobile::detectCountryFromMobile($mobile);
        }
        return FansHubMobile::normalize($mobile, $country);
    }
}
