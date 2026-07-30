define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var ctrl = 'withdrawchannel';
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/' + ctrl + '/index',
                    add_url: 'fanshub/' + ctrl + '/add',
                    edit_url: 'fanshub/' + ctrl + '/edit',
                    del_url: 'fanshub/' + ctrl + '/del',
                    multi_url: 'fanshub/' + ctrl + '/multi',
                    table: 'fans_pay_channel',
                }
            });
            var table = $("#table");
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'weigh',
                sortOrder: 'desc',
                columns: [[
                    {checkbox: true},
                    {field: 'id', title: 'ID'},
                    {field: 'name', title: '名称', operate: 'LIKE'},
                    {field: 'partition_name', title: '分区', operate: false},
                    {field: 'merchant_name', title: '总商户', operate: false},
                    {field: 'merchant_no', title: '商户号', operate: 'LIKE'},
                    {field: 'wallet_label', title: '钱包', operate: false},
                    {field: 'pay_channel', title: '通道编码', operate: 'LIKE'},
                    {field: 'handler', title: '处理器', operate: 'LIKE'},
                    {field: 'min_amount', title: '最小金额'},
                    {field: 'max_amount', title: '最大金额'},
                    {field: 'weigh', title: '排序'},
                    {field: 'status', title: '状态', searchList: {"normal": "显示", "hidden": "隐藏"}, formatter: Table.api.formatter.status},
                    {field: 'createtime', title: '创建时间', operate: 'RANGE', addclass: 'datetimerange', formatter: Table.api.formatter.datetime},
                    {
                        field: 'operate', title: __('Operate'), table: table, events: Table.api.events.operate,
                        buttons: [{
                            name: 'balance',
                            text: '查余额',
                            title: 'wanhuipay 商户余额',
                            classname: 'btn btn-xs btn-info btn-ajax',
                            icon: 'fa fa-rmb',
                            url: 'fanshub/' + ctrl + '/balance',
                            visible: function (row) {
                                return row.handler === 'wanhuitong';
                            },
                            success: function (data, ret) {
                                Layer.alert(ret.msg || '查询成功', {title: '商户余额', icon: 1});
                                return false;
                            }
                        }],
                        formatter: Table.api.formatter.operate
                    }
                ]]
            });
            Table.api.bindevent(table);
        },
        add: function () {
            Controller.api.bindevent();
            Controller.api.toggleMerchantFields();
        },
        edit: function () {
            Controller.api.bindevent();
            Controller.api.toggleMerchantFields();
        },
        api: {
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
            },
            toggleMerchantFields: function () {
                var $handler = $("select[name='row[handler]']");
                var merchantMap = Config.merchantMap || {};
                var walletList = Config.walletList || {};
                var bsCoinList = Config.bsCoinList || {};
                var fixedType = Config.fixedType || 'withdraw';
                var refresh = function () {
                    var v = $handler.val();
                    var show = (v === 'merchant' || v === 'jiuyuan' || v === 'wanhuitong' || v === 'bs');
                    $(".merchant-fields").toggle(show);
                    $(".wanhuitong-fields").toggle(v === 'wanhuitong');
                    $(".bs-fields").toggle(v === 'bs');
                    $(".bs-recharge-only").toggle(false);
                    $(".bs-withdraw-verify").toggle(false);
                    $(".md5-key-fields").toggle(v === 'merchant' || v === 'jiuyuan' || v === 'bs');
                    $(".jiuyuan-channel-fields").toggle(v === 'jiuyuan');
                    var $mno = $('#wanhuiMerchantNo');
                    if (v === 'wanhuitong') {
                        $mno.prop('readonly', true);
                        $('#wanhuiPayChannel').prop('disabled', false);
                        $('#bsPayChannel').prop('disabled', true);
                        $("input[name='row[pay_channel_md5]']").prop('disabled', true);
                    } else if (v === 'bs') {
                        $mno.prop('readonly', false);
                        $('#wanhuiPayChannel').prop('disabled', true);
                        $('#bsPayChannel').prop('disabled', false);
                        $("input[name='row[pay_channel_md5]']").prop('disabled', true);
                    } else {
                        $mno.prop('readonly', false);
                        $('#wanhuiPayChannel').prop('disabled', true);
                        $('#bsPayChannel').prop('disabled', true);
                        $("input[name='row[pay_channel_md5]']").prop('disabled', false);
                    }
                };
                var syncMerchant = function () {
                    var mid = String($('#wanhuiMerchantId').val() || '0');
                    var info = merchantMap[mid];
                    if (info && info.merchant_no) {
                        $('#wanhuiMerchantNo').val(info.merchant_no);
                    }
                };
                var syncName = function () {
                    var v = $handler.val();
                    var code = v === 'bs' ? $('#bsPayChannel').val() : $('#wanhuiPayChannel').val();
                    var label = v === 'bs' ? (bsCoinList[code] || code) : (walletList[code] || code);
                    var $name = $("input[name='row[name]']");
                    if (!$name.data('touched') && label) {
                        $name.val(label + (fixedType === 'withdraw' ? '代付' : '充值'));
                    }
                };
                $handler.on("changed.bs.select change", refresh);
                $('#wanhuiMerchantId').on('changed.bs.select change', syncMerchant);
                $('#wanhuiPayChannel').on('changed.bs.select change', syncName);
                $('#bsPayChannel').on('changed.bs.select change', syncName);
                $("input[name='row[name]']").on('input', function () {
                    $(this).data('touched', true);
                });
                refresh();
                syncMerchant();
            }
        }
    };
    return Controller;
});
