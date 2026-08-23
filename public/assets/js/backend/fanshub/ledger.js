define(['jquery', 'bootstrap', 'backend', 'table', 'form', './common'], function ($, undefined, Backend, Table, Form, FanshubCommon) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/ledger/index',
                    export_url: 'fanshub/ledger/export',
                    table: 'fans_ledger',
                }
            });
            var forceUid = Fast.api.query('user_id') || '';
            var table = $("#table");
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                queryParams: function (params) {
                    if (forceUid) {
                        params.user_id = forceUid;
                    }
                    return params;
                },
                columns: [[
                    {field: 'id', title: 'ID'},
                    {field: 'user_id', title: '会员ID', defaultValue: forceUid},
                    {field: 'user.mobile', title: '手机号', operate: 'LIKE'},
                    {field: 'type', title: '类型', searchList: (function () {
                        // 与 FansHubWallet::ledgerTypeLabels 对齐；缺失类型也能筛到冻结/返佣
                        return {
                            "register": "注册赠送", "register_bonus": "拉新股份", "share": "分享奖励", "invite": "邀请奖励",
                            "open_account": "开户奖励", "exchange": "闪兑", "admin_adjust": "人工调整",
                            "checkin": "星火签到", "checkin_bonus": "暴力对账", "checkin_day7": "7天暴击", "honor_tier": "荣誉晋升",
                            "recharge": "充值入账", "withdraw": "提现扣款", "withdraw_refund": "提现退回",
                            "red_packet_send": "红宝发包扣款", "red_packet_grab": "红宝入账", "red_packet_refund": "红宝退回",
                            "red_packet_fee": "红包手续费", "red_packet_fee_in": "红包手续费收入",
                            "red_packet_rebate": "推荐发包返佣", "red_packet_agent_rebate": "红宝返佣支出",
                            "red_packet_agent_rebate_in": "群主返佣", "red_packet_invite_rebate_in": "推荐发包返佣",
                            "red_packet_dual_rebate_in": "群主+推荐双重返佣",
                            "red_packet_mine_pay": "红宝扫雷赔付", "red_packet_worst_pay": "红宝拼手气赔付",
                            "red_packet_compensate_in": "红包赔付入账",
                            "red_packet_freeze": "红宝冻结", "red_packet_unfreeze": "红宝解冻",
                            "red_packet_expire_clawback": "未领完此包作废收回金额"
                        };
                    })(), formatter: Table.api.formatter.normal},
                    {field: 'rights_change', title: '股份变动', operate: 'BETWEEN'},
                    {field: 'hongbao_change', title: '红宝变动', operate: 'BETWEEN'},
                    {field: 'rights_after', title: '股份结余', operate: 'BETWEEN'},
                    {field: 'hongbao_after', title: '红宝结余', operate: 'BETWEEN'},
                    {field: 'biz_no', title: '红宝号', operate: 'LIKE'},
                    {field: 'remark', title: '备注', operate: 'LIKE'},
                    {field: 'channel', title: '通道', operate: 'LIKE'},
                    {field: 'createtime', title: '时间', operate: 'RANGE', addclass: 'datetimerange', formatter: Table.api.formatter.datetime}
                ]]
            });
            Table.api.bindevent(table);
            FanshubCommon.bindExport(table, $.fn.bootstrapTable.defaults.extend.export_url);
        }
    };
    return Controller;
});
