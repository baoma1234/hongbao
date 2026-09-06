/**
 * 大厅装修后台：图片预览（打包 static + OSS 上传）
 * 种子路径 home/lobby/* 不可走 upload.cdnurl（否则会拼成 OSS 根下不存在的 key）
 */
define(['jquery'], function ($) {
    function ossBase() {
        try {
            if (typeof Config !== 'undefined' && Config.upload && Config.upload.cdnurl) {
                return String(Config.upload.cdnurl).replace(/\/+$/, '');
            }
        } catch (e) {
        }
        return '';
    }

    function packagedLobbyUrl(relPath) {
        var p = String(relPath || '').replace(/^\/+/, '');
        if (p.indexOf('static/') === 0) p = p.slice(7);
        if (p.indexOf('999/static/') === 0) p = p.slice('999/static/'.length);
        var key = '999/static/' + p;
        var base = ossBase();
        if (base) return base + '/' + key;
        return '/' + key;
    }

    function resolveUrl(url) {
        url = String(url || '').trim();
        if (!url) return '';
        if (/^https?:\/\//i.test(url) || url.indexOf('data:') === 0) return url;

        var p = url.replace(/^\/+/, '');
        if (p.indexOf('static/') === 0) p = p.slice(7);

        // 本站 /999/static/... 或种子 home/lobby/...
        if (p.indexOf('999/static/') === 0) {
            return packagedLobbyUrl(p);
        }
        if (p.indexOf('home/lobby/') === 0) {
            return packagedLobbyUrl(p);
        }

        if (typeof Fast !== 'undefined' && Fast.api && typeof Fast.api._lobbyOrigCdnurl === 'function') {
            return Fast.api._lobbyOrigCdnurl(url.indexOf('/') === 0 ? url : '/' + url, true);
        }
        if (typeof Fast !== 'undefined' && Fast.api && Fast.api.cdnurl) {
            return Fast.api.cdnurl(url.indexOf('/') === 0 ? url : '/' + url, true);
        }
        return url.indexOf('/') === 0 ? url : '/' + url;
    }

    function patchCdnurl() {
        if (typeof Fast === 'undefined' || !Fast.api || typeof Fast.api.cdnurl !== 'function') return;
        if (Fast.api._lobbyCdnPatched) return;
        Fast.api._lobbyOrigCdnurl = Fast.api.cdnurl;
        Fast.api.cdnurl = function (url, domain) {
            var u = String(url || '').trim();
            if (u && !/^https?:\/\//i.test(u) && u.indexOf('data:') !== 0) {
                var p = u.replace(/^\/+/, '');
                if (p.indexOf('static/') === 0) p = p.slice(7);
                if (p.indexOf('home/lobby/') === 0 || p.indexOf('999/static/home/lobby/') === 0) {
                    return resolveUrl(u);
                }
            }
            return Fast.api._lobbyOrigCdnurl(url, domain);
        };
        Fast.api._lobbyCdnPatched = true;
    }

    function refreshPreviews(form) {
        patchCdnurl();
        var $form = form ? $(form) : $('form[role=form]');
        $('.faupload', $form).each(function () {
            var inputId = $(this).data('input-id');
            var previewId = $(this).data('preview-id');
            if (!inputId || !previewId) return;
            var $input = $('#' + inputId);
            var $preview = $('#' + previewId);
            if (!$input.length || !$preview.length) return;
            var val = String($input.val() || '').trim();
            if (!val) {
                $preview.empty();
                return;
            }
            // 走 Upload 预览逻辑（已 patch cdnurl，home/lobby 会指向 OSS /999/static）
            $input.trigger('change');
        });
    }

    patchCdnurl();

    return {
        resolveUrl: resolveUrl,
        refreshPreviews: refreshPreviews,
        imageFormatter: function (value) {
            value = value == null || value === '' ? '' : String(value);
            if (!value) return '';
            var url = resolveUrl(value);
            return '<a href="javascript:"><img class="img-sm img-center" src="' + url + '"></a>';
        }
    };
});
