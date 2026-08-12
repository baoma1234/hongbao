define(['jquery', 'bootstrap', 'backend', 'form'], function ($, undefined, Backend, Form) {
    var Controller = {
        index: function () {
            Form.api.bindevent($('#ga-form'));

            function applyPreview(data) {
                if (!data) {
                    return;
                }
                $('#ga-code').text(data.current_code || '----');
                if (typeof data.remain !== 'undefined') {
                    $('#ga-remain').text('剩余 ' + data.remain + 's');
                }
                if (data.qr_url) {
                    $('#ga-qr').attr('src', data.qr_url).show();
                    $('#ga-qr-tip').hide();
                }
                if (data.secret) {
                    $('#c-secret').val(data.secret);
                }
            }

            $(document).on('click', '#btn-generate', function () {
                Fast.api.ajax({
                    url: 'fanshub/googleauth/generate',
                    type: 'POST',
                    data: {
                        issuer: $('#c-issuer').val(),
                        __token__: $('input[name="__token__"]').val() || ''
                    }
                }, function (data) {
                    applyPreview(data);
                    Toastr.success('密钥已生成并保存，请扫码绑定');
                    return false;
                });
            });

            $(document).on('click', '#btn-preview', function () {
                Fast.api.ajax({
                    url: 'fanshub/googleauth/preview',
                    type: 'POST',
                    data: {
                        secret: $('#c-secret').val(),
                        issuer: $('#c-issuer').val(),
                        __token__: $('input[name="__token__"]').val() || ''
                    }
                }, function (data) {
                    applyPreview(data);
                    return false;
                });
            });

            if ($('#c-secret').val()) {
                setInterval(function () {
                    var rem = 30 - (Math.floor(Date.now() / 1000) % 30);
                    $('#ga-remain').text('剩余 ' + rem + 's');
                    if (rem === 30) {
                        $('#btn-preview').trigger('click');
                    }
                }, 1000);
            }
        }
    };
    return Controller;
});
