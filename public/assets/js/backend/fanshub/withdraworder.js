define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/withdraworder/index',
                    table: 'fans_withdraw_order',
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
                    {field: 'order_no', title: '订单号', operate: 'LIKE'},
                    {field: 'user_id', title: '会员ID', sortable: true},
                    {field: 'user.mobile', title: '手机号', operate: 'LIKE'},
                    {field: 'channel.name', title: '通道', operate: false},
                    {field: 'amount', title: '金额', sortable: true},
                    {field: 'turnover_snapshot', title: '当时流水', operate: false},
                    {field: 'account_info_text', title: '收款信息', operate: false},
                    {field: 'handler', title: '处理器', operate: 'LIKE'},
                    {
                        field: 'status',
                        title: '状态',
                        searchList: {"pending": "待审核", "processing": "已通过待打款", "paid": "已打款", "rejected": "已拒绝", "cancelled": "已取消"},
                        formatter: Table.api.formatter.status
                    },
                    {field: 'remark', title: '备注', operate: 'LIKE'},
                    {field: 'createtime', title: '创建时间', operate: 'RANGE', addclass: 'datetimerange', formatter: Table.api.formatter.datetime, sortable: true},
                    {field: 'updatetime', title: '更新时间', operate: 'RANGE', addclass: 'datetimerange', formatter: Table.api.formatter.datetime},
                    {
                        field: 'operate',
                        title: __('Operate'),
                        table: table,
                        events: {
                            'click .btn-ledger': function (e, value, row) {
                                e.stopPropagation();
                                var uid = parseInt(row.user_id, 10) || 0;
                                if (uid <= 0) {
                                    Layer.msg('无效会员ID');
                                    return;
                                }
                                Fast.api.open(
                                    'fanshub/ledger?user_id=' + uid,
                                    '资金流水 · 会员' + uid,
                                    {area: ['92%', '90%']}
                                );
                            },
                            'click .btn-approve': function (e, value, row) {
                                e.stopPropagation();
                                Layer.confirm('确认审核通过该提现？通过后可打款。', function (index) {
                                    Backend.api.ajax({
                                        url: 'fanshub/withdraworder/approve',
                                        data: {ids: row.id}
                                    }, function () {
                                        Layer.close(index);
                                        table.bootstrapTable('refresh');
                                        return false;
                                    });
                                });
                            },
                            'click .btn-markpaid': function (e, value, row) {
                                e.stopPropagation();
                                var gateway = !!row.payout_gateway;
                                var tip = gateway
                                    ? '将向代付通道提交出款，提交后等待通道回调到账（不会立刻标记已打款）。确定提交？'
                                    : '该通道无代付接口，确认已线下打款完成？';
                                Layer.confirm(tip, function (confirmIndex) {
                                    Layer.close(confirmIndex);
                                    Layer.prompt({
                                        title: '请输入谷歌验证码（6位）',
                                        formType: 0,
                                        maxlength: 6
                                    }, function (googleCode, promptIndex) {
                                        googleCode = String(googleCode || '').replace(/\s+/g, '');
                                        if (!/^\d{6}$/.test(googleCode)) {
                                            Layer.msg('请输入6位谷歌验证码', {icon: 2});
                                            return false;
                                        }
                                        Backend.api.ajax({
                                            url: 'fanshub/withdraworder/markpaid',
                                            data: {ids: row.id, google_code: googleCode}
                                        }, function (data, ret) {
                                            Layer.close(promptIndex);
                                            Layer.msg((ret && ret.msg) || '已提交', {icon: 1});
                                            table.bootstrapTable('refresh');
                                            return false;
                                        });
                                    });
                                });
                            },
                            'click .btn-reject': function (e, value, row) {
                                e.stopPropagation();
                                Layer.prompt({title: '拒绝原因（可选，将退回余额）', formType: 2}, function (remark, index) {
                                    Backend.api.ajax({
                                        url: 'fanshub/withdraworder/reject',
                                        data: {ids: row.id, remark: remark || ''}
                                    }, function () {
                                        Layer.close(index);
                                        table.bootstrapTable('refresh');
                                        return false;
                                    });
                                });
                            },
                            'click .btn-bs-query': function (e, value, row) {
                                e.stopPropagation();
                                Backend.api.ajax({
                                    url: 'fanshub/withdraworder/querygateway',
                                    data: {ids: row.id}
                                }, function (data, ret) {
                                    Layer.msg(ret.msg || '查单完成', {icon: 1});
                                    table.bootstrapTable('refresh');
                                    return false;
                                });
                            }
                        },
                        formatter: function (value, row) {
                            var html = [];
                            html.push('<a href="javascript:;" class="btn btn-xs btn-primary btn-ledger" title="查看资金流水"><i class="fa fa-list"></i> 流水</a>');
                            if (row.status === 'pending') {
                                html.push('<a href="javascript:;" class="btn btn-xs btn-warning btn-approve" title="审核通过"><i class="fa fa-gavel"></i> 审核通过</a>');
                                html.push('<a href="javascript:;" class="btn btn-xs btn-danger btn-reject" title="拒绝退回"><i class="fa fa-times"></i> 拒绝</a>');
                            } else if (row.status === 'processing') {
                                if (!row.payout_submitted) {
                                    html.push('<a href="javascript:;" class="btn btn-xs btn-success btn-markpaid" title="提交打款"><i class="fa fa-check"></i> 打款</a>');
                                }
                                html.push('<a href="javascript:;" class="btn btn-xs btn-danger btn-reject" title="拒绝退回"><i class="fa fa-times"></i> 拒绝</a>');
                                if (row.handler === 'bs' || row.handler === 'wanhuitong') {
                                    html.push('<a href="javascript:;" class="btn btn-xs btn-info btn-bs-query" title="查单"><i class="fa fa-search"></i> 查单</a>');
                                }
                            }
                            return html.join(' ');
                        }
                    }
                ]]
            });
            Table.api.bindevent(table);
        }
    };
    return Controller;
});
