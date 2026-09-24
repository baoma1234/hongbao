define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/welfareclaim/index' + location.search,
                    add_url: 'fanshub/welfareclaim/add',
                    edit_url: 'fanshub/welfareclaim/edit',
                    del_url: 'fanshub/welfareclaim/del',
                    multi_url: 'fanshub/welfareclaim/multi',
                    table: 'fans_welfare_rp_daily'
                }
            });
            var table = $("#table");
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                sortOrder: 'desc',
                columns: [[
                    {checkbox: true},
                    {field: 'id', title: 'ID', sortable: true},
                    {field: 'user_id', title: '用户UID', sortable: true},
                    {field: 'nickname', title: '昵称', operate: false},
                    {field: 'quota_date', title: '日期(Ymd)', visible: false},
                    {field: 'quota_date_text', title: '日期', operate: false},
                    {field: 'claim_count', title: '已领', sortable: true},
                    {field: 'entertain_count', title: '娱乐发/抢', sortable: true},
                    {field: 'bonus_chance', title: '娱乐加成', operate: false},
                    {field: 'admin_extra', title: '后台加减', sortable: true},
                    {field: 'free_limit', title: '个人免费', sortable: true, formatter: function (v) {
                        return parseInt(v, 10) > 0 ? v : '全局';
                    }},
                    {field: 'effective_limit', title: '有效上限', operate: false},
                    {field: 'remain', title: '剩余', operate: false},
                    {field: 'updatetime', title: __('Update time'), operate: 'RANGE', addclass: 'datetimerange', formatter: Table.api.formatter.datetime, sortable: true},
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
