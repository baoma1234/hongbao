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
                            title: '网关商户余额',
                            classname: 'btn btn-xs btn-info btn-ajax',
                            icon: 'fa fa-rmb',
                            url: 'fanshub/' + ctrl + '/balance',
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
            refreshSelect: function ($el) {
                if ($el.length && $el.data('selectpicker')) {
                    $el.selectpicker('refresh');
                }
            },
            setActiveMerchantSelect: function (which) {
                var $w = $('#wanhuiMerchantId');
                var $b = $('#bsMerchantId');
                var $wp = $('#wanhuiPayChannel');
                var $bp = $('#bsPayChannel');
                if (which === 'wanhui') {
                    $w.prop('disabled', false).attr('name', 'row[merchant_id]');
                    $b.prop('disabled', true).removeAttr('name');
                    $wp.prop('disabled', false).attr('name', 'row[pay_channel]');
                    $bp.prop('disabled', true).removeAttr('name');
                } else if (which === 'bs') {
                    $b.prop('disabled', false).attr('name', 'row[merchant_id]');
                    $w.prop('disabled', true).removeAttr('name');
                    $bp.prop('disabled', false).attr('name', 'row[pay_channel]');
                    $wp.prop('disabled', true).removeAttr('name');
                } else {
                    $w.prop('disabled', true).removeAttr('name');
                    $b.prop('disabled', true).removeAttr('name');
                    $wp.prop('disabled', true).removeAttr('name');
                    $bp.prop('disabled', true).removeAttr('name');
                }
                Controller.api.refreshSelect($w);
                Controller.api.refreshSelect($b);
                Controller.api.refreshSelect($wp);
                Controller.api.refreshSelect($bp);
            },
            toggleMerchantFields: function () {
                var $handler = $("select[name='row[handler]']");
                var merchantMap = Config.merchantMap || {};
                var bsMerchantMap = Config.bsMerchantMap || {};
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
                    $(".md5-key-fields").toggle(v === 'merchant' || v === 'jiuyuan' || v === 'bs');
                    $(".jiuyuan-channel-fields").toggle(v === 'jiuyuan');
                    var $mno = $('#wanhuiMerchantNo');
                    if (v === 'wanhuitong') {
                        $mno.prop('readonly', true);
                        Controller.api.setActiveMerchantSelect('wanhui');
                        $("input[name='row[pay_channel_md5]']").prop('disabled', true);
                    } else if (v === 'bs') {
                        $mno.prop('readonly', true);
                        Controller.api.setActiveMerchantSelect('bs');
                        $("input[name='row[pay_channel_md5]']").prop('disabled', true);
                    } else {
                        $mno.prop('readonly', false);
                        Controller.api.setActiveMerchantSelect('none');
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
                    var label = v === 'bs' ? (bsCoinList[code] || code) : (walletList[code] || code);
                    var $name = $("input[name='row[name]']");
                    if (!$name.data('touched') && label) {
                        $name.val(label + (fixedType === 'withdraw' ? '代付' : '充值'));
                    }
                };
                $handler.on("changed.bs.select change", refresh);
                $('#wanhuiMerchantId').on('changed.bs.select change', syncMerchant);
                $('#bsMerchantId').on('changed.bs.select change', syncBsMerchant);
                $('#wanhuiPayChannel').on('changed.bs.select change', syncName);
                $('#bsPayChannel').on('changed.bs.select change', syncName);
                $("input[name='row[name]']").on('input', function () {
                    $(this).data('touched', true);
                });
                var $nameInit = $("input[name='row[name]']");
                if ($.trim($nameInit.val() || '') !== '') {
                    $nameInit.data('touched', true);
                }
                refresh();
                syncMerchant();
                syncBsMerchant();
            }
        }
    };
    return Controller;
});
