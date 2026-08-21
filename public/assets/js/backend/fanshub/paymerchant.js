define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/paymerchant/index',
                    add_url: 'fanshub/paymerchant/add',
                    edit_url: 'fanshub/paymerchant/edit',
                    del_url: 'fanshub/paymerchant/del',
                    multi_url: 'fanshub/paymerchant/multi',
                    table: 'fans_pay_merchant',
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
                    {field: 'name', title: '备注名', operate: 'LIKE'},
                    {field: 'merchant_no', title: '商户号', operate: 'LIKE'},
                    {field: 'gateway_label', title: '网关', operate: false},
                    {field: 'gateway', title: '网关标识', visible: false},
                    {field: 'channel_count', title: '挂靠通道', operate: false},
                    {field: 'site', title: '公网域名', operate: 'LIKE'},
                    {field: 'private_key', title: '私钥', operate: false},
                    {field: 'platform_public_key', title: '平台公钥', operate: false},
                    {field: 'status', title: '状态', searchList: {"normal": "启用", "hidden": "停用"}, formatter: Table.api.formatter.status},
                    {field: 'createtime', title: '创建时间', operate: 'RANGE', addclass: 'datetimerange', formatter: Table.api.formatter.datetime},
                    {
                        field: 'operate', title: __('Operate'), table: table, events: Table.api.events.operate,
                        buttons: [
                            {
                                name: 'batchchannels',
                                text: '批量加通道',
                                title: '批量创建充提通道',
                                classname: 'btn btn-xs btn-success btn-dialog',
                                icon: 'fa fa-plus-square',
                                url: 'fanshub/paymerchant/batchchannels'
                            },
                            {
                                name: 'balance',
                                text: '查余额',
                                title: '商户余额',
                                classname: 'btn btn-xs btn-info btn-ajax',
                                icon: 'fa fa-rmb',
                                url: 'fanshub/paymerchant/balance',
                                success: function (data, ret) {
                                    Layer.alert(ret.msg || '查询成功', {title: '商户余额', icon: 1});
                                    return false;
                                }
                            },
                            {
                                name: 'syncrates',
                                text: '同步汇率',
                                title: '同步 BS 通道汇率',
                                classname: 'btn btn-xs btn-warning btn-ajax',
                                icon: 'fa fa-exchange',
                                url: 'fanshub/paymerchant/syncrates',
                                visible: function (row) {
                                    return row && row.gateway === 'bs';
                                },
                                success: function (data, ret) {
                                    Layer.alert(ret.msg || '同步成功', {title: 'BS 汇率', icon: 1});
                                    table.bootstrapTable('refresh');
                                    return false;
                                }
                            }
                        ],
                        formatter: Table.api.formatter.operate
                    }
                ]]
            });
            Table.api.bindevent(table);
        },
        add: function () { Controller.api.bindevent(); Controller.api.toggleGatewayFields(); },
        edit: function () { Controller.api.bindevent(); Controller.api.toggleGatewayFields(); },
        batchchannels: function () {
            Form.api.bindevent($("form[role=form],#batch-form"));
        },
        api: {
            bindevent: function () { Form.api.bindevent($("form[role=form]")); },
            toggleGatewayFields: function () {
                var $gw = $('#payMerchantGateway');
                var $ips = $('#payMerchantCallbackIps');
                var refresh = function () {
                    var v = $gw.val();
                    var isBs = v === 'bs';
                    $('.paymerchant-bs-only').toggle(isBs);
                    $('.paymerchant-wanhuitong-only').toggle(!isBs);
                    if (!$ips.data('touched')) {
                        $ips.val(isBs ? '8.217.236.95' : '18.162.71.242,95.40.141.160');
                    }
                };
                $gw.on('changed.bs.select change', refresh);
                $ips.on('input', function () { $(this).data('touched', true); });
                refresh();
            }
        }
    };
    return Controller;
});
