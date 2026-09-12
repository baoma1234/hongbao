define(['jquery', 'bootstrap', 'backend', 'form'], function ($, undefined, Backend, Form) {
    var MAX_PREVIEW = 5;

    function absUrl(u) {
        u = $.trim(u || '');
        if (!u) return '';
        if (/^https?:\/\//i.test(u) || u.indexOf('data:') === 0) return u;
        if (u.charAt(0) === '/') {
            try {
                if (typeof Fast !== 'undefined' && Fast.api && Fast.api.cdnurl) {
                    return Fast.api.cdnurl(u, true) || (location.origin + u);
                }
            } catch (e) {}
            return location.origin + u;
        }
        return u;
    }

    function parsePreviewList() {
        var raw = $.trim($('#preview_urls').val() || '');
        if (!raw) return [];
        var parts = raw.split(/[\r\n,]+/);
        var out = [];
        var seen = {};
        for (var i = 0; i < parts.length; i++) {
            var u = $.trim(parts[i] || '');
            if (!u) continue;
            var full = absUrl(u);
            if (!full || seen[full]) continue;
            seen[full] = 1;
            out.push(full);
            if (out.length >= MAX_PREVIEW) break;
        }
        return out;
    }

    function syncPreviewInput(list) {
        var lim = (list || []).slice(0, MAX_PREVIEW);
        $('#preview_urls').val(lim.join(','));
        renderThumbs(lim);
    }

    function renderThumbs(list) {
        var $box = $('#preview-thumbs');
        if (!$box.length) return;
        list = list || parsePreviewList();
        if (!list.length) {
            $box.empty();
            return;
        }
        var html = '';
        for (var i = 0; i < list.length; i++) {
            var u = list[i];
            html += '<div class="videosend-thumb" data-idx="' + i + '">'
                + '<img src="' + $('<div/>').text(u).html() + '" alt="">'
                + '<a href="javascript:;" class="videosend-thumb-del" title="移除" data-idx="' + i + '">&times;</a>'
                + '</div>';
        }
        $box.html(html);
    }

    function openPhotos(start) {
        var list = parsePreviewList();
        if (!list.length) return;
        var data = [];
        for (var i = 0; i < list.length; i++) {
            data.push({ src: list[i], thumb: list[i] });
        }
        var idx = Math.max(0, Math.min(list.length - 1, start | 0));
        try {
            Layer.photos({
                photos: { title: '预览图', data: data, start: idx },
                anim: 5,
                shade: 0.5
            });
        } catch (e) {
            window.open(list[idx], '_blank');
        }
    }

    var Controller = {
        index: function () {
            Form.api.bindevent($('#videosend-form'));

            function syncTarget() {
                var t = parseInt($('input[name="conversation_type"]:checked').val(), 10) || 2;
                if (t === 2) {
                    $('#row-group').show();
                    $('#row-peer').hide();
                } else {
                    $('#row-group').hide();
                    $('#row-peer').show();
                }
            }
            $('input[name="conversation_type"]').on('change', syncTarget);
            syncTarget();

            // 上传/图库选择后：限制 5 张并刷新缩略图
            $('#preview_urls').on('change input', function () {
                var list = parsePreviewList();
                if (list.length > MAX_PREVIEW) {
                    Toastr.warning('预览图最多 ' + MAX_PREVIEW + ' 张');
                }
                syncPreviewInput(list);
            });
            renderThumbs();

            // 点缩略图浏览；点 × 移除
            $('#preview-thumbs').on('click', '.videosend-thumb-del', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var idx = parseInt($(this).data('idx'), 10) || 0;
                var list = parsePreviewList();
                list.splice(idx, 1);
                syncPreviewInput(list);
            }).on('click', '.videosend-thumb', function (e) {
                if ($(e.target).closest('.videosend-thumb-del').length) return;
                openPhotos(parseInt($(this).data('idx'), 10) || 0);
            });

            // faupload 预览区点击也可放大（代理）
            $('#p-preview-imgs').on('click', 'li img, img', function (e) {
                e.preventDefault();
                var src = absUrl($(this).attr('src') || '');
                var list = parsePreviewList();
                var idx = 0;
                for (var i = 0; i < list.length; i++) {
                    if (list[i] === src || list[i].indexOf(src) >= 0 || src.indexOf(list[i]) >= 0) {
                        idx = i;
                        break;
                    }
                }
                openPhotos(idx);
            });

            $('#videosend-form').on('submit', function (e) {
                e.preventDefault();
                var agent = parseInt($('#agent_user_id').val(), 10) || 0;
                var ctype = parseInt($('input[name="conversation_type"]:checked').val(), 10) || 2;
                var url = $.trim($('#video_url').val() || '');
                var thumb = absUrl($('#thumb_url').val() || '');
                var content = $.trim($('#content').val() || '');
                var images = parsePreviewList();
                if (!agent) {
                    Toastr.error('请选择托管账号');
                    return false;
                }
                if (!url) {
                    Toastr.error('请填写视频地址');
                    return false;
                }
                if (!/^https?:\/\//i.test(url)) {
                    Toastr.error('视频地址须以 http:// 或 https:// 开头');
                    return false;
                }

                var extra = { url: url, fullurl: url };
                if (thumb) {
                    extra.thumb = thumb;
                    extra.poster = thumb;
                }
                if (content && content !== '[视频]') {
                    extra.caption = content;
                }
                if (images.length) {
                    extra.images = images.map(function (u) {
                        return { url: u, fullurl: u };
                    });
                    extra.count = images.length;
                    extra.image_urls = images.slice();
                    extra.image_fullurls = images.slice();
                    if (!thumb) {
                        extra.thumb = images[0];
                        extra.poster = images[0];
                    }
                }

                var data = {
                    agent_user_id: agent,
                    conversation_type: ctype,
                    msg_type: 5,
                    content: content || '[视频]',
                    preview_urls: images.join('\n'),
                    video_url: url,
                    thumb_url: thumb,
                    extra: JSON.stringify(extra)
                };
                if (ctype === 2) {
                    var gid = parseInt($('#group_id_manual').val(), 10) || 0;
                    if (!gid) {
                        gid = parseInt($('#group_id').val(), 10) || 0;
                    }
                    if (!gid) {
                        Toastr.error('请选择或填写群 ID');
                        return false;
                    }
                    data.group_id = gid;
                } else {
                    var peer = parseInt($('#to_user_id').val(), 10) || 0;
                    if (!peer) {
                        Toastr.error('请填写对方用户 ID');
                        return false;
                    }
                    data.to_user_id = peer;
                }

                var $btn = $('#btn-send').prop('disabled', true);
                $('#send-result').text('发送中…');
                Fast.api.ajax({
                    url: 'fanshub/videosend/send',
                    data: data
                }, function (data, ret) {
                    $('#send-result').text(ret.msg || '已发送');
                    Toastr.success(ret.msg || '已发送');
                    $btn.prop('disabled', false);
                    return false;
                }, function (data, ret) {
                    $('#send-result').text(ret.msg || '发送失败');
                    $btn.prop('disabled', false);
                });
                return false;
            });
        }
    };
    return Controller;
});
