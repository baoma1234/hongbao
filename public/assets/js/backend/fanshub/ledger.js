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
            var table = $("#table");
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                columns: [[
                    {field: 'id', title: 'ID'},
                    {field: 'user_id', title: '会员ID'},
                    {field: 'user.mobile', title: '手机号', operate: 'LIKE'},
                    {field: 'type', title: '类型', searchList: {
                        "register": "注册赠送", "share": "分享奖励", "invite": "邀请奖励",
                        "open_account": "开户奖励",
                        "exchange": "闪兑", "admin_adjust": "人工调整"
                    }, formatter: Table.api.formatter.normal},
                    {field: 'rights_change', title: '股份变动'},
                    {field: 'hongbao_change', title: '红宝变动', operate: false},
                    {field: 'rights_after', title: '股份结余'},
                    {field: 'hongbao_after', title: '红宝结余', operate: false},
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
