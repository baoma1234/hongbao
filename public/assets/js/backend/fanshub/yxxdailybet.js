define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/yxxdailybet/index' + location.search,
                    table: 'fans_yxx_daily_bet'
                }
            });
            var table = $("#table");
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'bet_total',
                sortOrder: 'desc',
                columns: [[
                    {field: 'id', title: 'ID', sortable: true},
                    {field: 'user_id', title: '用户UID', sortable: true},
                    {field: 'nickname', title: '昵称', operate: false},
                    {field: 'bet_date', title: '日期(Ymd)', visible: false},
                    {field: 'bet_date_text', title: '日期', operate: false},
                    {field: 'bet_count', title: '下注笔数', sortable: true},
                    {field: 'bet_total', title: '下注总额', sortable: true},
                    {field: 'updatetime', title: '更新时间', formatter: function (v, row) {
                        return row.time_text || Table.api.formatter.datetime(v);
                    }, operate: 'RANGE', addclass: 'datetimerange', sortable: true}
                ]]
            });
            Table.api.bindevent(table);
        }
    };
    return Controller;
});
