define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/friendrequest/index',
                    del_url: 'fanshub/friendrequest/del',
                    table: 'chat_friend_requests',
                }
            });
            var table = $("#table");
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                sortOrder: 'desc',
                columns: [[
                    {checkbox: true},
                    {field: 'id', title: 'ID'},
                    {field: 'from_user_id', title: '申请人ID'},
                    {field: 'fromuser.nickname', title: '申请人昵称', operate: false},
                    {field: 'fromuser.mobile', title: '申请人手机', operate: 'LIKE'},
                    {field: 'to_user_id', title: '被申请人ID'},
                    {field: 'touser.nickname', title: '被申请人昵称', operate: false},
                    {field: 'touser.mobile', title: '被申请人手机', operate: 'LIKE'},
                    {field: 'message', title: '附言', operate: 'LIKE'},
                    {field: 'status', title: '状态', searchList: {
                        "0": "待处理", "1": "已通过", "2": "已拒绝", "3": "已取消"
                    }, formatter: Table.api.formatter.status},
                    {field: 'createtime', title: '申请时间', operate: 'RANGE', addclass: 'datetimerange', formatter: Table.api.formatter.datetime},
                    {field: 'updatetime', title: '处理时间', operate: 'RANGE', addclass: 'datetimerange', formatter: Table.api.formatter.datetime},
                    {
                        field: 'operate', title: '操作', table: table,
                        buttons: [
                            {
                                name: 'approve',
                                text: '通过',
                                title: '通过',
                                classname: 'btn btn-xs btn-success btn-ajax',
                                icon: 'fa fa-check',
                                url: 'fanshub/friendrequest/approve',
                                confirm: '确认通过该好友申请？将互为好友并发送问候语。',
                                visible: function (row) { return String(row.status) === '0'; },
                                success: function () { table.bootstrapTable('refresh'); }
                            },
                            {
                                name: 'reject',
                                text: '拒绝',
                                title: '拒绝',
                                classname: 'btn btn-xs btn-warning btn-ajax',
                                icon: 'fa fa-ban',
                                url: 'fanshub/friendrequest/reject',
                                confirm: '确认拒绝该好友申请？',
                                visible: function (row) { return String(row.status) === '0'; },
                                success: function () { table.bootstrapTable('refresh'); }
                            }
                        ],
                        events: Table.api.events.operate,
                        formatter: Table.api.formatter.operate
                    }
                ]]
            });
            Table.api.bindevent(table);
        }
    };
    return Controller;
});
