<?php
require_once __DIR__ . '/vendor/autoload.php';


/** 获取服务实例 */
$server = \Root\Io\RtmpDemo::instance();
/** 设置flv通信端口 可以自行修改 默认8504 */
$server->flvPort = 8504;
/** 启动服务 */
$server->startFlvGateway();