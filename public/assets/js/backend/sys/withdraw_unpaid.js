define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'backend/sys/platform'], function ($, undefined, Backend, Table, Form, Platform) {

    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'sys/withdraw_unpaid/index' + location.search,
                    edit_url: 'sys/withdraw_unpaid/edit',
                    table: 'sys_withdraw_unpaid',
                }
            });

            var table = $("#table");
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                sortOrder: 'desc',
                columns: [
                    [
                        {checkbox: true},
                        {field: 'id', title: __('Id')},
                        {field: 'pid', title: __('Pid'), searchList: Platform.searchList, formatter: Platform.formatter},
                        {field: 'order_no', title: __('Order_no'), operate: 'LIKE'},
                        {field: 'username', title: __('Username'), operate: 'LIKE'},
                        {field: 'money', title: __('Money'), operate: 'LIKE'},
                        {field: 'merchAgentId', title: __('MerchAgentId')},
                        {field: 'merch_channel_name', title: __('Merch_channel_name'), operate: false, formatter: function (value) {
                            return value ? value : '<span class="text-muted">未匹配</span>';
                        }},
                        {field: 'urge_count', title: __('Urge_count')},
                        {field: 'last_urge_time', title: __('Last_urge_time'), operate: 'RANGE', addclass: 'datetimerange', autocomplete: false, formatter: Table.api.formatter.datetime},
                        {field: 'pay_status', title: __('Pay_status'), searchList: {"0": __('Unpaid'), "1": __('Paid')}, formatter: Table.api.formatter.status},
                        {field: 'addtime', title: __('Addtime'), operate: 'RANGE', addclass: 'datetimerange', autocomplete: false, formatter: Table.api.formatter.datetime},
                        {
                            field: 'operate',
                            title: __('Operate'),
                            table: table,
                            events: Table.api.events.operate,
                            buttons: [
                                {
                                    name: 'urge',
                                    text: __('Manual_urge'),
                                    title: __('Manual_urge'),
                                    icon: 'fa fa-bell',
                                    classname: 'btn btn-warning btn-xs btn-ajax',
                                    url: 'sys/withdraw_unpaid/urge/ids/{id}',
                                    confirm: __('Confirm_manual_urge'),
                                    refresh: true,
                                    visible: function (row) {
                                        return String(row.pay_status) === '0';
                                    }
                                }
                            ],
                            formatter: Table.api.formatter.operate
                        }
                    ]
                ]
            });

            Table.api.bindevent(table);
        },
        edit: function () {
            Controller.api.bindevent();
        },
        api: {
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
            }
        }
    };
    return Controller;
});
