define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/grouppopup/index',
                    add_url: 'fanshub/grouppopup/add',
                    edit_url: 'fanshub/grouppopup/edit',
                    del_url: 'fanshub/grouppopup/del',
                    multi_url: 'fanshub/grouppopup/multi',
                    table: 'chat_group_popups'
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
                    {field: 'group_id', title: '群ID', operate: '='},
                    {field: 'group_name', title: '群名称', operate: false},
                    {field: 'title', title: '标题', operate: 'LIKE'},
                    {
                        field: 'images', title: '配图', operate: false,
                        formatter: Table.api.formatter.images
                    },
                    {
                        field: 'show_mode', title: '展示规则',
                        searchList: Config.showModeList,
                        formatter: Table.api.formatter.normal
                    },
                    {field: 'weigh', title: '排序', sortable: true},
                    {
                        field: 'status', title: '状态',
                        searchList: Config.statusList,
                        formatter: Table.api.formatter.status
                    },
                    {
                        field: 'updatetime', title: '更新时间', operate: 'RANGE',
                        addclass: 'datetimerange', formatter: Table.api.formatter.datetime
                    },
                    {
                        field: 'operate', title: __('Operate'), table: table,
                        events: Table.api.events.operate, formatter: Table.api.formatter.operate
                    }
                ]]
            });
            Table.api.bindevent(table);
        },
        add: function () { Controller.api.bindevent(); },
        edit: function () { Controller.api.bindevent(); },
        api: {
            bindevent: function () {
                Form.api.bindevent($('form[role=form]'));
            }
        }
    };
    return Controller;
});
