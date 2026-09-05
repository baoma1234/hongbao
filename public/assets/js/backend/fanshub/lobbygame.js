define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/lobbygame/index',
                    add_url: 'fanshub/lobbygame/add',
                    edit_url: 'fanshub/lobbygame/edit',
                    del_url: 'fanshub/lobbygame/del',
                    multi_url: 'fanshub/lobbygame/multi',
                    table: 'fans_lobby_games'
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
                    {field: 'title', title: '游戏名', operate: 'LIKE'},
                    {field: 'cover', title: '封面', operate: false, formatter: Table.api.formatter.image},
                    {field: 'cats', title: '分类', operate: 'LIKE'},
                    {field: 'badge', title: '角标', operate: false},
                    {field: 'coming_soon', title: '敬请期待', formatter: function (v) { return parseInt(v, 10) ? '是' : '否'; }},
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
