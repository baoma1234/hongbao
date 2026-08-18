define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/yxxround/index' + location.search,
                    table: 'fans_yxx_rounds'
                }
            });
            var table = $("#table");
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                sortOrder: 'desc',
                columns: [[
                    {field: 'id', title: 'ID', sortable: true},
                    {field: 'round_index', title: '期号', sortable: true},
                    {field: 'settle_face', title: '结算门'},
                    {field: 'human_stake', title: '真人下注', sortable: true},
                    {field: 'pool_inject', title: '注入奖池'},
                    {field: 'boom_release', title: '爆点释放'},
                    {field: 'gross_pool_after', title: '池子余额'},
                    {field: 'cycle_count', title: '有效局'},
                    {field: 'hash_seed', title: '种子', formatter: function (v) {
                        v = String(v || '');
                        return v ? (v.substr(0, 12) + '…') : '-';
                    }, operate: 'LIKE'},
                    {field: 'createtime', title: '时间', formatter: Table.api.formatter.datetime, operate: 'RANGE', addclass: 'datetimerange', sortable: true}
                ]]
            });
            Table.api.bindevent(table);
        }
    };
    return Controller;
});
