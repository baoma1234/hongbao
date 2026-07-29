<?php

/**
 * 财务后台配置
 * 说明：pid 即代表一个财务后台，pid=1 为后台1，pid=2 为后台2，以此类推。
 * 新增后台：在 sites 下增加对应 pid 配置，并把 enabled 改为 true。
 */
return [
    'default_pid' => 1,
    'common'      => [
        'merch_table'    => 'sys_merch_channel',
        'withdraw_table' => 'sys_withdraw_unpaid',
        'schedule_table' => 'sys_urge_schedule',
        'urge_bot_token' => '8867639246:AAEIawpMD6BkyFRo3mEEAcMsOw7S-__E2oQ',
        'urge_chat_id'   => '',
        'cache_prefix'   => 'finance_login_',
        'cache_expire'   => 7200,
        'grant_type'     => 'password',
        'scope'          => 'server',
        'user_agent'     => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36',
    ],
    'sites'       => [
        1 => [
            'name'          => '03',
            'enabled'       => true,
            'base_url'      => 'https://trz97z15h0u.cg.ink',
            'sitecode'      => '1187',
            'companycode'   => '1187',
            'childsitecode' => '1187',
            'username'      => 'cdrobot',
            'password'      => '5ef0a7eaffaca9190bdb638154c061dee21c049e044bc451796385689d993bf1',
            'code'          => 'ewp5',
            'ga_secret'     => 'LUJLQX6WOJUJLIITZWJWD4YAO2UBCDPY',
            'device_id'     => '6d878289-abcd-4c76-b404-1e09c319a4bc',
            'base_cookie'   => 'isBrowserKeepAlive=1; sidebarStatus=1; SESSIONID-3-1187=02ba18b6-390d-4412-b12a-newhash-4dc10230cb92; ws_1187=third-b595a238-810a-4956-a070-f0ac5c514350',
            'referer_merch'    => '/financialManagementNew/paymentSettings?activeName=finance_merch_withdraw',
            'referer_withdraw' => '/financialManagementNew/paymentSettings?activeName=finance_withdraw_index',
        ],
        2 => [
            'name'          => '656',
            'enabled'       => true,
            'base_url'      => 'https://1lii229ty4d.cg.ink',
            'sitecode'      => '1806',
            'companycode'   => '1806',
            'childsitecode' => '1806',
            'username'      => 'cdrobot656',
            'password'      => '697e5535dcf7950a01b234207409cdb296684d3d8092ebddd7a7f699f6c21212',
            'code'          => 'ewp5',
            'ga_secret'     => '4WA562GLNIZIL5DLKG4RNW42Y6ZCEDVI',
            'device_id'     => 'fe21c5b8-9292-4239-ab92-0ab9fdcf7ef7',
            'base_cookie'   => 'isBrowserKeepAlive=1; ws_1806=third-31dbda78-85ef-463b-bbc1-2c951787dd71; _ga=GA1.1.1468909791.1781499225; _ga_89WN60ZK2E=GS2.1.s1781499225$o1$g0$t1781499228$j57$l0$h0; __cf_bm=9L_.mX_xJPsGA0bC_QStMLH7o3gkrRFOYpo4EE1GsdQ-1781508116.5900524-1.0.1.1-KJgXobkQrOqf9OgqIEa_clQxJb6l3qGLJhBpqlueLNJR0lqOzWX_NyJGegXbKx8CddmAzc_W7Jo_O7Jyz9392G9YaeFIuE1yRdkPG70qsNqE_HNmrLmI_GO8906i1BLG',
            'referer_merch'    => '/financialManagementNew/paymentSettings?activeName=finance_merch_withdraw',
            'referer_withdraw' => '/financialManagementNew/paymentSettings?activeName=finance_withdraw_index',
        ],
        3 => [
            'name'          => '887',
            'enabled'       => true,
            'base_url'      => 'https://22l78iv255rs0o80.cg.ink',
            'sitecode'      => '2377',
            'companycode'   => '2377',
            'childsitecode' => '2377',
            'username'      => 'cdrobot887',
            'password'      => '697e5535dcf7950a01b234207409cdb296684d3d8092ebddd7a7f699f6c21212',
            'code'          => 'ewp5',
            'ga_secret'     => 'LCP2PLXPZLNKYBNAOL5LLUR37EWMCBJK',
            'device_id'     => '787699f2-4d1d-4531-9756-242e5df0f49a',
            'base_cookie'   => 'isBrowserKeepAlive=1; ws_2377=third-2263be9e-fe75-481d-bce1-5f7fd5daf218; _ga=GA1.1.1468909791.1781499225; _ga_89WN60ZK2E=GS2.1.s1781499225$o1$g0$t1781499228$j57$l0$h0; __cf_bm=2ThiZP62NX8sGz9myTKIC..ktijV.4qiOtmREGhC_tg-1781508181.2598736-1.0.1.1-Z5EbXtB0bKlqdZTus7YLfcIEW24PCdv6GQTTc3mXxcMBpnE_facUxmaSVvPneDeYK5p.MF04Y3hUypT7Y.xuehJdPQdHZ12eS9Q94YELX89t0U38jf4mAbNOaYN.y2Qa',
            'referer_merch'    => '/financialManagementNew/paymentSettings?activeName=finance_merch_withdraw',
            'referer_withdraw' => '/financialManagementNew/paymentSettings?activeName=finance_withdraw_index',
        ],
    ],
];
