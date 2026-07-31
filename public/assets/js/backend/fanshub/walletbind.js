define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/walletbind/index' + location.search,
                    add_url: 'fanshub/walletbind/add',
                    edit_url: 'fanshub/walletbind/edit',
                    del_url: 'fanshub/walletbind/del',
                    multi_url: 'fanshub/walletbind/multi',
                    table: 'fans_wallet_bind',
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
                    {field: 'id', title: 'ID', sortable: true},
                    {field: 'user_id', title: '用户ID', operate: '=', sortable: true},
                    {field: 'user.nickname', title: '昵称', operate: 'LIKE'},
                    {field: 'user.mobile', title: '手机', operate: 'LIKE'},
                    {
                        field: 'bind_mode',
                        title: '绑定方式',
                        searchList: Config.bindModeList,
                        formatter: Table.api.formatter.normal
                    },
                    {field: 'wallet_type', title: '类型编码', operate: 'LIKE'},
                    {field: 'account_name', title: '收款人', operate: 'LIKE'},
                    {
                        field: 'account_no',
                        title: '账号/地址',
                        operate: 'LIKE',
                        cellStyle: function () {
                            return {css: {'max-width': '280px', 'word-break': 'break-all'}};
                        }
                    },
                    {field: 'bank_name', title: '银行/备注', operate: 'LIKE'},
                    {
                        field: 'createtime',
                        title: '绑定时间',
                        operate: 'RANGE',
                        addclass: 'datetimerange',
                        formatter: Table.api.formatter.datetime,
                        sortable: true
                    },
                    {
                        field: 'updatetime',
                        title: '更新时间',
                        operate: 'RANGE',
                        addclass: 'datetimerange',
                        formatter: Table.api.formatter.datetime,
                        sortable: true
                    },
                    {
                        field: 'operate',
                        title: __('Operate'),
                        table: table,
                        events: Table.api.events.operate,
                        formatter: Table.api.formatter.operate
                    }
                ]]
            });
            Table.api.bindevent(table);
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
                var $mode = $('#c-bind_mode');
                var $type = $('#c-wallet_type');
                var syncType = function () {
                    if (!$mode.length || !$type.length) return;
                    var m = String($mode.val() || '');
                    var cur = String($type.val() || '').trim();
                    if (m === 'bank' && (!cur || cur === 'ALIPAY' || cur === 'WECHAT')) {
                        $type.val('BANK');
                    } else if (m === 'alipay' && (!cur || cur === 'BANK' || cur === 'WECHAT')) {
                        $type.val('ALIPAY');
                    } else if (m === 'wechat' && (!cur || cur === 'BANK' || cur === 'ALIPAY')) {
                        $type.val('WECHAT');
                    }
                };
                $mode.on('changed.bs.select change', syncType);
            }
        }
    };
    return Controller;
});
