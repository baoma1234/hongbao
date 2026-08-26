define(['jquery', 'bootstrap', 'backend', 'table', 'form', './common'], function ($, undefined, Backend, Table, Form, FanshubCommon) {
    var Controller = {
        index: function () {
            if (!$('#fanshub-account-promote-style').length) {
                $('<style id="fanshub-account-promote-style">')
                    .text('.btn-promote-master{background-color:#6a62cb!important;border-color:#6a62cb!important;color:#fff!important;}.btn-promote-master:hover,.btn-promote-master:focus{background-color:#5a52b8!important;border-color:#5a52b8!important;color:#fff!important;}'
                        + '.fanshub-acc-cell{line-height:1.55;white-space:normal;text-align:left;min-width:150px;}'
                        + '.fanshub-acc-cell .fanshub-acc-line+.fanshub-acc-line{margin-top:2px;}')
                    .appendTo('head');
            }
            function escCell(v) {
                return $('<div/>').text(v == null ? '' : String(v)).html();
            }
            function copyCell(v) {
                v = String(v || '').trim();
                if (!v) return '-';
                return '<a href="javascript:;" class="btn-copy-cell text-primary" data-copy="' + escCell(v) + '" title="点击复制">' + escCell(v) + '</a>';
            }
            function infoLine(label, html) {
                return '<div class="fanshub-acc-line"><span class="text-muted">' + label + ':</span> ' + html + '</div>';
            }
            function pickNickname(row) {
                if (row.nickname) return row.nickname;
                if (row.user && row.user.nickname) return row.user.nickname;
                return row.user_id ? ('ID' + row.user_id) : '-';
            }
            function pickMobile(row) {
                return (row.user && row.user.mobile) || row.mobile || '';
            }
            function fmtVip(row) {
                var map = Config.memberLevelList || {};
                var key = String(row.member_level === undefined || row.member_level === null ? '' : row.member_level);
                var name = map[key];
                if (!name) {
                    return key === '' ? '-' : ('VIP' + key);
                }
                return '<span class="label label-warning">VIP' + key + '</span> ' + escCell(name);
            }
            function fmtStage(row) {
                return row.flow_stage === 'stage2' ? '阶段二' : (row.flow_stage === 'stage1' ? '阶段一' : (row.flow_stage || '-'));
            }
            function fmtPayPwd(row) {
                return row.pay_password
                    ? '<span class="label label-success">已设置</span>'
                    : '<span class="text-muted">未设置</span>';
            }
            function fmtChatForbid(row) {
                var value = row.chat_forbid;
                if (!value) return '<span class="text-muted">-</span>';
                var map = {text:'文字', image:'图片', sticker:'表情', video:'视频', file:'文件', rp_send:'发红包', rp_grab:'领红包'};
                var obj = null;
                try { obj = typeof value === 'object' ? value : JSON.parse(value); } catch (e) { return '-'; }
                if (!obj) return '-';
                var tags = [];
                Object.keys(map).forEach(function (k) {
                    if (obj[k]) tags.push(map[k]);
                });
                if (!tags.length) return '<span class="text-muted">-</span>';
                return '<span class="label label-danger" title="' + tags.join('、') + '">禁' + tags.length + '项</span>';
            }
            function fmtDt(ts) {
                if (!ts) return '-';
                return Table.api.formatter.datetime(ts, {}, 0);
            }
            Table.api.init({
                extend: {
                    index_url: 'fanshub/account/index',
                    edit_url: 'fanshub/account/edit',
                    del_url: 'fanshub/account/del',
                    export_url: 'fanshub/account/export',
                    table: 'fans_account',
                }
            });
            var table = $("#table");
            var hardDelConfirmOne = '确认真删除该用户？\n将永久删除登录账号、资产、邀请关系、聊天等相关数据，且不可恢复！';
            var hardDelConfirmBatch = function (n) {
                return '确认真删除选中的 ' + n + ' 个用户？\n将永久删除登录账号、资产、邀请关系、聊天等相关数据，且不可恢复！';
            };
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'createtime',
                sortOrder: 'desc',
                columns: [[
                    {checkbox: true},
                    {
                        field: 'avatar',
                        title: '头像',
                        operate: false,
                        events: Table.api.events.image,
                        formatter: function (value, row) {
                            var url = value || (row.user && row.user.avatar) || '';
                            return Table.api.formatter.image.call(this, url, row, 0);
                        }
                    },
                    {field: 'user_id', title: '用户ID', visible: false, operate: '='},
                    {field: 'user_info', title: '用户信息', operate: false, formatter: function (value, row) {
                        var uid = row.user_id || '-';
                        var nick = pickNickname(row);
                        var mobile = pickMobile(row);
                        var mainUid = row.main_uid || '';
                        return '<div class="fanshub-acc-cell">'
                            + infoLine('会员ID', copyCell(uid))
                            + infoLine('昵称', escCell(nick))
                            + infoLine('手机号', copyCell(mobile))
                            + infoLine('主站账号', mainUid ? copyCell(mainUid) : '-')
                            + '</div>';
                    }},
                    {field: 'user.nickname', title: '昵称', visible: false, operate: 'LIKE'},
                    {field: 'user.mobile', title: '手机号', visible: false, operate: 'LIKE'},
                    {field: 'main_uid', title: '主站账号', visible: false, operate: 'LIKE'},
                    {field: 'agent_info', title: '代理信息', operate: false, formatter: function (value, row) {
                        var upId = row.inviter_user_id || '';
                        var upMobile = row.inviter_mobile || '';
                        var subWd = row.sub_withdrawn_count != null ? row.sub_withdrawn_count : '-';
                        return '<div class="fanshub-acc-cell">'
                            + infoLine('上线ID', upId ? copyCell(upId) : '-')
                            + infoLine('上线手机', upMobile ? copyCell(upMobile) : '-')
                            + infoLine('下线提现', escCell(subWd))
                            + '</div>';
                    }},
                    {field: 'inviter_user_id', title: '上线ID', visible: false, operate: '='},
                    {field: 'inviter_mobile', title: '上线手机', visible: false, operate: 'LIKE'},
                    {field: 'sub_withdrawn_count', title: '下线提现', visible: false, operate: 'BETWEEN'},
                    {field: 'user_status', title: '用户状态', operate: false, formatter: function (value, row) {
                        return '<div class="fanshub-acc-cell">'
                            + infoLine('股份', escCell(row.rights != null ? row.rights : '-'))
                            + infoLine('红宝', escCell(row.hongbao != null ? row.hongbao : '-'))
                            + infoLine('累计流水', escCell(row.turnover != null ? row.turnover : '0'))
                            + infoLine('VIP等级', fmtVip(row))
                            + infoLine('阶段', escCell(fmtStage(row)))
                            + infoLine('支付密码', fmtPayPwd(row))
                            + infoLine('聊天禁言', fmtChatForbid(row))
                            + infoLine('登录封禁', (row.user && row.user.status === 'hidden')
                                ? '<span class="text-danger">已封禁</span>'
                                : '<span class="text-success">正常</span>')
                            + '</div>';
                    }},
                    {field: 'rights', title: '股份', visible: false, operate: 'BETWEEN'},
                    {field: 'hongbao', title: '红宝', visible: false, operate: 'BETWEEN'},
                    {field: 'turnover', title: '累计流水', visible: false, operate: 'BETWEEN'},
                    {field: 'member_level', title: 'VIP等级', visible: false, searchList: $.extend({}, Config.memberLevelList || {})},
                    {field: 'flow_stage', title: '阶段', visible: false, searchList: {"stage1": "阶段一", "stage2": "阶段二"}},
                    {field: 'admin_remark', title: '用户信息备注', operate: 'LIKE', formatter: function (value) {
                        var v = String(value || '').trim();
                        if (!v) return '<span class="text-muted">-</span>';
                        var short = v.length > 36 ? (v.substring(0, 36) + '…') : v;
                        return '<span title="' + escCell(v) + '">' + escCell(short) + '</span>';
                    }},
                    {field: 'status', title: '状态', searchList: {"normal": "正常", "frozen": "冻结"}, formatter: Table.api.formatter.status},
                    {field: 'op_time', title: '操作时间', operate: false, formatter: function (value, row) {
                        var joinTs = row.createtime || 0;
                        var joinIp = (row.user && row.user.joinip) || row.joinip || '-';
                        var loginTs = row.logintime || (row.user && row.user.logintime) || 0;
                        var loginIp = (row.user && row.user.loginip) || row.loginip || '-';
                        var updTs = row.updatetime || 0;
                        return '<div class="fanshub-acc-cell">'
                            + infoLine('注册', escCell(fmtDt(joinTs)) + ' / ' + escCell(joinIp))
                            + infoLine('登录', escCell(fmtDt(loginTs)) + ' / ' + escCell(loginIp))
                            + infoLine('更新', escCell(fmtDt(updTs)))
                            + '</div>';
                    }},
                    {field: 'createtime', title: '注册时间', visible: false, operate: 'RANGE', addclass: 'datetimerange', sortable: true},
                    {field: 'user.joinip', title: '注册IP', visible: false, operate: 'LIKE'},
                    {field: 'user.loginip', title: '登录IP', visible: false, operate: 'LIKE'},
                    {field: 'updatetime', title: '更新时间', visible: false, operate: 'RANGE', addclass: 'datetimerange', sortable: true},
                    {
                        field: 'operate', title: '操作', table: table,
                        events: Table.api.events.operate,
                        buttons: [{
                            name: 'detail',
                            text: '详情',
                            title: '用户详情',
                            classname: 'btn btn-xs btn-info btn-dialog',
                            icon: 'fa fa-eye',
                            url: 'fanshub/account/detail'
                        }, {
                            name: 'adjust',
                            text: '调账',
                            title: '人工调账',
                            classname: 'btn btn-xs btn-warning btn-dialog',
                            icon: 'fa fa-calculator',
                            url: 'fanshub/account/adjust'
                        }, {
                            name: 'chatforbid',
                            text: '禁言',
                            title: '聊天禁言',
                            classname: 'btn btn-xs btn-danger btn-dialog',
                            icon: 'fa fa-ban',
                            url: 'fanshub/account/chatforbid',
                            visible: function () {
                                return true;
                            }
                        }, {
                            name: 'ban',
                            text: '封禁',
                            title: '封禁登录',
                            classname: 'btn btn-xs btn-danger btn-ajax',
                            icon: 'fa fa-lock',
                            url: 'fanshub/account/ban',
                            confirm: '确认封禁该用户？将立即踢下线，且无法再登录。',
                            visible: function (row) {
                                return !(row.user && row.user.status === 'hidden');
                            },
                            success: function () {
                                table.bootstrapTable('refresh');
                            }
                        }, {
                            name: 'unban',
                            text: '解封',
                            title: '解除封禁',
                            classname: 'btn btn-xs btn-success btn-ajax',
                            icon: 'fa fa-unlock',
                            url: 'fanshub/account/ban',
                            confirm: '确认解除封禁？解除后用户可重新登录。',
                            visible: function (row) {
                                return !!(row.user && row.user.status === 'hidden');
                            },
                            success: function () {
                                table.bootstrapTable('refresh');
                            }
                        }, {
                            name: 'promotemaster',
                            text: '晋升团长',
                            title: '晋升团长',
                            classname: 'btn btn-xs btn-promote-master btn-ajax',
                            icon: 'fa fa-trophy',
                            url: 'fanshub/account/promotemaster',
                            confirm: '确认将该用户晋升为团长？\n用户态 → 团长\n荣誉段位 → 青铜团长',
                            visible: function (row) {
                                return !(row.user_mode === 'master' && parseInt(row.honor_tier_claimed, 10) >= 1);
                            },
                            success: function () {
                                table.bootstrapTable('refresh');
                            }
                        }, {
                            name: 'harddel',
                            text: '删除',
                            title: '真删除用户',
                            classname: 'btn btn-xs btn-danger btn-ajax',
                            icon: 'fa fa-trash',
                            url: 'fanshub/account/del',
                            confirm: hardDelConfirmOne,
                            visible: function () {
                                return !!Config.canHardDelete;
                            },
                            success: function () {
                                table.bootstrapTable('refresh');
                            }
                        }],
                        formatter: Table.api.formatter.operate
                    }
                ]]
            });
            Table.api.bindevent(table);
            $(document).off('click.fanshubCopy', '.btn-copy-cell').on('click.fanshubCopy', '.btn-copy-cell', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var text = String($(this).data('copy') || '').trim();
                if (!text) return;
                var done = function () {
                    Toastr.success('已复制：' + text);
                };
                var fail = function () {
                    window.prompt('复制以下内容', text);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(done).catch(fail);
                } else {
                    try {
                        var $t = $('<textarea readonly></textarea>').val(text).css({position: 'fixed', left: '-9999px'}).appendTo('body');
                        $t[0].select();
                        document.execCommand('copy');
                        $t.remove();
                        done();
                    } catch (err) {
                        fail();
                    }
                }
            });
            // 覆盖工具栏批量删除确认：强调真删除不可恢复
            var toolbar = $('#toolbar');
            toolbar.off('click', '.btn-del').on('click', '.btn-del', function () {
                var that = this;
                var ids = Table.api.selectedids(table);
                if (!ids.length) {
                    return false;
                }
                Layer.confirm(
                    hardDelConfirmBatch(ids.length),
                    {icon: 3, title: '真删除确认', offset: 0, shadeClose: true, btn: ['确认删除', '取消']},
                    function (index) {
                        Table.api.multi('del', ids, table, that);
                        Layer.close(index);
                    }
                );
                return false;
            });
            FanshubCommon.bindExport(table, $.fn.bootstrapTable.defaults.extend.export_url);
        },
        edit: function () {
            Controller.api.bindevent();
        },
        adjust: function () {
            Form.api.bindevent($("#adjust-form"));
        },
        chatforbid: function () {
            Form.api.bindevent($("#chatforbid-form"));
        },
        detail: function () {},
        api: {
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
            }
        }
    };
    return Controller;
});
