<?php
require_once __DIR__ . '/vendor/autoload.php';


/** 获取服务实例 */
$server = \Root\Io\RtmpDemo::instance();
/** 设置rtmp通信端口 可以自行修改 默认1935 */
$server->rtmpPort = 1935;
/** 设置flv通信端口 可以自行修改 默认8501 */
$server->flvPort = 8501;
/** hls协议预留端口 80 ，为了防止其他不相关的请求被转发到本项目，导致其他服务不可用，建议修改为其他不常用的端口，比如8000 */
$server->webPort = 80;
/** 启动服务 */
$server->start();



