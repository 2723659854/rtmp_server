<?php


namespace MediaServer\Http;

use MediaServer\Flv\FlvPlayStream;
use MediaServer\Flv\FlvPublisherStream;
use MediaServer\MediaServer;
use MediaServer\Utils\WMHttpChunkStream;
use MediaServer\Utils\WMWsChunkStream;
use Psr\Http\Message\StreamInterface;
use React\Promise\Promise;
use Root\Io\RtmpDemo;
use Root\rtmp\TcpConnection;
use Root\Request;
use Root\Response;
use Root\Protocols\Websocket;

/**
 * @purpose flv服务
 */
class HttpWMServer
{
    /** @var string $publicPath 资源路径 */
    static $publicPath = '';

    /**
     * 初始化
     */
    public function __construct()
    {

    }

    /**
     * 定义ws请求响应方法
     * @param TcpConnection $connection
     * @param string $headerData
     * @return void
     */
    public function onWebsocketRequest(TcpConnection $connection, string $headerData)
    {
        $request = new Request($headerData);
        $request->connection = $connection;
        //ignore connection message
        $connection->onMessage = null;
        if ($this->findFlv($request, $request->path())) {
            return;
        }
        $request->connection->close();
        return;
    }


    /**
     * http响应请求
     * @param TcpConnection $connection
     * @param Request $request
     */
    public function onHttpRequest(TcpConnection $connection, Request $request)
    {

        /** 这里做特殊处理 ，判断这个客户端是否是链接的代理服务器，如果是，那么使用代理客户端请求服务端 */
        if (isset(RtmpDemo::$playerClients[(int)$connection->getSocket()])){

            var_dump("代理请求");
            /** 请求代理服务器 */
            switch ($request->method()) {
                case "GET":
                    return $this->getHandlerGateway($request,$connection);
                case "HEAD":
                    return $connection->send(new Response(200));
                default:
                    logger()->warning("unknown method", ['method' => $request->method(), 'path' => $request->path()]);
                    return $connection->send(new Response(405));
            }
        }else{
            var_dump("正常请求");
            /** 请求主服务器 */
            switch ($request->method()) {
                case "GET":
                    return $this->getHandler($request);
                case "POST":
                    return $this->postHandler($request);
                case "HEAD":
                    return $connection->send(new Response(200));
                default:
                    logger()->warning("unknown method", ['method' => $request->method(), 'path' => $request->path()]);
                    return $connection->send(new Response(405));
            }
        }

    }

    /**
     * 处理flv代理请求
     * @param Request $request
     * @return void
     */
    public function getHandlerGateway(Request $request,TcpConnection $connection)
    {
        $path = $request->path();

        //api
        if ($path === '/api') {
            /** 转发，不处理其他逻辑 */
            return;
        }
        //flv
        if (
            $this->unsafeUri($request, $path) ||
            $this->findFlvGateway($request, $path) ||
            $this->findStaticFile($request, $path)
        ) {
            return ;
        }

        //api

        //404
        $request->connection->send(new Response(404, [], '404 Not Found'));
        return;
    }

    /**
     * 处理http的get请求
     * @param Request $request
     */
    public function getHandler(Request $request)
    {
        $path = $request->path();

        //api
        if ($path === '/api') {
            $name = $request->get('name');
            $args = $request->get('args', []);
            /** 调用媒体服务的接口 */
            $data = MediaServer::callApi($name, $args);
            if (!is_null($data)) {
                $request->connection->send(new Response(200, ['Content-Type' => "application/json"], json_encode($data)));
            } else {
                $request->connection->send(new Response(404, [], '404 Not Found'));
            }
            return;
        }
        //flv
        if (
            $this->unsafeUri($request, $path) ||
            $this->findFlv($request, $path) ||
            $this->findStaticFile($request, $path)
        ) {
            return;
        }

        //api

        //404
        $request->connection->send(new Response(404, [], '404 Not Found'));
        return;
    }


    /**
     * 处理post请求
     * @param Request $request
     * @return Promise|Response
     * @comment 貌似是发布流媒体，这里应该是http的播放器发送的请求
     * @comment 是这里和mediaServer产生关系的
     */
    public function postHandler(Request $request)
    {
        $path = $request->getUri()->getPath();
        $bodyStream = $request->getBody();
        if (!$bodyStream instanceof StreamInterface || !$bodyStream instanceof ReadableStreamInterface) {
            return new Response(
                500,
                ['Content-Type' => 'text/plain'],
                "Stream error."
            );
        };

        if (MediaServer::hasPublishStream($path)) {
            //publishStream already
            logger()->warning("Stream {path} exists", ['path' => $path]);
            return new Response(
                400,
                ['Content-Type' => 'text/plain'],
                "Stream {$path} exists."
            );
        }
        /** 调用了react php 的异步回调 */
        return new Promise(function ($resolve, $reject) use ($bodyStream, $path) {
            $flvReadStream = new FlvPublisherStream(
                $bodyStream,
                $path
            );
            /** 发布流媒体 这里和rtmp不同的地方，是把rtmp的数据转码成flv格式推流 */
            MediaServer::addPublish($flvReadStream);
            logger()->info("stream {path} created", ['path' => $path]);
            /** 绑定结束事件 */
            $flvReadStream->on('on_end', function () use ($resolve) {
                /** 返回响应200 */
                $resolve(new Response(200));
            });
            /** 绑定error事件 */
            $flvReadStream->on('error', function (\Exception $exception) use ($reject, &$bytes) {
                $reject(
                    new Response(
                        400,
                        ['Content-Type' => 'text/plain'],
                        $exception->getMessage()
                    )
                );
            });
        });
    }

