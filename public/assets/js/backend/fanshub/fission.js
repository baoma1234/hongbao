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
            html = '<tr><td colspan="7">暂无活动</td></tr>';
        } else {
            $.each(rows, function (_, r) {
                html += '<tr>'
                    + '<td>' + r.id + '</td>'
                    + '<td>' + (r.title || '') + '</td>'
                    + '<td>¥' + r.pool_amount + '</td>'
                    + '<td>' + r.global_quals + ' / ' + r.global_cap + '</td>'
                    + '<td>' + r.user_cap + '</td>'
                    + '<td>' + (statusMap[r.status] || r.status) + '</td>'
                    + '<td>' + fmt(r.start_time) + ' ~ ' + fmt(r.end_time) + '</td>'
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
                $('#fission-rows').html('<tr><td colspan="7">加载失败，请刷新重试</td></tr>');
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
            $('.btn-maintain').on('click', function () {
                Fast.api.ajax({url: 'fanshub/fission/maintain'}, function () {
                    load();
                    return false;
                });
            });
            load();
        },
        start: function () {
            Form.api.bindevent($('#start-form'), function () {
                Fast.api.close();
                return false;
            });
        }
    };
    return Controller;
});
