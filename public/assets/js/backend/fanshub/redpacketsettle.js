define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/redpacketsettle/index',
                    table: 'chat_red_packet_settlements'
                }
            });
            var table = $('#table');
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                columns: [[
                    {field: 'id', title: 'ID', sortable: true},
                    {field: 'packet_id', title: '红包ID'},
                    {field: 'packet_no', title: '单号', operate: 'LIKE'},
                    {
                        field: 'settle_type', title: '类型',
                        searchList: {
                            compensate: '赔付',
                            platform_fee: '平台抽水',
                            agent_rebate: '代理返点',
                            refund: '过期退回'
                        },
                        formatter: function (v, row) { return row.type_text || v; }
                    },
                    {field: 'from_label', title: '出账', operate: false},
                    {field: 'to_label', title: '入账', operate: false},
                    {field: 'amount', title: '金额', sortable: true},
                    {
                        field: 'status', title: '状态',
                        searchList: {1: '成功', 2: '失败'},
                        formatter: Table.api.formatter.normal
                    },
                    {field: 'remark', title: '备注', operate: 'LIKE'},
                    {
                        field: 'createtime', title: '时间', operate: 'RANGE',
                        addclass: 'datetimerange', formatter: Table.api.formatter.datetime, sortable: true
                    },
                    {
                        field: 'operate', title: __('Operate'), table: table, events: Table.api.events.operate,
                        buttons: [{
                            name: 'packet', text: '红包', title: '查看红包',
                            classname: 'btn btn-xs btn-info btn-dialog', icon: 'fa fa-gift',
                            url: 'fanshub/redpacket/detail/ids/{packet_id}',
                            extend: 'data-area=\'["92%","92%"]\''
                        }],
                        formatter: Table.api.formatter.operate
                    }
                ]]
            });
            Table.api.bindevent(table);
            $('#btnRetryBatch').on('click', function () {
                Layer.confirm('对最多 20 个「已抢完未结算」红包重试结算？', function (idx) {
                    Backend.api.ajax({url: 'fanshub/redpacketsettle/retrybatch', data: {}}, function (data) {
                        Layer.close(idx);
                        Layer.msg((data && data.msg) || '完成');
                        table.bootstrapTable('refresh');
                        setTimeout(function () { location.reload(); }, 800);
                        return false;
                    });
                });
            });
        }
    };
    return Controller;
});