    /**
     * 是否安全url
     * @param Request $request
     * @param $path
     * @return bool
     */
    public function unsafeUri(Request $request, $path): bool
    {
        if (
            !$path ||
            strpos($path, '..') !== false ||
            strpos($path, "\\") !== false ||
            strpos($path, "\0") !== false
        ) {
            $request->connection->send(new Response(404, [], '404 Not Found.'));
            return true;
        }
        return false;
    }

    /**
     * 返回静态资源
     * @param Request $request
     * @param $path
     * @return bool
     */
    public function findStaticFile(Request $request, $path)
    {

        if (preg_match('/%[0-9a-f]{2}/i', $path)) {
            $path = urldecode($path);
            if ($this->unsafeUri($request, $path)) {
                return true;
            }
        }

        $file = self::$publicPath . "/$path";
        if (!is_file($file)) {
            return false;
        }

        $request->connection->send((new Response())->withFile($file));

        return true;
    }

    /**
     * 播放flv
     * @param Request $request
     * @param $path
     * @return bool
     */
    public function findFlv(Request $request, $path)
    {
        if (!preg_match('/(.*)\.flv$/', $path, $matches)) {
            return false;
        } else {
            list(, $flvPath) = $matches;
            $this->playMediaStream($request, $flvPath);
            return true;
        }
    }

    //todo 两种方案，一种是转发flv 一种是转发rtmp，先尝试转发flv

    /**
     * 播放器请求网关请求播放flv
     * @param Request $request
     * @param $path
     * @return bool
     */
    public function findFlvGateway(Request $request, $path)
    {
        if (!preg_match('/(.*)\.flv$/', $path, $matches)) {
            return false;
        } else {
            list(, $flvPath) = $matches;
            /** 通知网关服务端更新关键帧，同时服务端按路径保存客户端，提高服务端转发数据的效率 */
            RtmpDemo::$client2ServerData[]= ['client'=>$request->connection->getSocket(),'path'=>$flvPath];
            /** 发送开播命令，然后发送关键帧 可能这个时候没有关键帧，*/
            RtmpDemo::sendKeyFrameToPlayer($request->connection->getSocket(),$flvPath);

            return true;
        }
    }

    /**
     * 播放flv资源
     * @param Request $request
     * @param $flvPath
     * @return void
     * @comment 是这里实现flv播放的 和mediaServer产生关系的
     */
    public function playMediaStream(Request $request, $flvPath)
    {
        /** 检查是否已经有发布这个流媒体 */
        //check stream
        if (MediaServer::hasPublishStream($flvPath)) {
            /** 如果是ws协议 */
            if ($request->connection->protocol === Websocket::class) {
                /** 修改ws缓冲区数据类型 为数组  */
                $request->connection->websocketType = Websocket::BINARY_TYPE_ARRAYBUFFER;
                /** 数据包 ws */
                $throughStream = new WMWsChunkStream($request->connection);
            } else {
                /** 数据包 http */
                $throughStream = new WMHttpChunkStream($request->connection);
            }
            /** 实例化flv播放资源 */
            $playerStream = new FlvPlayStream($throughStream, $flvPath);
            /** 是否关闭声音 */
            $disableAudio = $request->get('disableAudio', false);
            if ($disableAudio) {
                $playerStream->setEnableAudio(false);
            }
            /** 是否关闭视频 */
            $disableVideo = $request->get('disableVideo', false);
            if ($disableVideo) {
                $playerStream->setEnableVideo(false);
            }
            /** 是否要连续帧 */
            $disableGop = $request->get('disableGop', false);
            if ($disableGop) {
                $playerStream->setEnableGop(false);
            }
            /** 添加播放器 */
            MediaServer::addPlayer($playerStream);
        } else {
            /** 没有这一路推流资源 直接关闭链接或者发送404 */
            logger()->warning("Stream {path} not found", ['path' => $flvPath]);
            if ($request->connection->protocol === Websocket::class) {
                $request->connection->close();
            } else {
                /** 如果没有这个媒体资源，返回404，js一共会请求6次，若都是404，之后不会再自动发起请求 */
                $request->connection->send(
                    new Response(
                        404,
                        ['Content-Type' => 'text/plain','Access-Control-Allow-Origin' => '*',],
                        "Stream not found."
                    )
                );
            }

        }
    }
}