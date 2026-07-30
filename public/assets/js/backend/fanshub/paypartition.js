define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/paypartition/index',
                    add_url: 'fanshub/paypartition/add',
                    edit_url: 'fanshub/paypartition/edit',
                    del_url: 'fanshub/paypartition/del',
                    multi_url: 'fanshub/paypartition/multi',
                    table: 'fans_pay_partition',
                }
            });
            var table = $("#table");
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'weigh',
                sortOrder: 'desc',
                columns: [[
                    {checkbox: true},
                    {field: 'id', title: 'ID'},
                    {field: 'type', title: '类型', searchList: Config.typeList, formatter: Table.api.formatter.normal},
                    {field: 'code', title: '编码', operate: 'LIKE'},
                    {field: 'name', title: '名称', operate: 'LIKE'},
                    {field: 'bind_mode', title: '绑定方式', searchList: Config.bindModeList, formatter: Table.api.formatter.normal},
                    {field: 'weigh', title: '排序'},
                    {field: 'status', title: '状态', searchList: Config.statusList, formatter: Table.api.formatter.status},
                    {field: 'updatetime', title: '更新时间', operate: 'RANGE', addclass: 'datetimerange', formatter: Table.api.formatter.datetime},
                    {field: 'operate', title: __('Operate'), table: table, events: Table.api.events.operate, formatter: Table.api.formatter.operate}
                ]]
            });
            Table.api.bindevent(table);
        },
        add: function () {
            Controller.api.bindevent();
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
