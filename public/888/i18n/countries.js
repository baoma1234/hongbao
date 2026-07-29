window.FANSHUB_COUNTRIES = [
    // 顺序：中文、英文、越南、马来、柬埔寨、印尼（用图片国旗，兼容 Windows）
    { code: 'CN', dial: '86',  flagIso: 'cn', labelKey: 'country_cn', maxlen: 11, placeholderKey: 'login_phone_placeholder' },
    { code: 'PH', dial: '63',  flagIso: 'ph', labelKey: 'country_ph', maxlen: 10, placeholderKey: 'login_phone_placeholder_ph' },
    { code: 'VN', dial: '84',  flagIso: 'vn', labelKey: 'country_vn', maxlen: 10, placeholderKey: 'login_phone_placeholder_vn' },
    { code: 'MY', dial: '60',  flagIso: 'my', labelKey: 'country_my', maxlen: 10, placeholderKey: 'login_phone_placeholder_my' },
    { code: 'KH', dial: '855', flagIso: 'kh', labelKey: 'country_kh', maxlen: 9,  placeholderKey: 'login_phone_placeholder_kh' },
    { code: 'ID', dial: '62',  flagIso: 'id', labelKey: 'country_id', maxlen: 12, placeholderKey: 'login_phone_placeholder_id' }
];

/** 语言切换顺序：中文 + 英文(菲律宾) / 越南 / 马来 / 柬埔寨 / 印尼 */
window.FANSHUB_LOCALE_ORDER = ['zh-CN', 'en-PH', 'vi-VN', 'ms-MY', 'km-KH', 'id-ID'];

window.FANSHUB_LOCALE_META = {
    'zh-CN': { labelKey: 'lang_zh', country: 'CN', htmlLang: 'zh-CN', currency: '￥', flagIso: 'cn' },
    'en-PH': { labelKey: 'lang_en', country: 'PH', htmlLang: 'en', currency: '₱', flagIso: 'ph' },
    'vi-VN': { labelKey: 'lang_vi', country: 'VN', htmlLang: 'vi', currency: '₫', flagIso: 'vn' },
    'ms-MY': { labelKey: 'lang_ms', country: 'MY', htmlLang: 'ms', currency: 'RM', flagIso: 'my' },
    'km-KH': { labelKey: 'lang_km', country: 'KH', htmlLang: 'km', currency: '៛', flagIso: 'kh' },
    'id-ID': { labelKey: 'lang_id', country: 'ID', htmlLang: 'id', currency: 'Rp', flagIso: 'id' }
};
