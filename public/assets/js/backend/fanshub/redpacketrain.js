define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/redpacketrain/index',
                    add_url: 'fanshub/redpacketrain/add',
                    edit_url: 'fanshub/redpacketrain/edit',
                    del_url: 'fanshub/redpacketrain/del',
                    multi_url: 'fanshub/redpacketrain/multi',
                    table: 'chat_rp_rain_task',
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
                    {field: 'send_user_ids', title: '发包UID', operate: 'LIKE'},
                    {field: 'packet_type', title: '类型', searchList: Config.packetTypeList, formatter: Table.api.formatter.normal},
                    {field: 'amount_min', title: '金额最小'},
                    {field: 'amount_max', title: '金额最大'},
                    {field: 'total_count', title: '每包份数'},
                    {field: 'time_slots', title: '开启时间', operate: false, formatter: function (v, row) {
                        var mode = parseInt(row.schedule_mode, 10) || 1;
                        if (mode === 2) {
                            var m = parseInt(row.interval_minutes, 10) || 0;
                            var c = parseInt(row.interval_count, 10) || 0;
                            return '模式2：每' + m + '分钟×' + c + '包';
                        }
                        var arr = [];
                        try { arr = typeof v === 'string' ? JSON.parse(v || '[]') : (v || []); } catch (e) { arr = []; }
                        if (!arr || !arr.length) return '模式1：-';
                        return '模式1：' + arr.map(function (s) {
                            return (s.time || '') + '×' + (s.count || 0);
                        }).join('；');
                    }},
                    {field: 'round_sent', title: '本轮进度', operate: false, formatter: function (v, row) {
                        var s = parseInt(v, 10) || 0;
                        var t = parseInt(row.round_target, 10) || 0;
                        return t > 0 ? (s + '/' + t) : '-';
                    }},
                    {field: 'bot_grab_cap', title: '每人上限'},
                    {field: 'sweep_minutes', title: '超时分钟'},
                    {field: 'auto_send', title: '自动发', formatter: function (v) { return parseInt(v, 10) === 1 ? '是' : '否'; }},
                    {field: 'auto_grab', title: '自动抢', formatter: function (v) { return parseInt(v, 10) === 1 ? '是' : '否'; }},
                    {field: 'actor_mode', title: '抢包模式', searchList: {"1":"UID池","2":"机器人抢"}, formatter: Table.api.formatter.normal},
                    {field: 'last_slot_key', title: '最近轮次', operate: 'LIKE', formatter: function (v) {
                        var s = (v == null || v === '') ? '' : String(v);
                        if (!s) return '-';
                        // 兼容旧标记 force → 手动
                        if (s.indexOf('force ') === 0) return '手动 ' + s.slice(6);
                        return s;
                    }},
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
                    url: 'fanshub/redpacketrain/runonce',
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
                    }, function () {
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
                var syncSchedule = function () {
                    var mode = $('input[name="row[schedule_mode]"]:checked').val() || '1';
                    $('.rain-schedule-panel').each(function () {
                        $(this).toggle(String($(this).data('mode')) === String(mode));
                    });
                };
                $(document).off('change.rainSchedule', '.rain-schedule-mode').on('change.rainSchedule', '.rain-schedule-mode', syncSchedule);
                syncSchedule();
            }
        }
    };
    return Controller;
});
