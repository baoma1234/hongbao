define(['jquery', 'bootstrap', 'backend'], function ($, undefined, Backend) {
    var Controller = {
        index: function () {
            var pollTimer = null;
            var listPollTimer = null;

            function refreshCount() {
                $.ajax({
                    url: 'fanshub/index/onlinecount',
                    dataType: 'json',
                    cache: false
                }).done(function (ret) {
                    if (ret && ret.code === 1 && ret.data) {
                        $('#online-real-count').text(ret.data.online_real | 0);
                    }
                });
            }

            function startSlowPoll() {
                if (pollTimer) clearInterval(pollTimer);
                pollTimer = setInterval(refreshCount, 20000);
            }

            function openOnlineList() {
                var page = 1;
                var limit = 50;

                function fmtTime(ts) {
                    ts = parseInt(ts, 10) || 0;
                    if (ts <= 0) return '-';
                    var d = new Date(ts * 1000);
                    var p = function (n) { return n < 10 ? '0' + n : '' + n; };
                    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate())
                        + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
                }

                function render(data) {
                    var total = (data && data.total) | 0;
                    var list = (data && data.list) || [];
                    var bot = (data && data.bot_online) | 0;
                    var raw = (data && data.raw_online) | 0;
                    $('.online-list-meta').html(
                        '真实在线 <b>' + total + '</b> 人'
                        + ' <span class="text-muted">（连接总数 ' + raw + '，其中机器人 ' + bot + '）</span>'
                    );
                    var html = '';
                    if (!list.length) {
                        html = '<tr><td colspan="4" class="text-center text-muted">当前无真实在线用户</td></tr>';
                    } else {
                        for (var i = 0; i < list.length; i++) {
                            var u = list[i] || {};
                            html += '<tr>'
                                + '<td>' + (u.user_id | 0) + '</td>'
                                + '<td>' + $('<div/>').text(u.nickname || '').html() + '</td>'
                                + '<td>' + $('<div/>').text(u.mobile || '').html() + '</td>'
                                + '<td>' + fmtTime(u.logintime) + '</td>'
                                + '</tr>';
                        }
                    }
                    $('.online-list-tbody').html(html);

                    var pages = Math.max(1, Math.ceil(total / limit));
                    var pager = '';
                    if (pages > 1) {
                        pager += '<button type="button" class="btn btn-default btn-xs online-prev"' + (page <= 1 ? ' disabled' : '') + '>上一页</button> ';
                        pager += '<span class="text-muted"> ' + page + ' / ' + pages + ' </span>';
                        pager += ' <button type="button" class="btn btn-default btn-xs online-next"' + (page >= pages ? ' disabled' : '') + '>下一页</button>';
                    }
                    $('.online-list-pager').html(pager);
                    $('.online-prev').off('click').on('click', function () {
                        if (page > 1) {
                            page -= 1;
                            load();
                        }
                    });
                    $('.online-next').off('click').on('click', function () {
                        if (page < pages) {
                            page += 1;
                            load();
                        }
                    });
                }

                function load() {
                    $.ajax({
                        url: 'fanshub/index/onlinelist',
                        data: { page: page, limit: limit },
                        dataType: 'json',
                        cache: false
                    }).done(function (ret) {
                        if (ret && ret.code === 1) {
                            render(ret.data || {});
                            if (ret.data && typeof ret.data.total !== 'undefined') {
                                $('#online-real-count').text(ret.data.total | 0);
                            }
                        } else {
                            $('.online-list-tbody').html(
                                '<tr><td colspan="4" class="text-center text-danger">'
                                + ((ret && ret.msg) || '加载失败') + '</td></tr>'
                            );
                        }
                    }).fail(function () {
                        $('.online-list-tbody').html(
                            '<tr><td colspan="4" class="text-center text-danger">网络错误</td></tr>'
                        );
                    });
                }

                Layer.open({
                    type: 1,
                    title: '实时在线用户（真实 · 已排除机器人）',
                    area: ['720px', '560px'],
                    shadeClose: true,
                    content: '<div class="online-list-wrap" style="padding:12px;">'
                        + '<div class="online-list-meta text-muted" style="margin-bottom:8px;">加载中…</div>'
                        + '<div class="table-responsive" style="max-height:420px;overflow:auto;">'
                        + '<table class="table table-striped table-bordered" style="margin:0;">'
                        + '<thead><tr><th>UID</th><th>昵称</th><th>手机</th><th>最近登录</th></tr></thead>'
                        + '<tbody class="online-list-tbody"><tr><td colspan="4" class="text-center">加载中…</td></tr></tbody>'
                        + '</table></div>'
                        + '<div class="online-list-pager" style="margin-top:10px;text-align:right;"></div>'
                        + '</div>',
                    success: function () {
                        load();
                        if (listPollTimer) clearInterval(listPollTimer);
                        listPollTimer = setInterval(load, 8000);
                    },
                    end: function () {
                        if (listPollTimer) {
                            clearInterval(listPollTimer);
                            listPollTimer = null;
                        }
                        startSlowPoll();
                    }
                });
            }

            $(document).on('click', '.btn-online-list', function (e) {
                e.preventDefault();
                openOnlineList();
            });

            startSlowPoll();
            setTimeout(refreshCount, 1500);
        }
    };
    return Controller;
});
