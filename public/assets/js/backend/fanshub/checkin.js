define(['jquery', 'bootstrap', 'backend', 'table', './common'], function ($, undefined, Backend, Table, FanshubCommon) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/checkin/index',
                    export_url: 'fanshub/checkin/export',
                    table: 'fans_checkin',
                }
            });
            var table = $("#table");
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                columns: [[
                    {field: 'id', title: 'ID'},
                    {field: 'user_id', title: '会员ID'},
                    {field: 'user.mobile', title: '手机号', operate: 'LIKE'},
                    {field: 'checkin_date', title: '签到日', operate: 'RANGE', addclass: 'datetimerange'},
                    {field: 'mode', title: '模式', searchList: {"normal": "普通打卡", "violent": "暴力分享"}, formatter: Table.api.formatter.normal},
                    {field: 'base_amount', title: '基础(元)'},
                    {field: 'bonus_amount', title: '暴击(元)'},
                    {field: 'bonus_unlocked', title: '暴击解锁', searchList: {"0": "待解锁", "1": "已解锁"}, formatter: function (v) {
                        return parseInt(v, 10) === 1 ? '<span class="label label-success">已解锁</span>' : '<span class="label label-warning">待解锁</span>';
                    }},
                    {field: 'streak_day', title: '连续天'},
                    {field: 'day7_settled', title: '第7天结算', searchList: {"0": "否", "1": "是"}, formatter: Table.api.formatter.normal},
                    {field: 'createtime', title: '记录时间', operate: 'RANGE', addclass: 'datetimerange', formatter: Table.api.formatter.datetime}
                ]]
            });
            Table.api.bindevent(table);
            FanshubCommon.bindExport(table, $.fn.bootstrapTable.defaults.extend.export_url);
        }
    };
    return Controller;
});
