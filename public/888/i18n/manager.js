(function (global) {
    var STORAGE_LOCALE = 'fans_hub_locale';
    var STORAGE_COUNTRY = 'fans_hub_country';
    var DEFAULT_LOCALE = 'zh-CN';
    var loadPromises = {};

    function getMeta(locale) {
        var meta = global.FANSHUB_LOCALE_META || {};
        return meta[locale] || meta[DEFAULT_LOCALE] || { country: 'CN', htmlLang: 'zh-CN', currency: '￥' };
    }

    function getCountry(code) {
        var list = global.FANSHUB_COUNTRIES || [];
        for (var i = 0; i < list.length; i++) {
            if (list[i].code === code) return list[i];
        }
        return list[0] || { code: 'CN', dial: '86', maxlen: 11, placeholderKey: 'login_phone_placeholder' };
    }

    /** 语言/国家 → 国旗 ISO（与 img/flags/{iso}.svg 对应） */
    function resolveFlagIso(flagIso, code) {
        if (flagIso) return String(flagIso).toLowerCase();
        var c = String(code || '').toUpperCase();
        var localeMap = {
            'EN-PH': 'ph', 'VI-VN': 'vn', 'MS-MY': 'my', 'KM-KH': 'kh', 'ID-ID': 'id', 'ZH-CN': 'cn',
            'PH': 'ph', 'VN': 'vn', 'MY': 'my', 'KH': 'kh', 'ID': 'id', 'CN': 'cn'
        };
        if (localeMap[c]) return localeMap[c];
        var parts = String(code || '').split('-');
        if (parts.length >= 2) return parts[1].toLowerCase();
        return c.toLowerCase();
    }

    function scriptBase() {
        var scripts = document.getElementsByTagName('script');
        for (var i = scripts.length - 1; i >= 0; i--) {
            var src = scripts[i].src || '';
            if (/i18n\/manager\.js(\?|$)/.test(src)) {
                return src.replace(/manager\.js(\?.*)?$/, '');
            }
        }
        return 'i18n/';
    }

    var I18n = {
        locale: DEFAULT_LOCALE,
        country: 'CN',

        init: function () {
            try {
                this.locale = localStorage.getItem(STORAGE_LOCALE) || DEFAULT_LOCALE;
                if (!global.FANSHUB_LOCALE_META || !global.FANSHUB_LOCALE_META[this.locale]) {
                    this.locale = DEFAULT_LOCALE;
                }
                var meta = getMeta(this.locale);
                this.country = localStorage.getItem(STORAGE_COUNTRY) || meta.country || 'CN';
            } catch (e) {
                this.locale = DEFAULT_LOCALE;
                this.country = 'CN';
            }
            return this;
        },

        /** 按需加载单个语言包（已加载则直接 resolve） */
        ensureLocaleLoaded: function (locale) {
            locale = locale || this.locale || DEFAULT_LOCALE;
            if (!global.FANSHUB_LOCALE_META || !global.FANSHUB_LOCALE_META[locale]) {
                locale = DEFAULT_LOCALE;
            }
            global.FANSHUB_LOCALES = global.FANSHUB_LOCALES || {};
            if (global.FANSHUB_LOCALES[locale]) {
                return Promise.resolve(locale);
            }
            if (loadPromises[locale]) {
                return loadPromises[locale];
            }
            var base = scriptBase();
            var ver = global.FANSHUB_I18N_VER || '1';
            var url = base + 'locales/' + encodeURIComponent(locale) + '.js?v=' + encodeURIComponent(ver);
            loadPromises[locale] = new Promise(function (resolve, reject) {
                var s = document.createElement('script');
                s.async = true;
                s.src = url;
                s.onload = function () {
                    if (global.FANSHUB_LOCALES && global.FANSHUB_LOCALES[locale]) {
                        resolve(locale);
                    } else {
                        reject(new Error('locale pack empty: ' + locale));
                    }
                };
                s.onerror = function () {
                    delete loadPromises[locale];
                    if (locale !== DEFAULT_LOCALE) {
                        I18n.ensureLocaleLoaded(DEFAULT_LOCALE).then(resolve).catch(reject);
                    } else {
                        reject(new Error('locale pack failed: ' + locale));
                    }
                };
                document.head.appendChild(s);
            });
            return loadPromises[locale];
        },

        setLocale: function (locale) {
            var self = this;
            if (!global.FANSHUB_LOCALE_META || !global.FANSHUB_LOCALE_META[locale]) {
                return Promise.resolve();
            }
            return this.ensureLocaleLoaded(locale).then(function () {
                self.locale = locale;
                var meta = getMeta(locale);
                self.country = meta.country || self.country;
                try {
                    localStorage.setItem(STORAGE_LOCALE, locale);
                    localStorage.setItem(STORAGE_COUNTRY, self.country);
                } catch (e) {}
                self.applyDocumentLang();
                if (typeof global.onFanshubLocaleChange === 'function') {
                    global.onFanshubLocaleChange();
                }
            });
        },

        setCountry: function (code) {
            this.country = code;
            try { localStorage.setItem(STORAGE_COUNTRY, code); } catch (e) {}
            if (typeof global.onFanshubCountryChange === 'function') {
                global.onFanshubCountryChange();
            }
        },

        getCountry: function () {
            return getCountry(this.country);
        },

        getDial: function () {
            return this.getCountry().dial;
        },

        text: function (key, baseCopy, extra) {
            var locales = global.FANSHUB_LOCALES || {};
            var chain = [this.locale, 'en-PH', 'zh-CN'];
            var tpl = '';
            for (var i = 0; i < chain.length; i++) {
                if (locales[chain[i]] && locales[chain[i]][key]) {
                    tpl = locales[chain[i]][key];
                    break;
                }
            }
            if (!tpl && baseCopy && baseCopy[key]) {
                tpl = baseCopy[key];
            }
            return tpl;
        },

        /** 当前语言完整文案表（单包已含 zh 合并） */
        currentPack: function () {
            var locales = global.FANSHUB_LOCALES || {};
            return locales[this.locale] || locales[DEFAULT_LOCALE] || {};
        },

        currencySymbol: function () {
            return getMeta(this.locale).currency || '￥';
        },

        applyDocumentLang: function () {
            var meta = getMeta(this.locale);
            document.documentElement.lang = meta.htmlLang || 'zh-CN';
        },

        stripNational: function (raw) {
            var digits = String(raw || '').replace(/\D/g, '');
            var c = this.getCountry();
            var dial = String(c.dial);
            if (digits.indexOf(dial) === 0) {
                digits = digits.slice(dial.length);
            }
            if (this.country === 'CN' && digits.length === 13 && digits.indexOf('86') === 0) {
                digits = digits.slice(2);
            }
            return digits;
        },

        toE164: function (raw) {
            var national = this.stripNational(raw);
            if (!national) return '';
            var c = this.getCountry();
            if (!this.isValidNational(national)) return '';
            return '+' + c.dial + national;
        },

        isValidNational: function (national) {
            national = this.stripNational(national);
            var patterns = {
                CN: /^1[3-9]\d{9}$/,
                PH: /^9\d{9}$/,
                KH: /^\d{8,9}$/,
                ID: /^8\d{8,11}$/,
                VN: /^[35789]\d{8}$/,
                MY: /^1\d{8,9}$/
            };
            var re = patterns[this.country] || patterns.CN;
            return re.test(national);
        },

        fillCountrySelect: function (selectEl, textFn) {
            if (!selectEl) return;
            var html = '';
            (global.FANSHUB_COUNTRIES || []).forEach(function (c) {
                var label = (typeof textFn === 'function' && c.labelKey) ? textFn(c.labelKey) : c.code;
                html += '<option value="' + c.code + '" data-flag-iso="' + resolveFlagIso(c.flagIso, c.code) + '">+' + c.dial + ' ' + label + '</option>';
            });
            selectEl.innerHTML = html;
            selectEl.value = this.country;
            this.mountFlagSelect(selectEl, 'country');
        },

        fillLocaleSelect: function (selectEl, textFn) {
            if (!selectEl) return;
            var html = '';
            var meta = global.FANSHUB_LOCALE_META || {};
            var order = global.FANSHUB_LOCALE_ORDER || Object.keys(meta);
            var seen = {};
            var append = function (loc) {
                if (!meta[loc] || seen[loc]) return;
                seen[loc] = true;
                var labelKey = meta[loc].labelKey || loc;
                var label = (typeof textFn === 'function') ? textFn(labelKey) : loc;
                html += '<option value="' + loc + '" data-flag-iso="' + resolveFlagIso(meta[loc].flagIso, loc) + '">' + label + '</option>';
            };
            order.forEach(append);
            Object.keys(meta).forEach(append);
            selectEl.innerHTML = html;
            selectEl.value = this.locale;
            this.mountFlagSelect(selectEl, 'locale');
        },

        flagUrl: function (iso) {
            iso = String(iso || '').toLowerCase();
            if (!iso) return '';
            var assets = global.FANSHUB_ASSETS || {};
            var base = (assets.base != null && assets.base !== '') ? String(assets.base) : '';
            if (!base) {
                base = scriptBase().replace(/i18n\/?$/, '');
            }
            if (base && base.slice(-1) !== '/') base += '/';
            var ver = assets.ver || global.FANSHUB_I18N_VER || '1';
            return base + 'img/flags/' + encodeURIComponent(iso) + '.svg?v=' + encodeURIComponent(ver);
        },

        /** Windows 原生 select 不显示国旗 Emoji，用图片国旗自定义下拉 */
        mountFlagSelect: function (selectEl, mode) {
            if (!selectEl || selectEl._flagMounted) {
                if (selectEl && selectEl._flagSync) selectEl._flagSync();
                return;
            }
            selectEl._flagMounted = true;
            var wrap = document.createElement('div');
            wrap.className = 'flag-select' + (mode === 'locale' ? ' flag-select-locale' : ' flag-select-country');
            selectEl.parentNode.insertBefore(wrap, selectEl);
            wrap.appendChild(selectEl);
            selectEl.classList.add('flag-select-native');

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'flag-select-btn';
            btn.setAttribute('aria-haspopup', 'listbox');
            wrap.appendChild(btn);

            var panel = document.createElement('div');
            panel.className = 'flag-select-panel';
            panel.setAttribute('role', 'listbox');
            panel.hidden = true;
            wrap.appendChild(panel);

            var sync = function () {
                var opt = selectEl.options[selectEl.selectedIndex];
                var iso = (opt && opt.getAttribute('data-flag-iso')) || '';
                var text = opt ? opt.textContent : '';
                var url = I18n.flagUrl(iso);
                btn.innerHTML = (url ? '<img class="flag-select-img" src="' + url + '" alt="" width="22" height="15">' : '')
                    + '<span class="flag-select-text">' + text + '</span>'
                    + '<span class="flag-select-caret" aria-hidden="true"></span>';

                var items = '';
                Array.prototype.forEach.call(selectEl.options, function (o) {
                    var oIso = o.getAttribute('data-flag-iso') || '';
                    var oUrl = I18n.flagUrl(oIso);
                    var active = o.value === selectEl.value ? ' is-active' : '';
                    items += '<button type="button" class="flag-select-item' + active + '" role="option" data-value="'
                        + o.value.replace(/"/g, '&quot;') + '">'
                        + (oUrl ? '<img class="flag-select-img" src="' + oUrl + '" alt="" width="22" height="15">' : '')
                        + '<span>' + o.textContent + '</span></button>';
                });
                panel.innerHTML = items;
            };
            selectEl._flagSync = sync;
            sync();

            btn.addEventListener('click', function (ev) {
                ev.preventDefault();
                ev.stopPropagation();
                var open = panel.hidden;
                document.querySelectorAll('.flag-select-panel').forEach(function (p) { p.hidden = true; });
                panel.hidden = !open;
                wrap.classList.toggle('is-open', !panel.hidden);
            });

            panel.addEventListener('click', function (ev) {
                var item = ev.target.closest('.flag-select-item');
                if (!item) return;
                selectEl.value = item.getAttribute('data-value') || '';
                selectEl.dispatchEvent(new Event('change', { bubbles: true }));
                panel.hidden = true;
                wrap.classList.remove('is-open');
                sync();
            });

            if (!document._flagSelectDocClose) {
                document._flagSelectDocClose = true;
                document.addEventListener('click', function () {
                    document.querySelectorAll('.flag-select-panel').forEach(function (p) { p.hidden = true; });
                    document.querySelectorAll('.flag-select.is-open').forEach(function (w) { w.classList.remove('is-open'); });
                });
            }
        }
    };

    I18n.init();
    global.FanshubI18n = I18n;
})(window);
