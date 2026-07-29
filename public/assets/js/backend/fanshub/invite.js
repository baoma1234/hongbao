define(['jquery', 'bootstrap', 'backend', 'table', './common'], function ($, undefined, Backend, Table, FanshubCommon) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/invite/index',
                    export_url: 'fanshub/invite/export',
                    table: 'fans_invite',
                }
            });
            var table = $("#table");
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                columns: [[
                    {field: 'id', title: 'ID'},
                    {field: 'inviter_user_id', title: '邀请人ID'},
                    {field: 'inviter.mobile', title: '邀请人手机', operate: 'LIKE'},
                    {field: 'invitee_user_id', title: '被邀请人ID'},
                    {field: 'invitee.mobile', title: '被邀请人手机', operate: 'LIKE'},
                    {field: 'invitee_ip', title: '被邀请IP', operate: 'LIKE'},
                    {field: 'inviter_ip', title: '邀请人IP', operate: 'LIKE'},
                    {field: 'createtime', title: '时间', operate: 'RANGE', addclass: 'datetimerange', formatter: Table.api.formatter.datetime}
                ]]
            });
            Table.api.bindevent(table);
            FanshubCommon.bindExport(table, $.fn.bootstrapTable.defaults.extend.export_url);
        },
        leaderboard: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/invite/leaderboard',
                    table: 'fans_invite',
                }
            });
            var table = $("#table");
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'user_id',
                sortName: 'rank',
                pagination: false,
                search: false,
                commonSearch: false,
                columns: [[
                    {field: 'rank', title: '排名'},
                    {field: 'user_id', title: '用户ID'},
                    {field: 'mobile_mask', title: '手机号'},
                    {field: 'invite_count', title: '邀请人数'}
                ]]
            });
            Table.api.bindevent(table);
        }
    };
    return Controller;
});
