define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/niuniu/index',
                    detail_url: 'fanshub/niuniu/detail',
                    table: 'chat_niuniu_rounds'
                }
            });
            var table = $("#table");
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                sortOrder: 'desc',
                columns: [
                    [
                        {field: 'id', title: 'ID', sortable: true},
                        {field: 'group_id', title: '群ID'},
                        {field: 'status', title: '状态', formatter: function (v) {
                            var m = {1:'购入中',2:'领取中',3:'已结算',4:'作废',5:'流局'};
                            return m[v] || v;
                        }},
                        {field: 'share_count', title: '份数'},
                        {field: 'pool_amount', title: '奖池'},
                        {field: 'fee_amount', title: '手续费'},
                        {field: 'distributable', title: '可发放'},
                        {field: 'drand_round', title: 'drand轮次'},
                        {field: 'createtime', title: '创建时间', formatter: Table.api.formatter.datetime, operate: 'RANGE', addclass: 'datetimerange', sortable: true},
                        {field: 'operate', title: __('Operate'), table: table, events: Table.api.events.operate, formatter: Table.api.formatter.operate}
                    ]
                ]
            });
            Table.api.bindevent(table);
        },
        detail: function () {}
    };
    return Controller;
});
