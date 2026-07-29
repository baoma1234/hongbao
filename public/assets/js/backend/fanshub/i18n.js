define(['jquery', 'bootstrap', 'backend', 'form'], function ($, undefined, Backend, Form) {
    var Controller = {
        index: function () {
            Form.api.bindevent($('#i18n-form'), function () {
                return true;
            });

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
