<?php
require_once __DIR__ . '/vendor/autoload.php';

try {
    /** 获取服务实例 */
    $server = \Root\Io\RtmpDemo::instance();
    /** 设置rtmp通信端口 可以自行修改 默认1935 */
    $server->rtmpPort = 1935;
    /** 设置flv通信端口 可以自行修改 默认8501 */
    $server->flvPort = 8501;
    /** 启动服务 */
    $server->start();
}catch (Exception $exception){
    var_dump($exception->getMessage());
    var_dump($exception->getLine());
    var_dump($exception->getFile());
}


