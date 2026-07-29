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
                html += '<option value="' + c.code + '">+' + c.dial + ' ' + label + '</option>';
            });
            selectEl.innerHTML = html;
            selectEl.value = this.country;
        },

        fillLocaleSelect: function (selectEl, textFn) {
            if (!selectEl) return;
            var html = '';
            var meta = global.FANSHUB_LOCALE_META || {};
            Object.keys(meta).forEach(function (loc) {
                var labelKey = meta[loc].labelKey || loc;
                var label = (typeof textFn === 'function') ? textFn(labelKey) : loc;
                html += '<option value="' + loc + '">' + label + '</option>';
            });
            selectEl.innerHTML = html;
            selectEl.value = this.locale;
        }
    };

    I18n.init();
    global.FanshubI18n = I18n;
})(window);
