define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/push/index',
                    del_url: 'fanshub/push/del',
                    table: 'chat_push_logs'
                }
            });
            var table = $('#table');
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                sortOrder: 'desc',
                columns: [[
                    {checkbox: true},
                    {field: 'id', title: 'ID'},
                    {field: 'admin_id', title: '管理员', operate: '='},
                    {field: 'scene', title: '场景', operate: 'LIKE'},
                    {field: 'title', title: '标题', operate: 'LIKE'},
                    {field: 'content', title: '内容', operate: 'LIKE', formatter: Table.api.formatter.content},
                    {field: 'target_type', title: '目标类型', operate: 'LIKE'},
                    {field: 'platform', title: '平台'},
                    {field: 'msg_id', title: '极光msg_id', operate: 'LIKE'},
                    {
                        field: 'status', title: '状态',
                        searchList: {ok: '成功', fail: '失败'},
                        formatter: Table.api.formatter.status
                    },
                    {
                        field: 'createtime', title: '时间', operate: 'RANGE', addclass: 'datetimerange',
                        formatter: Table.api.formatter.datetime
                    },
                    {field: 'operate', title: __('Operate'), table: table, events: Table.api.events.operate, formatter: Table.api.formatter.operate}
                ]]
            });
            Table.api.bindevent(table);

            function syncMode() {
                var mode = $('#push-send-form input[name=mode]:checked').val();
                if (mode === 'batch') {
                    $('#row-user-id').addClass('hide');
                    $('#row-user-ids').removeClass('hide');
                } else if (mode === 'all') {
                    $('#row-user-id').addClass('hide');
                    $('#row-user-ids').addClass('hide');
                } else {
                    $('#row-user-id').removeClass('hide');
                    $('#row-user-ids').addClass('hide');
                }
            }
            $('#push-send-form input[name=mode]').on('change', syncMode);
            syncMode();

            $('#push-send-form').on('submit', function (e) {
                e.preventDefault();
                var $btn = $('#btn-push-send').prop('disabled', true);
                $.ajax({
                    url: 'fanshub/push/send',
                    type: 'POST',
                    dataType: 'json',
                    data: $(this).serialize(),
                    success: function (ret) {
                        if (ret.code === 1) {
                            Toastr.success(ret.msg || '已发送');
                            table.bootstrapTable('refresh');
                        } else {
                            Toastr.error(ret.msg || '失败');
                        }
                    },
                    error: function () {
                        Toastr.error('请求失败');
                    },
                    complete: function () {
                        $btn.prop('disabled', false);
                    }
                });
                return false;
            });
        }
    };
    return Controller;
});
