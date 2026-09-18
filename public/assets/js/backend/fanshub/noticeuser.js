define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var Controller = {
        index: function () {
            Table.api.init({
                extend: {
                    index_url: 'fanshub/noticeuser/index',
                    add_url: 'fanshub/noticeuser/add',
                    edit_url: 'fanshub/noticeuser/edit',
                    del_url: 'fanshub/noticeuser/del',
                    multi_url: 'fanshub/noticeuser/multi',
                    table: 'fans_notice'
                }
            });
            var table = $('#table');
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'publishtime',
                sortOrder: 'desc',
                columns: [[
                    {checkbox: true},
                    {field: 'id', title: 'ID'},
                    {field: 'author_name', title: '昵称', operate: 'LIKE'},
                    {
                        field: 'author_avatar', title: '头像', operate: false,
                        formatter: Table.api.formatter.image
                    },
                    {
                        field: 'category', title: '分类',
                        searchList: Config.categoryList || {},
                        formatter: Table.api.formatter.normal
                    },
                    {field: 'theme_title', title: '主题', operate: 'LIKE'},
                    {
                        field: 'content', title: '正文', operate: 'LIKE',
                        formatter: function (value) {
                            var t = String(value || '');
                            return t.length > 48 ? t.slice(0, 48) + '…' : t;
                        }
                    },
                    {field: 'views_count', title: '浏览', sortable: true, operate: 'BETWEEN'},
                    {field: 'user_id', title: '用户ID', sortable: true},
                    {
                        field: 'source', title: '来源',
                        searchList: {admin: '后台', user: '用户发帖'},
                        formatter: Table.api.formatter.normal
                    },
                    {field: 'weigh', title: '排序', sortable: true},
                    {
                        field: 'status', title: '状态',
                        searchList: Config.statusList || {draft: '草稿', published: '展示中', paused: '暂停展示', pending: '待审核', rejected: '已拒绝'},
                        formatter: function (value) {
                            var map = Config.statusList || {draft: '草稿', published: '展示中', paused: '暂停展示', pending: '待审核', rejected: '已拒绝'};
                            var label = map[value] || value;
                            var cls = 'default';
                            if (value === 'published') cls = 'success';
                            else if (value === 'paused' || value === 'pending') cls = 'warning';
                            else if (value === 'rejected') cls = 'danger';
                            return '<span class="label label-' + cls + '">' + label + '</span>';
                        }
                    },
                    {
                        field: 'publishtime', title: '发布时间', operate: 'RANGE', sortable: true,
                        addclass: 'datetimerange', formatter: Table.api.formatter.datetime
                    },
                    {
                        field: 'createtime', title: '创建时间', operate: 'RANGE', sortable: true,
                        addclass: 'datetimerange', formatter: Table.api.formatter.datetime, visible: false
                    },
                    {
                        field: 'operate', title: __('Operate'), table: table,
                        events: Table.api.events.operate, formatter: Table.api.formatter.operate
                    }
                ]]
            });
            Table.api.bindevent(table);
        },
        add: function () { Controller.api.bindevent(); },
        edit: function () { Controller.api.bindevent(); },
        api: {
            bindevent: function () {
                Form.api.bindevent($('form[role=form]'));
                Controller.api.bindThemeFilter();
            },
            bindThemeFilter: function () {
                var $cat = $('select[name="row[category]"]');
                var $theme = $('#c-theme_id');
                if (!$cat.length || !$theme.length) return;
                var meta = Config.themeMeta || [];
                var keep = String($theme.val() || '0');

                function rebuild(selectedCat, preferredId) {
                    var html = '<option value="0">无主题标签</option>';
                    for (var i = 0; i < meta.length; i++) {
                        var row = meta[i] || {};
                        if (String(row.category || '') !== String(selectedCat)) continue;
                        var label = String(row.title || '');
                        if (String(row.status || '') !== 'normal') label += ' [停用]';
                        html += '<option value="' + row.id + '">' + label + '</option>';
                    }
                    $theme.html(html);
                    var want = String(preferredId || '0');
                    if (want !== '0' && $theme.find('option[value="' + want + '"]').length) {
                        $theme.val(want);
                    } else {
                        $theme.val('0');
                    }
                    if ($theme.selectpicker) {
                        $theme.selectpicker('refresh');
                    }
                }

                rebuild(String($cat.val() || 'ads'), keep);
                $cat.on('changed.bs.select change', function () {
                    rebuild(String($cat.val() || 'ads'), '0');
                });
            }
        }
    };
    return Controller;
});
