define(['jquery', 'bootstrap', 'backend', 'table', 'form', './lobby-common'], function ($, undefined, Backend, Table, Form, Lobby) {
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
                    {field: 'title', title: '标题', operate: 'LIKE'},
                    {field: 'cover', title: '封面', operate: false, formatter: Lobby.imageFormatter, events: Table.api.events.image},
                    {field: 'badge', title: '角标', operate: 'LIKE'},
                    {field: 'cats', title: '分类', operate: 'LIKE'},
                    {field: 'weigh', title: '排序', sortable: true},
                    {field: 'status', title: '状态', searchList: Config.statusList, formatter: Table.api.formatter.status},
                    {field: 'operate', title: __('Operate'), table: table, events: Table.api.events.operate, formatter: Table.api.formatter.operate}
                ]]
            });
            Table.api.bindevent(table);
        },
        add: function () { Controller.api.bindevent(); },
        edit: function () { Controller.api.bindevent(); },
        api: {
            bindevent: function () {
                Form.api.bindevent($('form[role=form]'));
                Lobby.refreshPreviews($('form[role=form]'));
            }
        }
    };
    return Controller;
});
