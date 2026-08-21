define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/telegramuser/index',
                    del_url: 'fanshub/telegramuser/del',
                    table: 'fans_telegram_bind',
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
                    {field: 'tg_user_id', title: 'TG用户ID', operate: '='},
                    {field: 'tg_username', title: 'TG用户名', operate: 'LIKE', formatter: function (v, row) {
                        if (!v) return '-';
                        var name = '@' + v;
                        if (row.tg_link) {
                            return '<a href="' + row.tg_link + '" target="_blank" rel="noopener">' + name + '</a>';
                        }
                        return name;
                    }},
                    {field: 'tg_first_name', title: 'TG昵称', operate: 'LIKE', formatter: function (v, row) {
                        return [v || '', row.tg_last_name || ''].join(' ').trim() || '-';
                    }},
                    {field: 'user_id', title: '会员ID', operate: '='},
                    {field: 'user.mobile', title: '手机号', operate: 'LIKE'},
                    {field: 'user.nickname', title: '站内昵称', operate: 'LIKE'},
                    {field: 'createtime', title: '绑定时间', operate: 'RANGE', addclass: 'datetimerange', formatter: Table.api.formatter.datetime},
                    {field: 'updatetime', title: '更新时间', operate: 'RANGE', addclass: 'datetimerange', formatter: Table.api.formatter.datetime, visible: false},
                    {
                        field: 'operate',
                        title: __('Operate'),
                        table: table,
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
