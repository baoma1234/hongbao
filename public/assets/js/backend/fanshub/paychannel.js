define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/paychannel/index',
                    add_url: 'fanshub/paychannel/add',
                    edit_url: 'fanshub/paychannel/edit',
                    del_url: 'fanshub/paychannel/del',
                    multi_url: 'fanshub/paychannel/multi',
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
                    {field: 'type', title: '类型', searchList: {"recharge": "充值", "withdraw": "提现"}, formatter: function (v) {
                        return v === 'withdraw' ? '提现' : '充值';
                    }},
                    {field: 'name', title: '名称', operate: 'LIKE'},
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
                            title: '网关商户余额',
                            classname: 'btn btn-xs btn-info btn-ajax',
                            icon: 'fa fa-rmb',
                            url: 'fanshub/paychannel/balance',
                            visible: function (row) {
                                return row.handler === 'wanhuitong' || row.handler === 'bs';
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
        add: function () { Controller.api.bindevent(); Controller.api.toggleMerchantFields(); },
        edit: function () { Controller.api.bindevent(); Controller.api.toggleMerchantFields(); },
        api: {
            bindevent: function () { Form.api.bindevent($("form[role=form]")); },
            toggleMerchantFields: function () {
                var $handler = $("select[name='row[handler]']");
                var merchantMap = Config.merchantMap || {};
                var bsMerchantMap = Config.bsMerchantMap || {};
                var walletList = Config.walletList || {};
                var refresh = function () {
                    var v = $handler.val();
                    var show = (v === 'merchant' || v === 'jiuyuan' || v === 'wanhuitong' || v === 'bs');
                    $(".merchant-fields").toggle(show);
                    $(".wanhuitong-fields").toggle(v === 'wanhuitong');
                    $(".bs-fields").toggle(v === 'bs');
                    $(".bs-recharge-only").toggle(v === 'bs' && ($("select[name='row[type]']").val() || Config.fixedType || 'recharge') !== 'withdraw');
                    $(".md5-key-fields").toggle(v === 'merchant' || v === 'jiuyuan' || v === 'bs');
                    $(".jiuyuan-channel-fields").toggle(v === 'jiuyuan');
                    var $mno = $('#wanhuiMerchantNo');
                    if (v === 'wanhuitong') {
                        $mno.prop('readonly', true);
                        $('#wanhuiMerchantId').prop('disabled', false);
                        $('#bsMerchantId').prop('disabled', true);
                        $('#wanhuiPayChannel').prop('disabled', false);
                        $('#bsPayChannel').prop('disabled', true);
                        $("input[name='row[pay_channel_md5]']").prop('disabled', true);
                    } else if (v === 'bs') {
                        $mno.prop('readonly', true);
                        $('#wanhuiMerchantId').prop('disabled', true);
                        $('#bsMerchantId').prop('disabled', false);
                        $('#wanhuiPayChannel').prop('disabled', true);
                        $('#bsPayChannel').prop('disabled', false);
                        $("input[name='row[pay_channel_md5]']").prop('disabled', true);
                        $('.bs-md5-fields').hide();
                    } else {
                        $('#wanhuiMerchantId').prop('disabled', true);
                        $('#bsMerchantId').prop('disabled', true);
                        $mno.prop('readonly', false);
                        $('#wanhuiPayChannel').prop('disabled', true);
                        $('#bsPayChannel').prop('disabled', true);
                        $("input[name='row[pay_channel_md5]']").prop('disabled', false);
                    }
                };
                var syncBsMerchant = function () {
                    var mid = String($('#bsMerchantId').val() || '0');
                    var info = bsMerchantMap[mid];
                    if (info && info.merchant_no) {
                        $('#wanhuiMerchantNo').val(info.merchant_no);
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
                    var bsCoinList = Config.bsCoinList || {};
                    var label = v === 'bs' ? (bsCoinList[code] || code) : (walletList[code] || code);
                    var type = $("select[name='row[type]']").val();
                    var $name = $("input[name='row[name]']");
                    if (!$name.data('touched') && label) {
                        $name.val(label + (type === 'withdraw' ? '代付' : '充值'));
                    }
                };
                $handler.on("changed.bs.select change", refresh);
                $('#wanhuiMerchantId').on('changed.bs.select change', syncMerchant);
                $('#bsMerchantId').on('changed.bs.select change', syncBsMerchant);
                $('#wanhuiPayChannel').on('changed.bs.select change', syncName);
                $('#bsPayChannel').on('changed.bs.select change', syncName);
                $("select[name='row[type]']").on('changed.bs.select change', syncName);
                $("input[name='row[name]']").on('input', function () {
                    $(this).data('touched', true);
                });
                refresh();
                syncMerchant();
                syncBsMerchant();
            }
        }
    };
    return Controller;
});
