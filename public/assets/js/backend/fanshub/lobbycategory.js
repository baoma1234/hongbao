define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/lobbycategory/index',
                    add_url: 'fanshub/lobbycategory/add',
                    edit_url: 'fanshub/lobbycategory/edit',
                    del_url: 'fanshub/lobbycategory/del',
                    multi_url: 'fanshub/lobbycategory/multi',
                    table: 'fans_lobby_categories'
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
                    {field: 'cat_key', title: 'Key', operate: 'LIKE'},
                    {field: 'title', title: '分类名', operate: 'LIKE'},
                    {field: 'icon', title: '图标', operate: false, formatter: Table.api.formatter.image},
                    {field: 'icon_static', title: '打包图标', operate: false},
                    {field: 'action', title: '动作', searchList: Config.actionList, formatter: Table.api.formatter.normal},
                    {field: 'weigh', title: '排序', sortable: true},
                    {field: 'status', title: '状态', searchList: Config.statusList, formatter: Table.api.formatter.status},
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
