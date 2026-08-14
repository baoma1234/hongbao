define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {
    var statusMap = {0: '草稿', 1: '进行中', 2: '开奖成功', 3: '超时作废'};

    function fmt(ts) {
        if (!ts) return '-';
        var d = new Date(parseInt(ts, 10) * 1000);
        return isNaN(d.getTime()) ? '-' : d.toLocaleString();
    }

    function render(rows) {
        var html = '';
        if (!rows || !rows.length) {
            html = '<tr><td colspan="8">暂无活动</td></tr>';
        } else {
            $.each(rows, function (_, r) {
                var ops = '<a href="javascript:;" class="btn btn-xs btn-primary btn-edit" data-id="' + r.id + '">编辑</a>';
                if (parseInt(r.status, 10) === 1) {
                    ops += ' <a href="javascript:;" class="btn btn-xs btn-danger btn-force-settle" data-id="' + r.id + '">一键开奖</a>';
                }
                if (parseInt(r.status, 10) === 1 || parseInt(r.status, 10) === 2) {
                    ops += ' <a href="javascript:;" class="btn btn-xs btn-info btn-addqual-row" data-id="' + r.id + '">加份</a>';
                }
                html += '<tr>'
                    + '<td>' + r.id + '</td>'
                    + '<td>' + (r.title || '') + '</td>'
                    + '<td>¥' + r.pool_amount + '</td>'
                    + '<td>' + r.global_quals + ' / ' + r.global_cap + '</td>'
                    + '<td>' + r.user_cap + '</td>'
                    + '<td>' + (statusMap[r.status] || r.status) + '</td>'
                    + '<td>' + fmt(r.start_time) + ' ~ ' + fmt(r.end_time) + '</td>'
                    + '<td>' + ops + '</td>'
                    + '</tr>';
            });
        }
        $('#fission-rows').html(html);
    }

    function load() {
        $.ajax({
            url: Fast.api.fixurl('fanshub/fission/index'),
            dataType: 'json',
            data: {ajax: 1},
            success: function (res) {
                render((res && res.rows) ? res.rows : []);
            },
            error: function () {
                $('#fission-rows').html('<tr><td colspan="8">加载失败，请刷新重试</td></tr>');
            }
        });
    }

    function openEdit(id) {
        Fast.api.open('fanshub/fission/edit/ids/' + id, '编辑裂变活动 #' + id, {
            callback: function () {
                load();
            }
        });
    }

    function forceSettle(id) {
        Layer.confirm(
            '确认一键开奖？将把进度拉满到上限，并按已有资格立即派奖。此操作不可撤销。',
            {icon: 3, title: '一键开奖 #' + id},
            function (idx) {
                Layer.close(idx);
                Fast.api.ajax({
                    url: 'fanshub/fission/forcesettle',
                    data: {ids: id}
                }, function () {
                    load();
                    return false;
                });
            }
        );
    }

    function openAddQual(id) {
        var url = 'fanshub/fission/addqual' + (id ? '/ids/' + id : '');
        Fast.api.open(url, '给用户加份' + (id ? ' #' + id : ''), {
            callback: function () {
                load();
            }
        });
    }

    var Controller = {
        index: function () {
            $('.btn-start').on('click', function () {
                Layer.confirm('按默认配置开启新一轮？（1000元 / 100份 / 72h / 单人5）', function (idx) {
                    Layer.close(idx);
                    Fast.api.ajax({
                        url: 'fanshub/fission/start',
                        data: {}
                    }, function () {
                        load();
                        return false;
                    });
                });
            });
            $('.btn-addqual').on('click', function () {
                openAddQual(0);
            });
            $('.btn-maintain').on('click', function () {
                Fast.api.ajax({url: 'fanshub/fission/maintain'}, function () {
                    load();
                    return false;
                });
            });
            $(document).on('click', '#fission-rows .btn-edit', function () {
                openEdit($(this).data('id'));
            });
            $(document).on('click', '#fission-rows .btn-force-settle', function () {
                forceSettle($(this).data('id'));
            });
            $(document).on('click', '#fission-rows .btn-addqual-row', function () {
                openAddQual($(this).data('id'));
            });
            load();
        },
        start: function () {
            Form.api.bindevent($('#start-form'), function () {
                Fast.api.close();
                return false;
            });
        },
        edit: function () {
            Form.api.bindevent($('#edit-form'), function () {
                Fast.api.close();
                return false;
            });
        },
        addqual: function () {
            Form.api.bindevent($('#addqual-form'), function () {
                Fast.api.close();
                return false;
            });
        }
    };
    return Controller;
});
