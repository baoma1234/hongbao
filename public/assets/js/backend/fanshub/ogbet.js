define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/ogbet/index',
                    detail_url: 'fanshub/ogbet/detail',
                    table: 'fans_og_bet',
                }
            });
            var table = $("#table");
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                sortOrder: 'desc',
                columns: [[
                    {field: 'id', title: 'ID', operate: false},
                    {field: 'fetch_id', title: 'fetch_id', operate: 'BETWEEN'},
                    {field: 'user_id', title: 'UID', operate: '='},
                    {field: 'user.nickname', title: '昵称', operate: 'LIKE'},
                    {field: 'user.mobile', title: '手机', operate: 'LIKE'},
                    {field: 'player_id', title: 'player_id', operate: 'LIKE'},
                    {field: 'transaction_id', title: '注单号', operate: 'LIKE', formatter: Table.api.formatter.search},
                    {field: 'game_type_id', title: '类型', searchList: Config.gameTypeList, formatter: Table.api.formatter.normal},
                    {field: 'game_id', title: 'game_id', operate: '='},
                    {field: 'round_id', title: '局号', operate: '='},
                    {field: 'game_name', title: '游戏', operate: 'LIKE'},
                    {field: 'bet_place', title: '下注', operate: 'LIKE'},
                    {field: 'debit_amount', title: '扣款', operate: 'BETWEEN'},
                    {field: 'credit_amount', title: '返还', operate: 'BETWEEN'},
                    {field: 'winlose_amount', title: '输赢', operate: 'BETWEEN'},
                    {field: 'effective_amount', title: '有效投注', operate: 'BETWEEN'},
                    {field: 'currency', title: '币种', operate: 'LIKE'},
                    {field: 'transaction_type', title: '类型', operate: 'LIKE'},
                    {field: 'debit_at', title: '下注时间', operate: 'RANGE', addclass: 'datetimerange', formatter: Table.api.formatter.datetime, sortable: true},
                    {field: 'credit_at', title: '结算时间', operate: 'RANGE', addclass: 'datetimerange', formatter: Table.api.formatter.datetime},
                    {
                        field: 'operate', title: '操作', table: table,
                        events: Table.api.events.operate,
                        buttons: [{
                            name: 'detail',
                            text: '详情',
                            title: '注单详情',
                            classname: 'btn btn-xs btn-info btn-dialog',
                            icon: 'fa fa-eye',
                            url: 'fanshub/ogbet/detail'
                        }],
                        formatter: Table.api.formatter.operate
                    }
                ]]
            });
            Table.api.bindevent(table);

            $(document).on('click', '.btn-syncnow', function () {
                var loadIdx = Layer.load(1);
                Backend.api.ajax({
                    url: 'fanshub/ogbet/syncnow',
                    data: {max_pages: 3}
                }, function () {
                    Layer.close(loadIdx);
                    table.bootstrapTable('refresh');
                    return true;
                }, function () {
                    Layer.close(loadIdx);
                });
            });

            $(document).on('click', '.btn-relink', function () {
                var loadIdx = Layer.load(1);
                Backend.api.ajax({
                    url: 'fanshub/ogbet/relink',
                    data: {}
                }, function () {
                    Layer.close(loadIdx);
                    table.bootstrapTable('refresh');
                    return true;
                }, function () {
                    Layer.close(loadIdx);
                });
            });
        },
        detail: function () {}
    };
    return Controller;
});
