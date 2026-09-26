define(['jquery', 'bootstrap', 'backend', 'table', 'form', './lobby-common'], function ($, undefined, Backend, Table, Form, Lobby) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/lobbyfloat/index',
                    add_url: 'fanshub/lobbyfloat/add',
                    edit_url: 'fanshub/lobbyfloat/edit',
                    del_url: 'fanshub/lobbyfloat/del',
                    multi_url: 'fanshub/lobbyfloat/multi',
                    table: 'fans_lobby_floats'
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
                    {field: 'title', title: '备注', operate: 'LIKE'},
                    {field: 'image', title: '图标', operate: false, formatter: Lobby.imageFormatter, events: Table.api.events.image},
                    {field: 'side', title: '位置', searchList: Config.sideList, formatter: Table.api.formatter.normal},
                    {field: 'link_type', title: '跳转', searchList: Config.linkTypeList, formatter: Table.api.formatter.normal},
                    {field: 'link_url', title: '地址', operate: 'LIKE', formatter: Table.api.formatter.content},
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
