<?php

namespace app\admin\controller\fanshub;

/**
 * 用户发帖（彩金白嫖 / 红宝•海外圈内事）
 * 待审核优先，再按发布时间排序
 *
 * @icon fa fa-comments
 */
class Noticeuser extends Notice
{
    /** @var string[] */
    protected $categoryScope = ['ads', 'rules'];

    protected $defaultCategory = 'ads';

    protected $pendingFirstSort = true;
}
