define(['jquery', 'bootstrap', 'backend', 'table', 'form', './common'], function ($, undefined, Backend, Table, Form, FanshubCommon) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/secret/index',
                    edit_url: 'fanshub/secret/edit',
                    export_url: 'fanshub/secret/export',
                    table: 'fans_secret',
                }
            });
            var table = $("#table");
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                columns: [[
                    {checkbox: true},
                    {field: 'id', title: 'ID'},
                    {field: 'user_id', title: '会员ID'},
                    {field: 'user.mobile', title: '手机号', operate: 'LIKE'},
                    {field: 'code', title: '密令', operate: 'LIKE'},
                    {field: 'amount', title: '金额'},
                    {field: 'tier', title: '等级', searchList: {"VIP": "VIP", "GREEN": "GREEN"}},
                    {field: 'main_uid', title: 'UID'},
                    {field: 'status', title: '状态', searchList: {
                        "pending": "待联系", "contacted": "已联系", "completed": "已完成", "expired": "已过期"
                    }, formatter: Table.api.formatter.status},
                    {field: 'expiretime', title: '过期时间', formatter: Table.api.formatter.datetime},
                    {field: 'createtime', title: '创建时间', operate: 'RANGE', addclass: 'datetimerange', formatter: Table.api.formatter.datetime},
                    {field: 'operate', title: '操作', table: table, events: Table.api.events.operate, formatter: Table.api.formatter.operate}
                ]]
            });
            Table.api.bindevent(table);
            FanshubCommon.bindExport(table, $.fn.bootstrapTable.defaults.extend.export_url);
        },
        edit: function () {
            Controller.api.bindevent();
        },
        api: {
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
            }
        }
    };
    return Controller;
});
