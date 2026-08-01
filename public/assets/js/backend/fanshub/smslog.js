define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/smslog/index',
                    table: 'fans_sms_log',
                }
            });
            var table = $("#table");
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                sortOrder: 'desc',
                columns: [[
                    {field: 'id', title: 'ID', operate: false},
                    {field: 'mobile', title: '手机号', operate: 'LIKE'},
                    {
                        field: 'code', title: '验证码', operate: 'LIKE',
                        formatter: function (value) {
                            return value
                                ? ('<code style="font-size:14px;font-weight:700;color:#c0392b;">' + value + '</code>')
                                : '-';
                        }
                    },
                    {
                        field: 'event', title: '事件', operate: 'LIKE',
                        formatter: function (value) {
                            if (value === 'fanshub_login') return '登录/验证';
                            return value || '-';
                        }
                    },
                    {
                        field: 'channel', title: '通道', searchList: Config.channelList || {},
                        formatter: Table.api.formatter.normal
                    },
                    {field: 'ip', title: 'IP', operate: 'LIKE'},
                    {
                        field: 'status', title: '状态', searchList: Config.statusList || {},
                        formatter: Table.api.formatter.status
                    },
                    {
                        field: 'createtime', title: '发送时间', operate: 'RANGE', addclass: 'datetimerange',
                        formatter: Table.api.formatter.datetime
                    },
                    {
                        field: 'usedtime', title: '使用时间', operate: 'RANGE', addclass: 'datetimerange',
                        formatter: Table.api.formatter.datetime
                    }
                ]]
            });
            Table.api.bindevent(table);
        }
    };
    return Controller;
});
