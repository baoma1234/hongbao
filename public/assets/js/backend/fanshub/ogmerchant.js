define(['jquery', 'bootstrap', 'backend', 'form'], function ($, undefined, Backend, Form) {
    var Controller = {
        index: function () {
            Form.api.bindevent($('#og-form'));
            Form.api.bindevent($('#og-test-register'), function (data, ret) {
                // 成功后把请求/响应用 toast 附带展示关键
                if (ret && ret.data) {
                    console.log('OG register', ret.data);
                }
                return true;
            }, function (data, ret) {
                if (ret && ret.data) {
                    console.log('OG register fail', ret.data);
                }
                return false;
            });
            // 测试注册时带上当前商户表单字段（未保存也可试）
            $('#og-test-register').on('submit', function () {
                var $test = $(this);
                $('#og-form').serializeArray().forEach(function (item) {
                    if (!item || !item.name) return;
                    if ($test.find('[name="' + item.name + '"]').length) return;
                    $('<input type="hidden">').attr('name', item.name).val(item.value).appendTo($test);
                });
            });
        }
    };
    return Controller;
});
