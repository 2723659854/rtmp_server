<?php
require_once __DIR__.'/vendor/autoload.php';

/** 获取服务实例 */
$server = \Root\Io\RtmpDemo::instance();

/** 启动服务 */
$server->start();