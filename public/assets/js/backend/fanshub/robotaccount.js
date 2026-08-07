define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/robotaccount/index',
                    adjust_url: 'fanshub/robotaccount/adjust',
                    batchadjust_url: 'fanshub/robotaccount/batchadjust',
                    seed_url: 'fanshub/robotaccount/seed',
                    table: 'fans_account',
                }
            });
            var table = $("#table");
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'createtime',
                sortOrder: 'desc',
                columns: [[
                    {checkbox: true},
                    {field: 'user_id', title: '会员ID', sortable: true},
                    {field: 'user.nickname', title: '昵称', operate: 'LIKE', formatter: function (value, row) {
                        return row.nickname || value || (row.user && row.user.nickname) || ('ID' + row.user_id);
                    }},
                    {field: 'user.mobile', title: '手机号', operate: 'LIKE'},
                    {field: 'hongbao', title: '红宝', operate: 'BETWEEN'},
                    {field: 'rights', title: '股份', operate: 'BETWEEN'},
                    {field: 'status', title: '状态', searchList: {"normal": "正常", "frozen": "冻结"}, formatter: Table.api.formatter.status},
                    {field: 'createtime', title: '注册时间', operate: 'RANGE', addclass: 'datetimerange', sortable: true, formatter: Table.api.formatter.datetime},
                    {
                        field: 'operate', title: '操作', table: table,
                        buttons: [{
                            name: 'adjust',
                            text: '调账',
                            title: '机器人调账',
                            classname: 'btn btn-xs btn-warning btn-dialog',
                            icon: 'fa fa-calculator',
                            url: 'fanshub/robotaccount/adjust'
                        }],
                        events: Table.api.events.operate,
                        formatter: Table.api.formatter.operate
                    }
                ]]
            });
            Table.api.bindevent(table);

            function selectedIds() {
                var rows = table.bootstrapTable('getSelections') || [];
                return rows.map(function (r) { return r.user_id || r.id; }).filter(Boolean);
            }

            $(document).on('click', '.btn-copy-ids', function () {
                var ids = selectedIds();
                if (!ids.length) {
                    Toastr.error('请先勾选机器人');
                    return;
                }
                var text = ids.join(',');
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(function () {
                        Toastr.success('已复制 ' + ids.length + ' 个ID');
                    }).catch(function () {
                        window.prompt('复制以下ID', text);
                    });
                } else {
                    window.prompt('复制以下ID', text);
                }
            });

            $(document).on('click', '.btn-batch-adjust', function () {
                var ids = selectedIds();
                if (!ids.length) {
                    Toastr.error('请先勾选机器人');
                    return;
                }
                Fast.api.open(
                    $.fn.bootstrapTable.defaults.extend.batchadjust_url + '?ids=' + encodeURIComponent(ids.join(',')),
                    '批量加余额',
                    {area: ['520px', '420px']}
                );
            });

            $(document).on('click', '.btn-seed', function () {
                Layer.confirm('确认注册一批 300 个机器人？\n手机号从 10000000001 起递增\n初始红宝 100000\n昵称随机', function (index) {
                    Layer.close(index);
                    Fast.api.ajax({
                        url: $.fn.bootstrapTable.defaults.extend.seed_url,
                        data: {count: 300, start_mobile: '10000000001', hongbao: 100000}
                    }, function (data, ret) {
                        table.bootstrapTable('refresh');
                        if (data && data.ids) {
                            Layer.alert('新建ID（可复制）：\n' + data.ids, {area: ['560px', '360px']});
                        }
                        return false;
                    });
                });
            });
        },
        batchadjust: function () {
            Form.api.bindevent($("#batchadjust-form"));
        },
        adjust: function () {
            Form.api.bindevent($("#adjust-form"));
        }
    };
    return Controller;
});
