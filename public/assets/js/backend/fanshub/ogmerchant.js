define(['jquery', 'bootstrap', 'backend', 'form'], function ($, undefined, Backend, Form) {
    var Controller = {
        index: function () {
            Form.api.bindevent($('#og-form'));

            function bindTest($form, label) {
                Form.api.bindevent($form, function (data, ret) {
                    if (ret && ret.data) {
                        console.log('OG ' + label, ret.data);
                    }
                    return true;
                }, function (data, ret) {
                    if (ret && ret.data) {
                        console.log('OG ' + label + ' fail', ret.data);
                    }
                    return false;
                });
                $form.on('submit', function () {
                    var $test = $(this);
                    $('#og-form').serializeArray().forEach(function (item) {
                        if (!item || !item.name) return;
                        if ($test.find('[name="' + item.name + '"]').length) return;
                        $('<input type="hidden">').attr('name', item.name).val(item.value).appendTo($test);
                    });
                });
            }

            bindTest($('#og-test-register'), 'register');
            bindTest($('#og-test-deposit'), 'deposit');
            bindTest($('#og-test-withdraw'), 'withdraw');
            bindTest($('#og-test-history'), 'history');
        }
    };
    return Controller;
});
