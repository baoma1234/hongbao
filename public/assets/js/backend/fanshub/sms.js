define(['jquery', 'bootstrap', 'backend', 'form'], function ($, undefined, Backend, Form) {
    var Controller = {
        index: function () {
            Form.api.bindevent($("#sms-form"));

            $(document).on('click', '#btn-test-dagou-sms', function () {
                var mobile = $('#test-dagou-mobile').val().trim();
                if (!/^1\d{10}$/.test(mobile)) {
                    Toastr.error('请填写11位中国大陆手机号');
                    return;
                }
                var form = $('#sms-form');
                var postData = form.serializeArray();
                postData.push({name: 'mobile', value: mobile});
                Fast.api.ajax({
                    url: Fast.api.fixurl('fanshub.sms/testdagousms'),
                    type: 'POST',
                    data: postData
                });
            });

            $(document).on('click', '#btn-dagou-balance', function () {
                Fast.api.ajax({
                    url: Fast.api.fixurl('fanshub.sms/dagoubalance'),
                    type: 'POST',
                    data: $('#sms-form').serializeArray()
                }, function (data) {
                    if (data) {
                        var hint = '余额: ' + (data.balance || '-') + '，可用条数: ' + (data.items || '-') + '，已用: ' + (data.use_items || '-');
                        $('#dagou-balance-hint').text(hint);
                    }
                    return false;
                });
            });

            $(document).on('click', '#btn-test-una-sms', function () {
                var mobile = $('#test-una-mobile').val().trim();
                if (!/^\+?\d{8,15}$/.test(mobile.replace(/\s+/g, ''))) {
                    Toastr.error('请填写国际手机号（E.164，如 +639123456789）');
                    return;
                }
                var form = $('#sms-form');
                var postData = form.serializeArray();
                postData.push({name: 'mobile', value: mobile});
                Fast.api.ajax({
                    url: Fast.api.fixurl('fanshub.sms/testunisms'),
                    type: 'POST',
                    data: postData
                });
            });

            $(document).on('click', '#btn-una-balance', function () {
                Fast.api.ajax({
                    url: Fast.api.fixurl('fanshub.sms/unabalance'),
                    type: 'POST',
                    data: $('#sms-form').serializeArray()
                }, function (data) {
                    if (data) {
                        var hint = '余额: ' + (data.balance !== undefined ? data.balance : JSON.stringify(data));
                        $('#una-balance-hint').text(hint);
                    }
                    return false;
                });
            });
        }
    };
    return Controller;
});
