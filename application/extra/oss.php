<?php

use think\Env;

/**
 * 阿里云 OSS（本地保留 + 双写上传时读取）
 * 密钥填在项目根目录 .env 的 [oss] 段，勿提交仓库。
 */
return [
    'enabled'           => (bool)Env::get('oss.enabled', false),
    'access_key_id'     => (string)Env::get('oss.access_key_id', ''),
    'access_key_secret' => (string)Env::get('oss.access_key_secret', ''),
    'bucket'            => (string)Env::get('oss.bucket', ''),
    'endpoint'          => (string)Env::get('oss.endpoint', 'oss-cn-hongkong.aliyuncs.com'),
    'region'            => (string)Env::get('oss.region', 'cn-hongkong'),
    /** 绑定的 CDN / 自定义域名，如 https://img.example.com ；空则用 bucket.endpoint */
    'cdn_domain'        => (string)Env::get('oss.cdn_domain', ''),
    /** true=本地落盘后再同步 OSS；false=仅本地 */
    'dual_write'        => (bool)Env::get('oss.dual_write', true),
    /** OSS 对象前缀（不含首斜杠），与本地 /uploads/ 对齐 */
    'prefix'            => (string)Env::get('oss.prefix', 'uploads/'),
];
