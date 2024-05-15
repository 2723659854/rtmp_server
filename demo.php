<?php
require_once __DIR__.'/vendor/autoload.php';

$server = \Root\Io\RtmpDemo::instance();

$server->port = 1935 ;

$server->onConnect = function (\Root\rtmp\TcpConnection $connection){
    /** 将传递进来的数据解码 */
    new \MediaServer\Rtmp\RtmpStream(
        new \MediaServer\Utils\WMBufferStream($connection)
    );
};

/**  这个的作用就是添加一个新的协议并监听 */
$server->onWorkerStart = function ($server) {
     new \MediaServer\Http\HttpWMServer("\\MediaServer\\Http\\ExtHttpProtocol://0.0.0.0:18080",$server);
};

/** 启动服务 */
$server->start();