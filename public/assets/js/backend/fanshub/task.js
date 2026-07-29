define(['jquery', 'bootstrap', 'backend', 'table', './common'], function ($, undefined, Backend, Table, FanshubCommon) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/task/index',
                    export_url: 'fanshub/task/export',
                    table: 'fans_task',
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
                    {field: 'task_type', title: '类型', searchList: {
                        "share": "分享奖励", "open_account": "开户奖励", "exchange": "闪兑", "invite": "邀请奖励"
                    }},
                    {field: 'channel', title: '通道', operate: 'LIKE'},
                    {field: 'rights', title: '股份变动'},
                    {field: 'balance', title: '余额变动'},
                    {field: 'extra', title: '备注', operate: 'LIKE'},
                    {field: 'ip', title: 'IP'},
                    {field: 'createtime', title: '时间', operate: 'RANGE', addclass: 'datetimerange', formatter: Table.api.formatter.datetime}
                ]]
            });
            Table.api.bindevent(table);
            FanshubCommon.bindExport(table, $.fn.bootstrapTable.defaults.extend.export_url);
        }
    };
    return Controller;
});
