define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/messagespopup/index',
                    add_url: 'fanshub/messagespopup/add',
                    edit_url: 'fanshub/messagespopup/edit',
                    del_url: 'fanshub/messagespopup/del',
                    multi_url: 'fanshub/messagespopup/multi',
                    table: 'chat_messages_popups'
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
                    {field: 'title', title: '标题', operate: 'LIKE'},
                    {
                        field: 'images', title: '配图', operate: false,
                        formatter: Table.api.formatter.images
                    },
                    {
                        field: 'jump_type', title: '跳转',
                        searchList: Config.jumpTypeList,
                        formatter: Table.api.formatter.normal
                    },
                    {field: 'btn_text', title: '按钮', operate: false},
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
                        field: 'operate', title: __('Operate'), table: table,
                        events: Table.api.events.operate,
                        formatter: Table.api.formatter.operate
                    }
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
                Form.api.bindevent($('form[role=form]'));
            }
        }
    };
    return Controller;
});
