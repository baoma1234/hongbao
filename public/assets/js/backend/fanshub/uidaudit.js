define(['jquery', 'bootstrap', 'backend', 'table', 'form', './common'], function ($, undefined, Backend, Table, Form, FanshubCommon) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/uidaudit/index',
                    export_url: 'fanshub/uidaudit/export',
                    table: 'fans_account',
                }
            });
            var table = $("#table");
            var uidAuditList = $.extend({}, Config.uidAuditList || {
                "pending": "待核销",
                "approved": "已通过",
                "rejected": "已拒绝"
            });
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'updatetime',
                sortOrder: 'desc',
                queryParams: function (params) {
                    var filter = {};
                    try {
                        filter = params.filter ? JSON.parse(params.filter) : {};
                    } catch (e) {
                        filter = {};
                    }
                    if (filter.main_uid_audit === undefined) {
                        filter.main_uid_audit = 'pending';
                        params.filter = JSON.stringify(filter);
                        var op = {};
                        try {
                            op = params.op ? JSON.parse(params.op) : {};
                        } catch (e2) {
                            op = {};
                        }
                        op.main_uid_audit = '=';
                        params.op = JSON.stringify(op);
                    }
                    return params;
                },
                columns: [[
                    {checkbox: true},
                    {field: 'id', title: 'ID', operate: false},
                    {field: 'user_id', title: '会员ID'},
                    {field: 'user.mobile', title: '手机号', operate: 'LIKE'},
                    {
                        field: 'main_uid_pending',
                        title: '待审账号',
                        operate: 'LIKE',
                        formatter: function (value, row) {
                            if (row.main_uid_audit === 'pending' && value) {
                                return '<span class="text-danger" style="font-weight:bold;font-size:14px;">' + value + '</span>';
                            }
                            return value || '-';
                        }
                    },
                    {field: 'main_uid', title: '已通过账号', operate: 'LIKE'},
                    {
                        field: 'main_uid_audit',
                        title: '审核状态',
                        searchList: uidAuditList,
                        defaultValue: 'pending',
                        formatter: function (value) {
                            var key = String(value === undefined || value === null ? '' : value);
                            var text = uidAuditList[key] || key || '-';
                            var cls = key === 'pending' ? 'label-danger' : (key === 'approved' ? 'label-success' : (key === 'rejected' ? 'label-warning' : 'label-default'));
                            return '<span class="label ' + cls + '">' + text + '</span>';
                        }
                    },
                    {field: 'main_uid_reject_reason', title: '拒绝原因', operate: 'LIKE', formatter: function (v) { return v || '-'; }},
                    {field: 'updatetime', title: '更新时间', operate: 'RANGE', addclass: 'datetimerange', formatter: Table.api.formatter.datetime},
                    {
                        field: 'operate', title: '操作', table: table, operate: false,
                        buttons: [{
                            name: 'approve',
                            text: '通过核销',
                            title: '通过核销（需 SugarCRM 验证）',
                            classname: 'btn btn-xs btn-success btn-ajax',
                            icon: 'fa fa-check',
                            url: 'fanshub/uidaudit/approve',
                            confirm: '确认核销通过该游戏账号？将请求 SugarCRM 校验手机号已验证。',
                            visible: function (row) {
                                return row.main_uid_audit === 'pending' && !!row.main_uid_pending;
                            },
                            success: function () { table.bootstrapTable('refresh'); }
                        }, {
                            name: 'forceapprove',
                            text: '强制通过',
                            title: '强制通过（不走接口）',
                            classname: 'btn btn-xs btn-warning btn-ajax',
                            icon: 'fa fa-bolt',
                            url: 'fanshub/uidaudit/forceapprove',
                            confirm: '确认强制核销通过？将跳过 SugarCRM 接口，请确保已人工核实游戏账号无误。',
                            visible: function (row) {
                                return row.main_uid_audit === 'pending' && !!row.main_uid_pending;
                            },
                            success: function () { table.bootstrapTable('refresh'); }
                        }, {
                            name: 'reject',
                            text: '拒绝',
                            title: '拒绝',
                            classname: 'btn btn-xs btn-danger btn-ajax',
                            icon: 'fa fa-ban',
                            url: 'fanshub/uidaudit/reject',
                            confirm: '确认拒绝？用户需重新提交。',
                            visible: function (row) {
                                return row.main_uid_audit === 'pending' && !!row.main_uid_pending;
                            },
                            success: function () { table.bootstrapTable('refresh'); }
                        }],
                        events: Table.api.events.operate,
                        formatter: Table.api.formatter.operate
                    }
                ]]
            });
            Table.api.bindevent(table);
            FanshubCommon.bindExport(table, $.fn.bootstrapTable.defaults.extend.export_url);
        },
        api: {
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
            }
        }
    };
    return Controller;
});
