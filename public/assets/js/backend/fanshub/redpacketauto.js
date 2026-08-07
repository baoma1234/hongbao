define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/redpacketauto/index',
                    add_url: 'fanshub/redpacketauto/add',
                    edit_url: 'fanshub/redpacketauto/edit',
                    del_url: 'fanshub/redpacketauto/del',
                    multi_url: 'fanshub/redpacketauto/multi',
                    table: 'chat_rp_auto_task',
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
                    {field: 'id', title: 'ID'},
                    {field: 'name', title: '任务名', operate: 'LIKE'},
                    {field: 'group_id', title: '群ID'},
                    {field: 'send_user_id', title: '发包UID'},
                    {field: 'send_user_ids', title: '发包UID池', operate: 'LIKE'},
                    {field: 'packet_type', title: '类型', searchList: Config.packetTypeList, formatter: Table.api.formatter.normal},
                    {field: 'amount_min', title: '金额最小'},
                    {field: 'amount_max', title: '金额最大'},
                    {field: 'total_count', title: '个数'},
                    {field: 'interval_sec', title: '间隔秒'},
                    {field: 'burst_count', title: '窗内包数'},
                    {field: 'burst_window_sec', title: '窗长秒'},
                    {field: 'auto_send', title: '自动发', formatter: function (v) { return parseInt(v, 10) === 1 ? '是' : '否'; }},
                    {field: 'auto_grab', title: '自动抢', formatter: function (v) { return parseInt(v, 10) === 1 ? '是' : '否'; }},
                    {field: 'grab_user_ids', title: '抢包UID', operate: 'LIKE'},
                    {field: 'today_count', title: '今日发包'},
                    {field: 'last_packet_id', title: '最近包ID'},
                    {field: 'last_error', title: '最近错误', operate: false},
                    {field: 'status', title: '状态', searchList: Config.statusList, formatter: Table.api.formatter.status},
                    {field: 'updatetime', title: '更新', operate: 'RANGE', addclass: 'datetimerange', formatter: Table.api.formatter.datetime},
                    {field: 'operate', title: __('Operate'), table: table, events: Table.api.events.operate, formatter: Table.api.formatter.operate}
                ]]
            });
            Table.api.bindevent(table);

            $(document).on('click', '.btn-runonce', function () {
                var ids = Table.api.selectedids(table);
                if (!ids.length) {
                    Layer.msg('请先勾选任务');
                    return;
                }
                Backend.api.ajax({
                    url: 'fanshub/redpacketauto/runonce',
                    data: {ids: ids.join(',')}
                }, function () {
                    table.bootstrapTable('refresh');
                    return true;
                });
            });

            $(document).on('click', '.btn-restartim', function () {
                Layer.confirm('确认重启聊天服务？（会短暂断线约数秒）', function (index) {
                    Layer.close(index);
                    var loadIdx = Layer.load(1);
                    Backend.api.ajax({
                        url: 'fanshub/redpacketauto/restartim',
                        data: {}
                    }, function (data, ret) {
                        Layer.close(loadIdx);
                        table.bootstrapTable('refresh');
                        return true;
                    }, function () {
                        Layer.close(loadIdx);
                    });
                });
            });
        },
        add: function () {
            Controller.api.bindevent();
        },
        edit: function () {
            Controller.api.bindevent();
        },
        api: {
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
            }
        }
    };
    return Controller;
});
