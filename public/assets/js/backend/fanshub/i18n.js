define(['jquery', 'bootstrap', 'backend', 'form'], function ($, undefined, Backend, Form) {
    var Controller = {
        index: function () {
            var $form = $('#i18n-form');
            if ($form.length) {
                $form.off('submit.i18nJson').on('submit.i18nJson', function (e) {
                    e.preventDefault();
                    var payload = {};
                    $('#i18n-table .i18n-cell').each(function () {
                        var $el = $(this);
                        var locale = String($el.attr('data-locale') || '').trim();
                        var key = String($el.attr('data-key') || '').trim();
                        if (!locale || !key) return;
                        if (!payload[locale]) payload[locale] = {};
                        payload[locale][key] = $el.val();
                    });
                    if (!Object.keys(payload).length) {
                        Toastr.error('没有可保存的文案');
                        return false;
                    }
                    Fast.api.ajax({
                        url: $form.attr('action') || Fast.api.fixurl('fanshub.config/savei18n'),
                        type: 'POST',
                        data: {
                            i18n_json: JSON.stringify(payload),
                            __token__: $('input[name="__token__"]').val() || ''
                        }
                    }, function () {
                        return true;
                    });
                    return false;
                });
            }

            $('#i18n-search').on('input', function () {
                var q = ($(this).val() || '').toLowerCase().trim();
                $('#i18n-table .i18n-data-row').each(function () {
                    var hay = ($(this).attr('data-search') || '').toLowerCase();
                    var cells = '';
                    $(this).find('.i18n-cell').each(function () {
                        cells += ($(this).val() || '').toLowerCase();
                    });
                    var hit = !q || hay.indexOf(q) >= 0 || cells.indexOf(q) >= 0;
                    $(this).toggle(hit);
                });
                $('#i18n-table .i18n-group-row').each(function () {
                    var $next = $(this);
                    var visible = false;
                    while (true) {
                        $next = $next.next();
                        if (!$next.length || $next.hasClass('i18n-group-row')) break;
                        if ($next.is(':visible')) {
                            visible = true;
                            break;
                        }
                    }
                    $(this).toggle(visible);
                });
            });
        }
    };
    return Controller;
});
