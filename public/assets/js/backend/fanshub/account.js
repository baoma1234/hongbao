define(['jquery', 'bootstrap', 'backend', 'table', 'form', './common'], function ($, undefined, Backend, Table, Form, FanshubCommon) {
    var Controller = {
        index: function () {
            if (!$('#fanshub-account-promote-style').length) {
                $('<style id="fanshub-account-promote-style">')
                    .text('.btn-promote-master{background-color:#6a62cb!important;border-color:#6a62cb!important;color:#fff!important;}.btn-promote-master:hover,.btn-promote-master:focus{background-color:#5a52b8!important;border-color:#5a52b8!important;color:#fff!important;}')
                    .appendTo('head');
            }
            Table.api.init({
                extend: {
                    index_url: 'fanshub/account/index',
                    edit_url: 'fanshub/account/edit',
                    export_url: 'fanshub/account/export',
                    table: 'fans_account',
                }
            });
            var table = $("#table");
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                columns: [[
                    {checkbox: true},
                    {field: 'id', title: '会员ID'},
                    {field: 'user.mobile', title: '手机号', operate: 'LIKE'},
                    {field: 'inviter_user_id', title: '上线ID', operate: false, formatter: function (value) {
                        return value ? value : '-';
                    }},
                    {field: 'inviter_mobile', title: '上线手机', operate: false, formatter: function (value) {
                        return value ? value : '-';
                    }},
                    {field: 'rights', title: '股份', operate: 'BETWEEN'},
                    {field: 'balance', title: '余额', operate: 'BETWEEN'},
                    {field: 'main_uid', title: '主站账号', operate: 'LIKE'},
                    {field: 'member_level', title: 'VIP等级', searchList: $.extend({}, Config.memberLevelList || {}), formatter: function (value, row) {
                        var map = Config.memberLevelList || {};
                        var key = String(value === undefined || value === null ? '' : value);
                        var name = map[key];
                        if (!name) {
                            return key === '' ? '-' : ('VIP' + key);
                        }
                        return '<span class="label label-warning">VIP' + key + '</span> ' + name;
                    }},
                    {field: 'flow_stage', title: '阶段', searchList: {"stage1": "阶段一", "stage2": "阶段二"}, formatter: Table.api.formatter.normal},
                    {field: 'user_mode', title: '用户态', searchList: {"newbie": "新手", "master": "团长"}, formatter: Table.api.formatter.normal},
                    {field: 'sub_withdrawn_count', title: '下线提现', operate: 'BETWEEN'},
                    {field: 'honor_tier_claimed', title: '荣誉段位', operate: 'BETWEEN'},
                    {field: 'fission_streak_days', title: '连签天', operate: 'BETWEEN'},
                    {field: 'status', title: '状态', searchList: {"normal": "正常", "frozen": "冻结"}, formatter: Table.api.formatter.status},
                    {field: 'chat_forbid', title: '聊天禁言', operate: false, formatter: function (value) {
                        if (!value) return '<span class="text-muted">-</span>';
                        var map = {text:'文字', image:'图片', sticker:'表情', video:'视频', file:'文件', rp_send:'发红包', rp_grab:'领红包'};
                        var obj = null;
                        try { obj = typeof value === 'object' ? value : JSON.parse(value); } catch (e) { return '-'; }
                        if (!obj) return '-';
                        var tags = [];
                        Object.keys(map).forEach(function (k) {
                            if (obj[k]) tags.push(map[k]);
                        });
                        if (!tags.length) return '<span class="text-muted">-</span>';
                        return '<span class="label label-danger" title="' + tags.join('、') + '">禁' + tags.length + '项</span>';
                    }},
                    {field: 'updatetime', title: '更新时间', operate: 'RANGE', addclass: 'datetimerange', formatter: Table.api.formatter.datetime},
                    {
                        field: 'operate', title: '操作', table: table,
                        buttons: [{
                            name: 'detail',
                            text: '详情',
                            title: '用户详情',
                            classname: 'btn btn-xs btn-info btn-dialog',
                            icon: 'fa fa-eye',
                            url: 'fanshub/account/detail'
                        }, {
                            name: 'adjust',
                            text: '调账',
                            title: '人工调账',
                            classname: 'btn btn-xs btn-warning btn-dialog',
                            icon: 'fa fa-calculator',
                            url: 'fanshub/account/adjust'
                        }, {
                            name: 'chatforbid',
                            text: '禁言',
                            title: '聊天禁言',
                            classname: 'btn btn-xs btn-danger btn-dialog',
                            icon: 'fa fa-ban',
                            url: 'fanshub/account/chatforbid',
                            visible: function () {
                                return true;
                            }
                        }, {
                            name: 'promotemaster',
                            text: '晋升团长',
                            title: '晋升团长',
                            classname: 'btn btn-xs btn-promote-master btn-ajax',
                            icon: 'fa fa-trophy',
                            url: 'fanshub/account/promotemaster',
                            confirm: '确认将该用户晋升为团长？\n用户态 → 团长\n荣誉段位 → 青铜团长',
                            visible: function (row) {
                                return !(row.user_mode === 'master' && parseInt(row.honor_tier_claimed, 10) >= 1);
                            },
                            success: function () {
                                table.bootstrapTable('refresh');
                            }
                        }],
                        events: Table.api.events.operate,
                        formatter: Table.api.formatter.operate
                    }
                ]]
            });
            Table.api.bindevent(table);
            FanshubCommon.bindExport(table, $.fn.bootstrapTable.defaults.extend.export_url);
        },
        edit: function () {
            Controller.api.bindevent();
        },
        adjust: function () {
            Form.api.bindevent($("#adjust-form"));
        },
        chatforbid: function () {
            Form.api.bindevent($("#chatforbid-form"));
        },
        detail: function () {},
        api: {
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
            }
        }
    };
    return Controller;
});
