define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/rechargeorder/index',
                    table: 'fans_recharge_order',
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
                    {field: 'handler', title: '处理器', operate: 'LIKE'},
                    {
                        field: 'status',
                        title: '状态',
                        searchList: {"pending": "待支付", "paid": "已到账", "failed": "失败", "cancelled": "已取消"},
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
                            'click .btn-markpaid': function (e, value, row) {
                                e.stopPropagation();
                                Layer.confirm('确认将该充值单标记为已到账并入账？', function (index) {
                                    Backend.api.ajax({
                                        url: 'fanshub/rechargeorder/markpaid',
                                        data: {ids: row.id}
                                    }, function () {
                                        Layer.close(index);
                                        table.bootstrapTable('refresh');
                                        return false;
                                    });
                                });
                            },
                            'click .btn-markfailed': function (e, value, row) {
                                e.stopPropagation();
                                Layer.prompt({title: '作废原因（可选）', formType: 2}, function (remark, index) {
                                    Backend.api.ajax({
                                        url: 'fanshub/rechargeorder/markfailed',
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
                                    url: 'fanshub/rechargeorder/querygateway',
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
                            if (row.status === 'pending' || row.status === 'failed') {
                                html.push('<a href="javascript:;" class="btn btn-xs btn-success btn-markpaid" title="确认到账"><i class="fa fa-check"></i> 到账</a>');
                            }
                            if (row.status === 'pending') {
                                html.push('<a href="javascript:;" class="btn btn-xs btn-danger btn-markfailed" title="作废"><i class="fa fa-times"></i> 作废</a>');
                            }
                            if (row.handler === 'bs' && row.status === 'pending') {
                                html.push('<a href="javascript:;" class="btn btn-xs btn-info btn-bs-query" title="BS查单"><i class="fa fa-search"></i> BS查单</a>');
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
