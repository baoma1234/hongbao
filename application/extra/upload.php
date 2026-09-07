<?php

//上传配置
return [
    /**
     * 上传地址,默认是本地上传
     */
    'uploadurl' => 'ajax/upload',
    /**
     * CDN地址
     */
    'cdnurl'    => '',
    /**
     * 文件保存格式
     */
    'savekey'   => '/uploads/{year}{mon}{day}/{filemd5}{.suffix}',
    /**
     * 最大可上传大小（非图/视频兜底；图片/视频在 Upload 内分别限制 5MB / 200MB）
     */
    'maxsize'   => '200mb',
    /**
     * 聊天/通用图片上传上限（字节）
     */
    'image_maxsize' => 5242880,
    /**
     * 视频上传上限（字节）
     */
    'video_maxsize' => 209715200,
    /**
     * 服务端图片压缩：最长边像素；0 表示不缩放
     */
    'image_max_edge' => 1920,
    /**
     * JPEG/WebP 压缩质量 1–100
     */
    'image_quality' => 82,
    /**
     * 可上传的文件类型
     * 如配置允许 pdf,ppt,docx,svg 等可能含有脚本的文件时，请先从服务器配置此类文件直接下载而不是预览
     */
    'mimetype'  => 'jpg,png,bmp,jpeg,gif,webp,zip,rar,wav,mp4,mp3,webm',
    /**
     * 是否支持批量上传
     */
    'multiple'  => false,
    /**
     * 上传超时时长，这里仅用于JS上传超时控制
     */
    'timeout'  => 600000,
    /**
     * 是否支持分片上传
     */
    'chunking'  => false,
    /**
     * 默认分片大小
     */
    'chunksize' => 2097152,
    /**
     * 完整URL模式
     */
    'fullmode' => false,
    /**
     * 缩略图样式
     */
    'thumbstyle' => '',
];
