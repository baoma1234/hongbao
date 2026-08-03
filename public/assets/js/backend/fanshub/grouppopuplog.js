define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/grouppopuplog/index',
                    del_url: 'fanshub/grouppopuplog/del',
                    table: 'chat_group_popup_logs'
                }
            });
            var table = $('#table');
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                sortOrder: 'desc',
                columns: [[
                    {checkbox: true},
                    {field: 'id', title: 'ID'},
                    {field: 'popup_id', title: '弹窗ID', operate: '='},
                    {field: 'popup.title', title: '弹窗标题', operate: false},
                    {field: 'group_id', title: '群ID', operate: '='},
                    {field: 'group_name', title: '群名称', operate: false},
                    {field: 'user_id', title: '用户ID', operate: '='},
                    {field: 'user.nickname', title: '昵称', operate: false},
                    {field: 'user.mobile', title: '手机号', operate: 'LIKE'},
                    {
                        field: 'action', title: '动作',
                        searchList: Config.actionList,
                        formatter: Table.api.formatter.normal
                    },
                    {
                        field: 'createtime', title: '时间', operate: 'RANGE',
                        addclass: 'datetimerange', formatter: Table.api.formatter.datetime
                    },
                    {
                        field: 'operate', title: __('Operate'), table: table,
                        events: Table.api.events.operate, formatter: Table.api.formatter.operate
                    }
                ]]
            });
            Table.api.bindevent(table);
        }
    };
    return Controller;
});
