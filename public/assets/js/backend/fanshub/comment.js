define(['jquery', 'bootstrap', 'backend', 'table', 'form', './common'], function ($, undefined, Backend, Table, Form, FanshubCommon) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/comment/index',
                    edit_url: 'fanshub/comment/edit',
                    del_url: 'fanshub/comment/del',
                    export_url: 'fanshub/comment/export',
                    table: 'fans_comment',
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
                    {field: 'content', title: '内容', operate: 'LIKE'},
                    {field: 'status', title: '状态', searchList: {
                        "pending": "待审核", "approved": "已通过", "rejected": "已拒绝"
                    }, formatter: Table.api.formatter.status},
                    {field: 'createtime', title: '时间', operate: 'RANGE', addclass: 'datetimerange', formatter: Table.api.formatter.datetime},
                    {
                        field: 'operate', title: '操作', table: table,
                        buttons: [
                            {
                                name: 'approve',
                                text: '通过',
                                title: '通过',
                                classname: 'btn btn-xs btn-success btn-ajax',
                                icon: 'fa fa-check',
                                url: 'fanshub/comment/approve',
                                confirm: '确认通过该留言？',
                                visible: function (row) {
                                    return row.status !== 'approved';
                                },
                                success: function () { table.bootstrapTable('refresh'); }
                            },
                            {
                                name: 'reject',
                                text: '拒绝',
                                title: '拒绝',
                                classname: 'btn btn-xs btn-warning btn-ajax',
                                icon: 'fa fa-ban',
                                url: 'fanshub/comment/reject',
                                confirm: '确认拒绝该留言？',
                                success: function () { table.bootstrapTable('refresh'); }
                            }
                        ],
                        events: Table.api.events.operate,
                        formatter: Table.api.formatter.operate
                    }
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
