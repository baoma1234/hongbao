define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/yxxgroup/index' + location.search,
                    table: 'fans_yxx_group_state'
                }
            });
            var table = $("#table");
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'group_id',
                sortName: 'updatetime',
                sortOrder: 'desc',
                columns: [[
                    {field: 'group_id', title: '群ID', sortable: true},
                    {field: 'group_name', title: '群名称', operate: 'LIKE'},
                    {field: 'owner_user_id', title: '群主UID', sortable: true},
                    {field: 'owner_nickname', title: '群主昵称', operate: false},
                    {field: 'is_open', title: '状态', searchList: {'1': '开桌中', '0': '已关桌'}, formatter: function (v, row) {
                        return row.open_text || (parseInt(v, 10) ? '开桌中' : '已关桌');
                    }},
                    {field: 'gross_pool', title: '爆点池', sortable: true},
                    {field: 'cycle_count', title: '有效局', sortable: true},
                    {field: 'boom_half_count', title: '半爆计数'},
                    {field: 'updatetime', title: '更新时间', formatter: function (v, row) {
                        return row.time_text || Table.api.formatter.datetime(v);
                    }, operate: 'RANGE', addclass: 'datetimerange', sortable: true},
                    {
                        field: 'operate',
                        title: __('Operate'),
                        table: table,
                        events: {
                            'click .btn-yxx-close': function (e, value, row) {
                                e.preventDefault();
                                e.stopPropagation();
                                if (!parseInt(row.is_open, 10)) {
                                    return;
                                }
                                Layer.prompt({title: '强制关桌 · 谷歌验证码', formType: 0}, function (code, index) {
                                    code = String(code || '').replace(/\s+/g, '');
                                    if (!/^\d{6}$/.test(code)) {
                                        Layer.msg('请输入6位谷歌验证码');
                                        return;
                                    }
                                    Layer.close(index);
                                    Backend.api.ajax({
                                        url: 'fanshub/yxxgroup/close',
                                        data: {ids: row.group_id, google_code: code}
                                    }, function () {
                                        table.bootstrapTable('refresh');
                                        return false;
                                    });
                                });
                            }
                        },
                        buttons: [{
                            name: 'close',
                            text: '强制关桌',
                            title: '强制关桌',
                            classname: 'btn btn-xs btn-danger btn-yxx-close',
                            icon: 'fa fa-ban',
                            visible: function (row) {
                                return !!parseInt(row.is_open, 10);
                            }
                        }],
                        formatter: Table.api.formatter.buttons
                    }
                ]]
            });
            Table.api.bindevent(table);
        }
    };
    return Controller;
});
