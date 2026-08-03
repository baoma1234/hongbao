define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/redpacket/index',
                    table: 'chat_red_packets'
                }
            });
            var table = $('#table');
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                columns: [[
                    {field: 'id', title: 'ID', sortable: true},
                    {field: 'packet_no', title: '单号', operate: 'LIKE'},
                    {
                        field: 'packet_type', title: '类型',
                        searchList: {1: '普通红包', 2: '拼手气', 3: '埋雷包', 4: '随机红包'},
                        formatter: function (v, row) { return row.type_text || v; }
                    },
                    {
                        field: 'status', title: '状态',
                        searchList: {1: '进行中', 2: '已抢完', 3: '已过期', 4: '已关闭', 5: '已结算'},
                        formatter: function (v, row) { return row.status_text || v; }
                    },
                    {field: 'from_user_id', title: '发包人ID'},
                    {field: 'from_label', title: '发包人', operate: false},
                    {field: 'group_id', title: '群ID'},
                    {field: 'total_amount', title: '总额', sortable: true},
                    {field: 'grabbed', title: '已抢', operate: false, formatter: function (v, row) {
                        return (row.grabbed || 0) + '/' + row.total_count;
                    }},
                    {field: 'mine_digit', title: '雷号', operate: false},
                    {field: 'platform_fee', title: '抽水', operate: false},
                    {field: 'agent_rebate_amount', title: '返点', operate: false},
                    {
                        field: 'createtime', title: '时间', operate: 'RANGE',
                        addclass: 'datetimerange', formatter: Table.api.formatter.datetime, sortable: true
                    },
                    {
                        field: 'operate', title: __('Operate'), table: table, events: Table.api.events.operate,
                        buttons: [
                            {
                                name: 'detail', text: '详情', title: '红包详情',
                                classname: 'btn btn-xs btn-info btn-dialog', icon: 'fa fa-list',
                                url: 'fanshub/redpacket/detail',
                                extend: 'data-area=\'["92%","92%"]\''
                            },
                            {
                                name: 'retrysettle', text: '重试结算', title: '重试结算',
                                classname: 'btn btn-xs btn-warning btn-ajax', icon: 'fa fa-refresh',
                                url: 'fanshub/redpacket/retrysettle',
                                confirm: '确认对该红包触发结算？',
                                visible: function (row) { return row.status == 2 || row.compensate_status == 3; },
                                success: function () { table.bootstrapTable('refresh'); return true; }
                            },
                            {
                                name: 'refundnow', text: '退回', title: '过期退回',
                                classname: 'btn btn-xs btn-primary btn-ajax', icon: 'fa fa-undo',
                                url: 'fanshub/redpacket/refundnow',
                                confirm: '确认将剩余金额退回发包方？',
                                visible: function (row) { return row.status == 1; },
                                success: function () { table.bootstrapTable('refresh'); return true; }
                            },
                            {
                                name: 'forceclose', text: '关包', title: '强制关包',
                                classname: 'btn btn-xs btn-danger btn-ajax', icon: 'fa fa-ban',
                                url: 'fanshub/redpacket/forceclose',
                                confirm: '强制关包不会自动退款，确认？',
                                visible: function (row) { return row.status == 1; },
                                success: function () { table.bootstrapTable('refresh'); return true; }
                            }
                        ],
                        formatter: Table.api.formatter.operate
                    }
                ]]
            });
            Table.api.bindevent(table);
        },
        detail: function () {
            $('.btn-retrysettle').on('click', function () {
                var id = $(this).data('id');
                Layer.confirm('确认重试结算？', function (idx) {
                    Backend.api.ajax({url: 'fanshub/redpacket/retrysettle', data: {ids: id}}, function () {
                        Layer.close(idx);
                        location.reload();
                        return false;
                    });
                });
            });
            $('.btn-refundnow').on('click', function () {
                var id = $(this).data('id');
                Layer.confirm('确认过期退回剩余金额？', function (idx) {
                    Backend.api.ajax({url: 'fanshub/redpacket/refundnow', data: {ids: id}}, function () {
                        Layer.close(idx);
                        location.reload();
                        return false;
                    });
                });
            });
            $('.btn-forceclose').on('click', function () {
                var id = $(this).data('id');
                Layer.confirm('强制关包不退款，确认？', function (idx) {
                    Backend.api.ajax({url: 'fanshub/redpacket/forceclose', data: {ids: id}}, function () {
                        Layer.close(idx);
                        location.reload();
                        return false;
                    });
                });
            });
        }
    };
    return Controller;
});
