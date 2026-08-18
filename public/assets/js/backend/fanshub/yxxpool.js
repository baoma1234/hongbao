define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/yxxpool/index' + location.search,
                    detail_url: 'fanshub/yxxpool/raindetail',
                    table: 'fans_yxx_rain_events'
                }
            });
            var table = $("#table");
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                sortOrder: 'desc',
                columns: [[
                    {field: 'id', title: 'ID', sortable: true},
                    {field: 'round_index', title: '期号', sortable: true},
                    {field: 'release_amount', title: '释放金额', sortable: true},
                    {field: 'participant_count', title: '人数'},
                    {field: 'gross_pool_before', title: '释放前'},
                    {field: 'gross_pool_after', title: '释放后'},
                    {field: 'status', title: '状态', formatter: function (v) {
                        return parseInt(v, 10) === 1 ? '已派发' : '熔断';
                    }},
                    {field: 'createtime', title: '时间', formatter: Table.api.formatter.datetime, operate: 'RANGE', addclass: 'datetimerange', sortable: true},
                    {
                        field: 'operate',
                        title: __('Operate'),
                        table: table,
                        events: Table.api.events.operate,
                        buttons: [{
                            name: 'detail',
                            text: '明细',
                            title: '红包雨明细',
                            classname: 'btn btn-xs btn-info btn-dialog',
                            icon: 'fa fa-list',
                            url: 'fanshub/yxxpool/raindetail'
                        }],
                        formatter: Table.api.formatter.operate
                    }
                ]]
            });
            Table.api.bindevent(table);

            $(document).on('click', '.btn-setstatus', function () {
                var status = $('input[name="status"]:checked').val() || '';
                var code = String($('input[name="google_code"]').val() || '').replace(/\s+/g, '');
                if (!status) {
                    Layer.msg('请选择状态');
                    return;
                }
                if (!/^\d{6}$/.test(code)) {
                    Layer.msg('请输入6位谷歌验证码');
                    return;
                }
                Backend.api.ajax({
                    url: 'fanshub/yxxpool/setstatus',
                    data: {status: status, google_code: code}
                }, function () {
                    setTimeout(function () {
                        location.reload();
                    }, 400);
                    return false;
                });
            });
        },
        raindetail: function () {}
    };
    return Controller;
});
