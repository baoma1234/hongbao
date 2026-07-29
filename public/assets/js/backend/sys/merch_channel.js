define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'backend/sys/platform'], function ($, undefined, Backend, Table, Form, Platform) {

    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'sys/merch_channel/index' + location.search,
                    add_url: 'sys/merch_channel/add',
                    edit_url: 'sys/merch_channel/edit',
                    del_url: 'sys/merch_channel/del',
                    multi_url: 'sys/merch_channel/multi',
                    table: 'sys_merch_channel',
                }
            });

            var table = $("#table");
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'row_id',
                sortName: 'row_id',
                columns: [
                    [
                        {checkbox: true},
                        {field: 'row_id', title: 'ID', visible: false},
                        {field: 'id', title: __('Id')},
                        {field: 'pid', title: __('Pid'), searchList: Platform.searchList, formatter: Platform.formatter},
                        {field: 'channelName', title: __('ChannelName'), operate: 'LIKE'},
                        {field: 'merchCode', title: __('MerchCode'), operate: 'LIKE'},
                        {field: 'chanel', title: __('Chanel'), operate: 'LIKE', formatter: function (value) {
                            return value ? '<span class="text-success">' + value + '</span>' : '<span class="text-danger">未配置</span>';
                        }},
                        {
                            field: 'status',
                            title: __('Status'),
                            align: 'center',
                            table: table,
                            searchList: {"normal": __('Normal'), "hidden": __('Hidden')},
                            formatter: Controller.api.formatter.statusSwitch
                        },
                        {field: 'addtime', title: __('Addtime'), operate: 'RANGE', addclass: 'datetimerange', autocomplete: false, formatter: Table.api.formatter.datetime},
                        {field: 'operate', title: __('Operate'), table: table, events: Table.api.events.operate, formatter: Table.api.formatter.operate}
                    ]
                ]
            });

            Table.api.bindevent(table);

            $(document).on('click', '.btn-sync-merch', function () {
                var params = new URLSearchParams(location.search);
                var activePid = $('.platform-pid-tabs li.active a').data('value');
                var pid = activePid || params.get('pid') || '';
                if (!pid) {
                    Toastr.error('请先选择要同步的平台');
                    return;
                }
                Layer.confirm('确认从远端同步商户通道？<br>pid=' + pid, function (index) {
                    Layer.close(index);
                    var loading = Layer.load(1);
                    $.ajax({
                        url: Fast.api.fixurl('sys/merch_channel/sync'),
                        type: 'POST',
                        data: {pid: pid},
                        dataType: 'json',
                        success: function (ret) {
                            Layer.close(loading);
                            if (ret.code === 1) {
                                var data = ret.data || {};
                                var saved = data.saved || data;
                                Toastr.success(
                                    '同步完成：远端 ' + (data.remote_total || 0)
                                    + ' 条，新增 ' + (data.inserted || saved.inserted || 0)
                                    + '，更新 ' + (data.updated || saved.updated || 0)
                                    + '，跳过 ' + (data.skipped || saved.skipped || 0)
                                );
                                table.bootstrapTable('refresh');
                            } else {
                                Toastr.error(ret.msg || '同步失败');
                            }
                        },
                        error: function () {
                            Layer.close(loading);
                            Toastr.error('同步请求失败');
                        }
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
            },
            formatter: {
                statusSwitch: function (value, row, index) {
                    var table = this.table;
                    var options = table ? table.bootstrapTable('getOptions') : {};
                    var pk = options.pk || 'row_id';
                    var yes = 'normal';
                    var no = 'hidden';
                    var enabled = value === yes;
                    var iconClass = 'fa fa-toggle-on fa-2x merch-status-toggle';
                    if (!enabled) {
                        iconClass += ' fa-flip-horizontal is-off';
                    } else {
                        iconClass += ' is-on';
                    }
                    return "<a href='javascript:;' data-toggle='tooltip' title='点击切换启停' class='btn-change' data-index='"
                        + index + "' data-id='" + row[pk] + "' data-params='status=" + (enabled ? no : yes)
                        + "'><i class='" + iconClass + "'></i></a>";
                }
            }
        }
    };
    return Controller;
});
