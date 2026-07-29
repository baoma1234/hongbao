define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'backend/sys/platform'], function ($, undefined, Backend, Table, Form, Platform) {

    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'sys/urge_schedule/index' + location.search,
                    add_url: 'sys/urge_schedule/add',
                    edit_url: 'sys/urge_schedule/edit',
                    del_url: 'sys/urge_schedule/del',
                    multi_url: 'sys/urge_schedule/multi',
                    table: 'sys_urge_schedule',
                }
            });

            var table = $("#table");
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'sort',
                columns: [
                    [
                        {checkbox: true},
                        {field: 'id', title: __('Id')},
                        {field: 'pid', title: __('Pid'), searchList: Platform.searchList, formatter: Platform.formatter},
                        {field: 'sort', title: __('Sort')},
                        {field: 'minutes', title: __('Minutes')},
                        {field: 'type', title: __('Type'), searchList: {"fixed": __('Fixed'), "repeat": __('Repeat')}, formatter: Table.api.formatter.normal},
                        {field: 'repeat_interval', title: __('Repeat_interval')},
                        {field: 'status', title: __('Status'), searchList: {"normal": __('Normal'), "hidden": __('Hidden')}, formatter: Table.api.formatter.status},
                        {field: 'remark', title: __('Remark'), operate: 'LIKE'},
                        {field: 'createtime', title: __('Createtime'), operate: 'RANGE', addclass: 'datetimerange', autocomplete: false, formatter: Table.api.formatter.datetime},
                        {field: 'operate', title: __('Operate'), table: table, events: Table.api.events.operate, formatter: Table.api.formatter.operate}
                    ]
                ]
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
