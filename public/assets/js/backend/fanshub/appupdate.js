define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/appupdate/index',
                    add_url: 'fanshub/appupdate/add',
                    edit_url: 'fanshub/appupdate/edit',
                    del_url: 'fanshub/appupdate/del',
                    multi_url: '',
                    table: 'fanshub_app_update'
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
                    {field: 'id', title: 'ID', sortable: true},
                    {
                        field: 'platform', title: '平台', searchList: Config.platformList,
                        formatter: Table.api.formatter.normal
                    },
                    {field: 'version_name', title: 'versionName', operate: 'LIKE'},
                    {field: 'version_code', title: 'versionCode', sortable: true},
                    {
                        field: 'download_url', title: '下载地址', operate: 'LIKE',
                        formatter: function (v) {
                            var s = v ? String(v) : '';
                            if (!s) return '<span class="text-muted">（兜底通用链接）</span>';
                            var short = s.length > 42 ? s.slice(0, 42) + '…' : s;
                            return '<a href="' + esc(s) + '" target="_blank" rel="noopener">' + esc(short) + '</a>';
                        }
                    },
                    {
                        field: 'force_update', title: '强制', searchList: Config.forceUpdateList,
                        formatter: function (v) {
                            return parseInt(v, 10) === 1
                                ? '<span class="label label-danger">强制</span>'
                                : '<span class="label label-default">可选</span>';
                        }
                    },
                    {
                        field: 'is_current', title: '当前', searchList: Config.isCurrentList,
                        formatter: function (v) {
                            return parseInt(v, 10) === 1
                                ? '<span class="label label-success">推送中</span>'
                                : '<span class="text-muted">—</span>';
                        }
                    },
                    {field: 'update_note', title: '说明', operate: 'LIKE', formatter: Table.api.formatter.content},
                    {field: 'remark', title: '备注', operate: 'LIKE'},
                    {
                        field: 'status', title: '状态', searchList: Config.statusList,
                        formatter: Table.api.formatter.status
                    },
                    {
                        field: 'createtime', title: '创建时间', operate: 'RANGE', addclass: 'datetimerange',
                        formatter: Table.api.formatter.datetime, sortable: true
                    },
                    {
                        field: 'operate', title: __('Operate'), table: table,
                        events: $.extend({}, Table.api.events.operate, {
                            'click .btn-publish': function (e, value, row) {
                                e.stopPropagation();
                                Layer.confirm('将该版本设为「' + (Config.platformList[row.platform] || row.platform) + '」当前推送？', function (index) {
                                    Fast.api.ajax({
                                        url: 'fanshub/appupdate/publish',
                                        data: {ids: row.id}
                                    }, function () {
                                        table.bootstrapTable('refresh');
                                        return false;
                                    });
                                    Layer.close(index);
                                });
                            }
                        }),
                        formatter: function (value, row, index) {
                            var that = $.extend({}, this);
                            var html = Table.api.formatter.operate.call(that, value, row, index);
                            if (parseInt(row.is_current, 10) !== 1) {
                                html += ' <a href="javascript:;" class="btn btn-xs btn-info btn-publish" title="设为当前"><i class="fa fa-check"></i> 设为当前</a>';
                            }
                            return html;
                        }
                    }
                ]]
            });
            Table.api.bindevent(table);

            $('#app-update-enabled').on('change', function () {
                var on = $(this).prop('checked') ? 1 : 0;
                Fast.api.ajax({
                    url: 'fanshub/appupdate/toggle',
                    data: {enabled: on}
                }, function (data, ret) {
                    Toastr.success(ret.msg || '已保存');
                    return false;
                }, function () {
                    $('#app-update-enabled').prop('checked', !on);
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
                Form.api.bindevent($('form[role=form]'));
            }
        }
    };
    return Controller;
});
