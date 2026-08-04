define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/contactremark/index',
                    del_url: 'fanshub/contactremark/del',
                    table: 'chat_user_remarks',
                }
            });
            var table = $("#table");
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'updatetime',
                sortOrder: 'desc',
                columns: [[
                    {checkbox: true},
                    {field: 'id', title: 'ID'},
                    {field: 'user_id', title: '设置者ID'},
                    {field: 'owner.nickname', title: '设置者昵称', operate: false},
                    {field: 'owner.mobile', title: '设置者手机', operate: 'LIKE'},
                    {field: 'peer_user_id', title: '对方ID'},
                    {field: 'peer.nickname', title: '对方昵称', operate: false},
                    {field: 'peer.mobile', title: '对方手机', operate: 'LIKE'},
                    {field: 'remark', title: '备注', operate: 'LIKE'},
                    {field: 'createtime', title: '创建时间', operate: 'RANGE', addclass: 'datetimerange', formatter: Table.api.formatter.datetime},
                    {field: 'updatetime', title: '更新时间', operate: 'RANGE', addclass: 'datetimerange', formatter: Table.api.formatter.datetime},
                    {field: 'operate', title: '操作', table: table, events: Table.api.events.operate, formatter: Table.api.formatter.operate}
                ]]
            });
            Table.api.bindevent(table);
        }
    };
    return Controller;
});
