define(['jquery', 'bootstrap', 'backend', 'table', './common'], function ($, undefined, Backend, Table, FanshubCommon) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/loginlog/index',
                    export_url: 'fanshub/loginlog/export',
                    table: 'fans_login_log',
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
                    {field: 'ip', title: 'IP', operate: 'LIKE'},
                    {field: 'device_fingerprint', title: '设备指纹', operate: 'LIKE'},
                    {field: 'user_agent', title: 'User-Agent', operate: 'LIKE'},
                    {field: 'createtime', title: '时间', operate: 'RANGE', addclass: 'datetimerange', formatter: Table.api.formatter.datetime}
                ]]
            });
            Table.api.bindevent(table);
            FanshubCommon.bindExport(table, $.fn.bootstrapTable.defaults.extend.export_url);
        }
    };
    return Controller;
});
