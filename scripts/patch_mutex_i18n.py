# -*- coding: utf-8 -*-
import re
line = "  'phase2_share_checkin_mutex' => 'Already checked in today — promo text can still be copied, but share shares are not granted again',\n"
files = [
    r'c:\wwwroot\caijin.com_7111\application\extra\i18n\en-PH.php',
    r'c:\wwwroot\caijin.com_7111\application\extra\i18n\id-ID.php',
    r'c:\wwwroot\caijin.com_7111\application\extra\i18n\vi-VN.php',
    r'c:\wwwroot\caijin.com_7111\application\extra\i18n\ms-MY.php',
    r'c:\wwwroot\caijin.com_7111\application\extra\i18n\km-KH.php',
]
for p in files:
    t = open(p, encoding='utf-8').read()
    if 'phase2_share_checkin_mutex' in t:
        print('SKIP', p)
        continue
    t2 = re.sub(r'\);\s*$', line + ');\n', t, count=1)
    open(p, 'w', encoding='utf-8', newline='\n').write(t2)
    print('OK', p)
