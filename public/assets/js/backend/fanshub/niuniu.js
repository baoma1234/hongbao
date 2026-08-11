define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/niuniu/index' + location.search,
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
                            var m = {1: '购入中', 2: '领取中', 3: '已结算', 4: '作废', 5: '流局'};
                            return m[v] || v;
                        }},
                        {field: 'game_mode', title: '玩法', formatter: function (v) {
                            return parseInt(v, 10) === 2 ? '单结果' : '多包';
                        }},
                        {field: 'share_count', title: '份数'},
                        {field: 'pool_amount', title: '奖池'},
                        {field: 'fee_amount', title: '手续费'},
                        {field: 'distributable', title: '可发放'},
                        {field: 'tron_block_num', title: '波场区块', formatter: function (v, row) {
                            return v || row.drand_round || '-';
                        }},
                        {field: 'createtime', title: '创建时间', formatter: Table.api.formatter.datetime, operate: 'RANGE', addclass: 'datetimerange', sortable: true},
                        {
                            field: 'operate',
                            title: __('Operate'),
                            table: table,
                            events: Table.api.events.operate,
                            buttons: [
                                {
                                    name: 'detail',
                                    text: '领取结果',
                                    title: '领取结果',
                                    classname: 'btn btn-xs btn-info btn-dialog',
                                    icon: 'fa fa-list',
                                    url: 'fanshub/niuniu/detail'
                                }
                            ],
                            formatter: Table.api.formatter.operate
                        }
                    ]
                ]
            });
            Table.api.bindevent(table);
        },
        detail: function () {}
    };
    return Controller;
});
