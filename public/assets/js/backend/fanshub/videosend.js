define(['jquery', 'bootstrap', 'backend', 'form'], function ($, undefined, Backend, Form) {
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

            $('#videosend-form').on('submit', function (e) {
                e.preventDefault();
                var agent = parseInt($('#agent_user_id').val(), 10) || 0;
                var ctype = parseInt($('input[name="conversation_type"]:checked').val(), 10) || 2;
                var url = $.trim($('#video_url').val() || '');
                var thumb = $.trim($('#thumb_url').val() || '');
                var content = $.trim($('#content').val() || '');
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

                var data = {
                    agent_user_id: agent,
                    conversation_type: ctype,
                    msg_type: 5,
                    content: content || '[视频]',
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
