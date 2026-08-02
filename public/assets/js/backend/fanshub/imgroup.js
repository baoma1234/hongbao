define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/imgroup/index',
                    add_url: 'fanshub/imgroup/add',
                    edit_url: 'fanshub/imgroup/edit',
                    del_url: 'fanshub/imgroup/del',
                    table: 'chat_groups'
                }
            });
            var table = $('#table');
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'weigh',
                sortOrder: 'desc',
                columns: [
                    [
                        {checkbox: true},
                        {field: 'id', title: 'ID', sortable: true},
                        {field: 'weigh', title: '排序', sortable: true, operate: false},
                        {field: 'name', title: '群名称', operate: 'LIKE'},
                        {field: 'owner_user_id', title: '群主ID'},
                        {field: 'owner_label', title: '群主', operate: false},
                        {field: 'admin_labels', title: '群管理员', operate: false},
                        {field: 'member_count', title: '人数', sortable: true},
                        {
                            field: 'privacy_mode',
                            title: '群属性',
                            searchList: {open: '开放群', private: '隐私群'},
                            formatter: function (v) {
                                return v === 'open' ? '🔓开放' : '🔒隐私';
                            }
                        },
                        {
                            field: 'is_recommend',
                            title: '推荐',
                            searchList: {1: '是', 0: '否'},
                            formatter: function (v) {
                                return parseInt(v, 10) === 1 ? '⭐推荐' : '-';
                            }
                        },
                        {
                            field: 'chat_mode',
                            title: '模式',
                            searchList: {chat: '聊天', grab: '抢红包'},
                            formatter: function (v) {
                                return v === 'grab' ? '抢红包' : '聊天';
                            }
                        },
                        {
                            field: 'is_vip_group',
                            title: 'VIP红包',
                            searchList: {0: '否', 1: '是'},
                            formatter: function (v) {
                                return parseInt(v, 10) === 1 ? 'VIP' : '-';
                            }
                        },
                        {
                            field: 'status',
                            title: '状态',
                            searchList: {1: '正常', 2: '解散', 3: '全员禁言'},
                            formatter: Table.api.formatter.normal
                        },
                        {
                            field: 'createtime',
                            title: '创建时间',
                            formatter: Table.api.formatter.datetime,
                            operate: 'RANGE',
                            addclass: 'datetimerange',
                            sortable: true
                        },
                        {
                            field: 'operate',
                            title: __('Operate'),
                            table: table,
                            events: Table.api.events.operate,
                            buttons: [
                                {
                                    name: 'members',
                                    text: '群成员',
                                    title: '查看群成员',
                                    classname: 'btn btn-xs btn-info btn-dialog',
                                    icon: 'fa fa-users',
                                    url: 'fanshub/imgroup/members',
                                    extend: 'data-area=\'["92%","92%"]\''
                                },
                                {
                                    name: 'invite',
                                    text: '添加成员',
                                    title: '添加群成员',
                                    classname: 'btn btn-xs btn-success btn-dialog',
                                    icon: 'fa fa-user-plus',
                                    url: 'fanshub/imgroup/invite',
                                    extend: 'data-area=\'["90%","90%"]\''
                                },
                                {
                                    name: 'muteall_on',
                                    text: '全员禁言',
                                    title: '开启全员禁言',
                                    classname: 'btn btn-xs btn-warning btn-ajax',
                                    icon: 'fa fa-bell-slash',
                                    url: 'fanshub/imgroup/muteall',
                                    confirm: '开启后普通成员无法发言，仅群主/管理员可发言，确认？',
                                    visible: function (row) {
                                        return parseInt(row.status, 10) === 1;
                                    },
                                    success: function () {
                                        table.bootstrapTable('refresh');
                                    }
                                },
                                {
                                    name: 'muteall_off',
                                    text: '取消禁言',
                                    title: '关闭全员禁言',
                                    classname: 'btn btn-xs btn-warning btn-ajax',
                                    icon: 'fa fa-volume-up',
                                    url: 'fanshub/imgroup/muteall',
                                    confirm: '确认关闭全员禁言？',
                                    visible: function (row) {
                                        return parseInt(row.status, 10) === 3;
                                    },
                                    success: function () {
                                        table.bootstrapTable('refresh');
                                    }
                                },
                                {
                                    name: 'harddel',
                                    text: '硬删除',
                                    title: '硬删除群组（不可恢复）',
                                    classname: 'btn btn-xs btn-danger btn-harddel-one',
                                    icon: 'fa fa-times-circle',
                                    visible: function () {
                                        return $('.toolbar .btn-harddel').length > 0 && !$('.toolbar .btn-harddel').hasClass('hide');
                                    }
                                }
                            ],
                            formatter: Table.api.formatter.operate
                        }
                    ]
                ]
            });
            Table.api.bindevent(table);

            var doHardDelete = function (ids) {
                ids = (ids || []).map(function (x) { return parseInt(x, 10) || 0; }).filter(function (x) { return x > 0; });
                if (!ids.length) {
                    Toastr.warning('请先选择群组');
                    return;
                }
                Layer.prompt({
                    title: '硬删除将永久清除群成员/消息/红包等，不可恢复。请输入 DELETE 确认',
                    formType: 0,
                    value: ''
                }, function (value, index) {
                    if (String(value || '').trim().toUpperCase() !== 'DELETE') {
                        Toastr.error('请输入 DELETE 才能继续');
                        return;
                    }
                    Layer.close(index);
                    Fast.api.ajax({
                        url: 'fanshub/imgroup/harddel',
                        data: {ids: ids.join(',')}
                    }, function () {
                        table.bootstrapTable('refresh');
                        return false;
                    });
                });
            };

            $('.btn-harddel').on('click', function () {
                var ids = Table.api.selectedids(table);
                doHardDelete(ids);
            });
            table.on('click', '.btn-harddel-one', function () {
                var row = table.bootstrapTable('getData') || [];
                var tr = $(this).closest('tr');
                var index = tr.data('index');
                var data = typeof index === 'number' ? table.bootstrapTable('getData')[index] : null;
                if (!data) {
                    // fallback: parse from operate area
                    var $tr = $(this).closest('tr');
                    data = $tr.data('index') != null ? table.bootstrapTable('getData')[$tr.data('index')] : null;
                }
                if (!data || !data.id) {
                    Toastr.warning('无法获取群ID');
                    return false;
                }
                doHardDelete([data.id]);
                return false;
            });
        },
        add: function () {
            Controller.api.bindevent();
        },
        edit: function () {
            Controller.api.bindevent();
        },
        members: function () {
            var groupId = (window.ImgroupMembersConfig && window.ImgroupMembersConfig.group_id) || 0;
            var table = $('#member-table');
            // 弹窗 URL 含 /members/ids/xx，相对路径会拼错；且须 server 模式才能解析 {total,rows}
            table.bootstrapTable({
                url: Fast.api.fixurl('fanshub/imgroup/members/ids/' + groupId),
                pk: 'user_id',
                sortName: 'role',
                commonSearch: false,
                search: false,
                pagination: false,
                sidePagination: 'server',
                queryParams: function (params) {
                    params.keyword = $('#member-keyword').val() || '';
                    return params;
                },
                columns: [[
                    {field: 'user_id', title: '会员ID', sortable: true},
                    {field: 'nickname', title: '昵称'},
                    {field: 'mobile', title: '手机号'},
                    {field: 'role_text', title: '角色'},
                    {
                        field: 'is_muted',
                        title: '禁言',
                        formatter: function (value, row) {
                            if (parseInt(value, 10) === 1) {
                                return '<span class="label label-danger">禁言至 ' + (row.mute_text || '') + '</span>';
                            }
                            return '<span class="text-muted">正常</span>';
                        }
                    },
                    {
                        field: 'jointime',
                        title: '入群时间',
                        formatter: Table.api.formatter.datetime
                    },
                    {
                        field: 'operate',
                        title: '操作',
                        formatter: function (value, row) {
                            if (parseInt(row.role, 10) === 3) {
                                return '<span class="text-muted">群主</span>';
                            }
                            var html = '';
                            html += '<a href="javascript:;" class="btn btn-xs btn-danger btn-kick" data-uid="' + row.user_id + '"><i class="fa fa-user-times"></i> 踢出</a> ';
                            if (parseInt(row.is_muted, 10) === 1) {
                                html += '<a href="javascript:;" class="btn btn-xs btn-default btn-unmute" data-uid="' + row.user_id + '"><i class="fa fa-volume-up"></i> 取消禁言</a> ';
                            } else {
                                html += '<div class="btn-group">' +
                                    '<button type="button" class="btn btn-xs btn-warning dropdown-toggle" data-toggle="dropdown">' +
                                    '<i class="fa fa-microphone-slash"></i> 禁言 <span class="caret"></span></button>' +
                                    '<ul class="dropdown-menu pull-right">' +
                                    '<li><a href="javascript:;" class="btn-mute" data-uid="' + row.user_id + '" data-sec="600">10 分钟</a></li>' +
                                    '<li><a href="javascript:;" class="btn-mute" data-uid="' + row.user_id + '" data-sec="3600">1 小时</a></li>' +
                                    '<li><a href="javascript:;" class="btn-mute" data-uid="' + row.user_id + '" data-sec="86400">24 小时</a></li>' +
                                    '</ul></div>';
                            }
                            return html;
                        }
                    }
                ]]
            });

            $('#btn-member-search').on('click', function () {
                table.bootstrapTable('refresh');
            });
            $('#member-keyword').on('keydown', function (e) {
                if (e.keyCode === 13) {
                    e.preventDefault();
                    table.bootstrapTable('refresh');
                }
            });

            $(document).on('click', '.btn-kick', function () {
                var uid = $(this).data('uid');
                Layer.confirm('确定将该成员移出群组？', function (index) {
                    Fast.api.ajax({
                        url: 'fanshub/imgroup/kick',
                        data: {group_id: groupId, user_id: uid}
                    }, function () {
                        table.bootstrapTable('refresh');
                        Layer.close(index);
                        return false;
                    });
                });
            });
            $(document).on('click', '.btn-mute', function () {
                var uid = $(this).data('uid');
                var sec = parseInt($(this).data('sec'), 10) || 0;
                Fast.api.ajax({
                    url: 'fanshub/imgroup/mute',
                    data: {group_id: groupId, user_id: uid, seconds: sec}
                }, function () {
                    table.bootstrapTable('refresh');
                    return false;
                });
            });
            $(document).on('click', '.btn-unmute', function () {
                var uid = $(this).data('uid');
                Fast.api.ajax({
                    url: 'fanshub/imgroup/mute',
                    data: {group_id: groupId, user_id: uid, seconds: 0}
                }, function () {
                    table.bootstrapTable('refresh');
                    return false;
                });
            });
            $(document).on('click', '.btn-muteall', function () {
                var enabled = parseInt($(this).data('enabled'), 10) ? 1 : 0;
                var tip = enabled ? '开启后普通成员无法发言，仅管理员可发言，确认？' : '确认关闭全员禁言？';
                Layer.confirm(tip, function (index) {
                    Fast.api.ajax({
                        url: 'fanshub/imgroup/muteall',
                        data: {group_id: groupId, enabled: enabled}
                    }, function () {
                        Layer.close(index);
                        location.reload();
                        return false;
                    });
                });
            });
        },
        invite: function () {
            var groupId = (window.ImgroupInviteConfig && window.ImgroupInviteConfig.group_id) || 0;
            var table = $('#invite-table');
            var updateCount = function () {
                var rows = table.bootstrapTable('getSelections') || [];
                var n = rows.length;
                $('#invite-selected-count').text(n);
                $('#btn-invite-confirm').prop('disabled', n <= 0);
            };
            table.bootstrapTable({
                url: Fast.api.fixurl('fanshub/imgroup/candidates'),
                pk: 'user_id',
                sortName: 'id',
                commonSearch: false,
                search: false,
                sidePagination: 'server',
                pageSize: 50,
                queryParams: function (params) {
                    params.group_id = groupId;
                    params.keyword = $('#invite-keyword').val() || '';
                    return params;
                },
                columns: [[
                    {checkbox: true},
                    {field: 'user_id', title: '会员ID', sortable: true},
                    {field: 'nickname', title: '昵称'},
                    {field: 'username', title: '用户名'},
                    {field: 'mobile', title: '手机号'}
                ]],
                onCheck: updateCount,
                onUncheck: updateCount,
                onCheckAll: updateCount,
                onUncheckAll: updateCount,
                onLoadSuccess: updateCount
            });

            $('#btn-invite-search').on('click', function () {
                table.bootstrapTable('refresh', {pageNumber: 1});
            });
            $('#invite-keyword').on('keydown', function (e) {
                if (e.keyCode === 13) {
                    e.preventDefault();
                    table.bootstrapTable('refresh', {pageNumber: 1});
                }
            });
            $('#btn-invite-confirm').on('click', function () {
                var rows = table.bootstrapTable('getSelections') || [];
                var ids = rows.map(function (r) { return r.user_id; });
                if (!ids.length) {
                    Toastr.warning('请先选择用户');
                    return;
                }
                Layer.confirm('确认将选中的 ' + ids.length + ' 人加入群组？', function (index) {
                    Fast.api.ajax({
                        url: 'fanshub/imgroup/addmembers',
                        data: {group_id: groupId, user_ids: ids.join(',')}
                    }, function () {
                        Layer.close(index);
                        table.bootstrapTable('refresh');
                        // 刷新父层成员表
                        try {
                            parent.$('#member-table').bootstrapTable('refresh');
                            parent.$('#table').bootstrapTable('refresh');
                        } catch (e) {}
                        return false;
                    });
                });
            });
        },
        api: {
            bindevent: function () {
                Form.api.bindevent($('form[role=form]'));
            }
        }
    };
    return Controller;
});
