define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/redpacketskin/index',
                    add_url: 'fanshub/redpacketskin/add',
                    edit_url: 'fanshub/redpacketskin/edit',
                    del_url: 'fanshub/redpacketskin/del',
                    multi_url: 'fanshub/redpacketskin/multi',
                    table: 'chat_red_packet_skins'
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
                    {field: 'name', title: '名称', operate: 'LIKE'},
                    {
                        field: 'packet_type', title: '类型',
                        searchList: {0: '通用', 2: '手气包', 3: '埋雷包'},
                        formatter: Table.api.formatter.normal
                    },
                    {
                        field: 'image', title: '封面', operate: false,
                        formatter: Table.api.formatter.image
                    },
                    {field: 'width', title: '宽', operate: false},
                    {field: 'height', title: '高', operate: false},
                    {field: 'weigh', title: '排序', sortable: true},
                    {
                        field: 'status', title: '状态',
                        searchList: {normal: '启用', hidden: '停用'},
                        formatter: Table.api.formatter.status
                    },
                    {
                        field: 'createtime', title: '创建时间', operate: 'RANGE',
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
