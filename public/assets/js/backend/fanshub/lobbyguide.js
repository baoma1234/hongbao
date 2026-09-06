define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/lobbyguide/index',
                    add_url: 'fanshub/lobbyguide/add',
                    edit_url: 'fanshub/lobbyguide/edit',
                    del_url: 'fanshub/lobbyguide/del',
                    multi_url: 'fanshub/lobbyguide/multi',
                    table: 'fans_lobby_guides'
                }
            });
            var table = $('#table');
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'weigh',
                sortOrder: 'desc',
                columns: [[
                    {checkbox: true},
                    {field: 'id', title: 'ID'},
                    {field: 'game_key', title: 'Key', operate: 'LIKE'},
                    {field: 'title', title: '标题', operate: 'LIKE'},
                    {field: 'intro', title: '简介', operate: 'LIKE', formatter: function (v) {
                        v = String(v || '');
                        return v.length > 36 ? v.substr(0, 36) + '…' : v;
                    }},
                    {field: 'weigh', title: '排序', sortable: true},
                    {field: 'status', title: '显示', searchList: Config.statusList, formatter: Table.api.formatter.status},
                    {field: 'operate', title: __('Operate'), table: table, events: Table.api.events.operate, formatter: Table.api.formatter.operate}
                ]]
            });
            Table.api.bindevent(table);
        },
        add: function () { Controller.api.bindevent(); },
        edit: function () { Controller.api.bindevent(); },
        api: { bindevent: function () { Form.api.bindevent($('form[role=form]')); } }
    };
    return Controller;
});
