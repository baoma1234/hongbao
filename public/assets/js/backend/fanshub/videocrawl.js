define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/videocrawl/index',
                    add_url: 'fanshub/videocrawl/add',
                    edit_url: 'fanshub/videocrawl/edit',
                    del_url: 'fanshub/videocrawl/del',
                    multi_url: 'fanshub/videocrawl/multi',
                    table: 'chat_video_crawl_task',
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
                    {field: 'api_url', title: '采集地址', operate: 'LIKE', formatter: Table.api.formatter.url},
                    {field: 'group_id', title: '群ID'},
                    {field: 'send_user_id', title: '发送UID'},
                    {field: 'start_page', title: '起始页'},
                    {field: 'current_page', title: '下一页'},
                    {field: 'pagecount', title: '总页数'},
                    {field: 'pages_done', title: '已采页数'},
                    {field: 'sent_count', title: '已发视频'},
                    {field: 'skip_count', title: '跳过'},
                    {field: 'last_page', title: '最近页'},
                    {field: 'auto_run', title: '定时', formatter: function (v) { return parseInt(v, 10) === 1 ? '是' : '否'; }},
                    {field: 'last_error', title: '最近错误', operate: false},
                    {field: 'last_run_time', title: '最近执行', operate: 'RANGE', addclass: 'datetimerange', formatter: Table.api.formatter.datetime},
                    {field: 'status', title: '状态', searchList: Config.statusList, formatter: Table.api.formatter.status},
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
                var loadIdx = Layer.load(1);
                Backend.api.ajax({
                    url: 'fanshub/videocrawl/runonce',
                    data: {ids: ids.join(',')}
                }, function () {
                    Layer.close(loadIdx);
                    table.bootstrapTable('refresh');
                    return true;
                }, function () {
                    Layer.close(loadIdx);
                });
            });

            $(document).on('click', '.btn-resetpage', function () {
                var ids = Table.api.selectedids(table);
                if (!ids.length) {
                    Layer.msg('请先勾选任务');
                    return;
                }
                Layer.confirm('确认把倒序进度重置回起始页？（已发去重保留，不会重复发同一 vod）', function (index) {
                    Layer.close(index);
                    Backend.api.ajax({
                        url: 'fanshub/videocrawl/resetpage',
                        data: {ids: ids.join(',')}
                    }, function () {
                        table.bootstrapTable('refresh');
                        return true;
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
