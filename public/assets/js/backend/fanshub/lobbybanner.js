define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/lobbybanner/index',
                    add_url: 'fanshub/lobbybanner/add',
                    edit_url: 'fanshub/lobbybanner/edit',
                    del_url: 'fanshub/lobbybanner/del',
                    multi_url: 'fanshub/lobbybanner/multi',
                    table: 'fans_lobby_banners'
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
                    {field: 'image', title: '图片', operate: false, formatter: Table.api.formatter.image},
                    {field: 'link_type', title: '跳转', searchList: Config.linkTypeList, formatter: Table.api.formatter.normal},
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
