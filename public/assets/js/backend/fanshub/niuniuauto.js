define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/niuniuauto/index',
                    add_url: 'fanshub/niuniuauto/add',
                    edit_url: 'fanshub/niuniuauto/edit',
                    del_url: 'fanshub/niuniuauto/del',
                    multi_url: 'fanshub/niuniuauto/multi',
                    table: 'chat_niuniu_auto_task',
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
                    {field: 'actor_mode', title: '模式', searchList: {"1":"UID池","2":"机器人"}, formatter: Table.api.formatter.normal},
                    {field: 'buyer_count_min', title: '人数min'},
                    {field: 'buyer_count_max', title: '人数max'},
                    {field: 'shares_min', title: '份数min'},
                    {field: 'shares_max', title: '份数max'},
                    {field: 'auto_buy', title: '自动购', formatter: function (v) { return parseInt(v, 10) === 1 ? '是' : '否'; }},
                    {field: 'auto_claim', title: '自动领', formatter: function (v) { return parseInt(v, 10) === 1 ? '是' : '否'; }},
                    {field: 'last_round_id', title: '最近局'},
                    {field: 'last_error', title: '错误', operate: false},
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
                    url: 'fanshub/niuniuauto/runonce',
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
                        url: 'fanshub/niuniuauto/restartim',
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
