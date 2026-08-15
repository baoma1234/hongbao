define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/pushdevice/index',
                    del_url: 'fanshub/pushdevice/del',
                    multi_url: 'fanshub/pushdevice/multi',
                    table: 'chat_push_devices'
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
                    {field: 'user_id', title: '用户ID', operate: '='},
                    {field: 'registration_id', title: 'Registration ID', operate: 'LIKE'},
                    {
                        field: 'platform', title: '平台',
                        searchList: Config.platformList,
                        formatter: Table.api.formatter.normal
                    },
                    {
                        field: 'enabled', title: '推送开关',
                        searchList: {1: '开', 0: '关'},
                        formatter: Table.api.formatter.toggle
                    },
                    {
                        field: 'last_login_time', title: '最近上报', operate: 'RANGE', addclass: 'datetimerange',
                        formatter: Table.api.formatter.datetime
                    },
                    {
                        field: 'updatetime', title: '更新', operate: 'RANGE', addclass: 'datetimerange',
                        formatter: Table.api.formatter.datetime
                    },
                    {field: 'operate', title: __('Operate'), table: table, events: Table.api.events.operate, formatter: Table.api.formatter.operate}
                ]]
            });
            Table.api.bindevent(table);
        }
    };
    return Controller;
});
