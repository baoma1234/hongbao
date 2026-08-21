define(['jquery', 'bootstrap', 'backend', 'form'], function ($, undefined, Backend, Form) {
    var fieldSectionMap = {
        single_ticket_value: 'basic',
        withdraw_threshold: 'basic',
        max_vote_percent: 'basic',
        register_rights: 'basic',
        share_rights: 'basic',
        open_account_rights: 'basic',
        secret_lock_seconds: 'basic',
        customer_service_url: 'basic',
        app_download_url: 'basic',
        main_station_url: 'basic',
        h5_entry_path: 'basic',
        default_locale: 'basic',
        locale_auto_detect: 'basic',
        invite_base_url: 'invite',
        invite_code_offset: 'invite',
        share_text: 'invite',
        marquee_text: 'invite',
        invite_ip_limit_enabled: 'invite',
        share_daily_max: 'invite',
        share_cooldown_seconds: 'invite',
        jackpot_base: 'market',
        jackpot_ceiling: 'market',
        jackpot_auto_grow: 'market',
        jackpot_server_sync: 'market',
        market_virtual_base: 'market',
        market_seed_capital: 'market',
        api_sign_enabled: 'security',
        api_sign_secret: 'security',
        device_fp_limit_enabled: 'security',
        main_uid_verify_enabled: 'security',
        main_uid_verify_url: 'security'
    };

    function bindForm() {
        Form.api.bindevent($('#config-form'));
    }

    function bindChecklist() {
        function renderChecklist(data) {
            var panel = $('#production-checklist-panel');
            if (!panel.length) return;
            var levelMap = {success: 'success', warning: 'warning', danger: 'danger'};
            panel.removeClass('panel-success panel-warning panel-danger').addClass('panel-' + (levelMap[data.level] || 'warning'));
            panel.find('.panel-lead .text-muted').text(data.summary || '');
            panel.find('.label-success').text('閫氳繃 ' + (data.counts.ok || 0));
            panel.find('.label-warning').text('寤鸿 ' + (data.counts.warn || 0));
            panel.find('.label-danger').text('蹇呴』淇 ' + (data.counts.fail || 0));
            var rows = [];
            (data.items || []).forEach(function (item) {
                var label = item.status === 'ok' ? 'label-success' : (item.status === 'warn' ? 'label-warning' : 'label-danger');
                var text = item.status === 'ok' ? '閫氳繃' : (item.status === 'warn' ? '寤鸿' : '蹇呴』淇');
                rows.push('<tr data-field="' + (item.field || '') + '"><td><span class="label ' + label + '">' + text + '</span></td><td>' + Fast.api.escape(item.title || '') + '</td><td>' + Fast.api.escape(item.message || '') + '</td></tr>');
            });
            $('#production-checklist-body').html(rows.join(''));
        }

        $(document).off('click.configChecklist').on('click.configChecklist', '#btn-refresh-checklist', function () {
                Fast.api.ajax({url: Fast.api.fixurl('fanshub.config/checklist')}, function (data) {
                renderChecklist(data);
                return false;
            });
        });

        $(document).off('click.configChecklistRow').on('click.configChecklistRow', '#production-checklist-body tr[data-field]', function () {
            var field = $(this).data('field');
            if (!field) return;
            if (String(field).indexOf('sms_') === 0) {
                Backend.api.addtabs('fanshub.sms');
                return;
            }
            var input = $('[name="' + field + '"]');
            if (input.length) {
                $('html, body').animate({scrollTop: input.first().closest('.form-group').offset().top - 80}, 200);
                input.first().focus();
                return;
            }
            var section = fieldSectionMap[field];
            if (section) {
                Backend.api.addtabs('fanshub.config/' + section);
            }
        });
    }

    function bindUidTest() {
        $(document).off('click.configUidTest').on('click.configUidTest', '#btn-test-uid-verify', function () {
            var mainUid = $('#test-main-uid').val().trim();
            if (!mainUid) {
                Toastr.error('璇峰～鍐欐祴璇?UID');
                return;
            }
            var form = $('#config-form');
            var postData = form.serializeArray();
            postData.push({name: 'main_uid', value: mainUid});
            postData.push({name: 'mobile', value: $('#test-main-mobile').val().trim()});
            Fast.api.ajax({
                    url: Fast.api.fixurl('fanshub.config/testuidverify'),
                type: 'POST',
                data: postData
            });
        });
    }

    function bindJackpotReset() {
        $(document).off('click.configJackpot').on('click.configJackpot', '#btn-reset-jackpot', function () {
            Layer.confirm('纭畾灏嗘湇鍔＄澶х洏閲戦閲嶇疆涓哄綋鍓嶃€屽ぇ濂栨睜鍩烘暟銆嶏紵', function (index) {
                Layer.close(index);
                Fast.api.ajax({
                    url: Fast.api.fixurl('fanshub.config/resetjackpot'),
                    type: 'POST'
                }, function (data) {
                    if (data && data.amount !== undefined) {
                        $('#jackpot-current-display').text(data.amount);
                    }
                    return false;
                });
            });
        });
    }

    function bindI18n() {
        var $form = $('#i18n-form');
        if (!$form.length) return;

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

        $('#i18n-search').off('input.i18nFilter').on('input.i18nFilter', function () {
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

    var Controller = {
        index: function () {},
        basic: function () {
            bindForm();
            bindChecklist();
        },
        exchange: function () {
            bindForm();
        },
        invite: function () {
            bindForm();
        },
        copy: function () {
            bindForm();
        },
        market: function () {
            bindForm();
            bindJackpotReset();
        },
        security: function () {
            bindForm();
            bindUidTest();
        },
        telegram: function () {
            bindForm();
        },
        i18n: function () {
            bindI18n();
        }
    };
    return Controller;
});

