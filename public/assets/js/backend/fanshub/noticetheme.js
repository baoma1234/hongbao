define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/noticetheme/index' + location.search,
                    add_url: 'fanshub/noticetheme/add',
                    edit_url: 'fanshub/noticetheme/edit',
                    del_url: 'fanshub/noticetheme/del',
                    multi_url: 'fanshub/noticetheme/multi',
                    table: 'fans_notice_theme',
                }
            });
            var table = $("#table");
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'weigh',
                sortOrder: 'desc',
                columns: [
                    [
                        {checkbox: true},
                        {field: 'id', title: __('Id'), sortable: true},
                        {
                            field: 'category',
                            title: '所属模块',
                            searchList: Config.categoryList || {},
                            formatter: Table.api.formatter.normal
                        },
                        {field: 'code', title: '编码', operate: 'LIKE'},
                        {field: 'title', title: '主题名', operate: 'LIKE'},
                        {field: 'weigh', title: '权重', sortable: true},
                        {field: 'status', title: __('Status'), searchList: Config.statusList, formatter: Table.api.formatter.status},
                        {field: 'updatetime', title: __('Updatetime'), operate: 'RANGE', addclass: 'datetimerange', formatter: Table.api.formatter.datetime, sortable: true},
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
