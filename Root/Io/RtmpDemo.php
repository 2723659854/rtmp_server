<?php

namespace Root\Io;

use MediaServer\Flv\Flv;
use MediaServer\Flv\FlvTag;
use MediaServer\Http\HttpWMServer;
use MediaServer\MediaReader\AACPacket;
use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\AVCPacket;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\MediaReader\MetaDataFrame;
use MediaServer\MediaReader\VideoFrame;
use MediaServer\MediaServer;
use MediaServer\PushServer\PublishStreamInterface;
use Root\Protocols\Http;
use Root\Protocols\Http\Chunk;
use Root\Response;
use Root\rtmp\TcpConnection;

/**
 * @purpose 使用了select的IO多路复用模型
 * @note 也可以使用epoll模型，但是windows目前不支持。为了兼容windows和Linux系统，所以选择select模型。
 * @comment 代码必须写注释，不然时间长了，自己也看不懂了
 */
class RtmpDemo
{
    /** @var array $allSocket 存放所有socket 注意内存泄漏 */
    public static array $allSocket;

    /** @var string $host 监听的ip */
    private string $host = '0.0.0.0';

    /** @var string $port RTMP监听的端口 可修改 */
    public string $rtmpPort = '1935';

    /** @var string $flvPort flv监听端口 可修改 */
    public string $flvPort = '8501';

    /** @var string $webPort web端口 */
    public string $webPort = '80';

    /** @var string $protocol 通信协议 */
    private string $protocol = 'tcp';

    /** @var ?RtmpDemo $instance rtmp服务器实例 */
    private static ?RtmpDemo $instance = null;

    /** @var int 读事件 */
    const  EV_READ = 1;

    /** @var int 写事件 */
    const EV_WRITE = 2;

    /** @var array $_allEvents 所有的事件 */
    private array $_allEvents = [];

    /** @var array $_readFds 读事件 */
    private array $_readFds = [];

    /** @var array $_writeFds 写事件 */
    private array $_writeFds = [];

    /** @var resource $flvServerSocket flv服務端 */
    private static $flvServerSocket = null;

    /** @var resource $webServerSocket web服务器 */
    private static $webServerSocket = null;

    /** @var resource $rtmpServerSocket rtmp服务器 */
    private static $rtmpServerSocket = null;

    /** @var string $transport 默认通信传输协议 */
    private string $transport = 'tcp';

    /** @var array $serverSocket 服务端socket */
    private array $serverSocket = [];


    /**
     * 添加读写事件
     * @param resource $fd socket链接
     * @param int $flag 读写类型
     * @param array $func 回调函数
     * @return bool
     */
    public function add($fd, int $flag, array $func): bool
    {
        switch ($flag) {
            case self::EV_READ:
            case self::EV_WRITE:
                $count = $flag === self::EV_READ ? \count($this->_readFds) : \count($this->_writeFds);
                if ($count >= 1024) {
                    /** 可以修改默认值并重新编译php ，突破1024的上限，不过作为直播，当达到1024个链接的时候，应该考虑CDN了。 */
                    logger()->warning("系统最大支持1024个链接");
                } else if (\DIRECTORY_SEPARATOR !== '/' && $count >= 256) {
                    logger()->warning("系统调用选择超出了最大连接数256");
                }
                $fd_key = (int)$fd;
                $this->_allEvents[$fd_key][$flag] = array($func, $fd);
                if ($flag === self::EV_READ) {
                    $this->_readFds[$fd_key] = $fd;
                } else {
                    $this->_writeFds[$fd_key] = $fd;
                }
                break;
        }

        return true;
    }

    /**
     * 删除事件
     * @param resource $fd 链接的socket
     * @param int $flag 事件类型
     * @return bool
     */
    public function del($fd, int $flag): bool
    {
        $fd_key = (int)$fd;
        switch ($flag) {
            case self::EV_READ:
                unset($this->_allEvents[$fd_key][$flag], $this->_readFds[$fd_key]);
                if (empty($this->_allEvents[$fd_key])) {
                    unset($this->_allEvents[$fd_key]);
                }
                return true;
            case self::EV_WRITE:
                unset($this->_allEvents[$fd_key][$flag], $this->_writeFds[$fd_key]);
                if (empty($this->_allEvents[$fd_key])) {
                    unset($this->_allEvents[$fd_key]);
                }
                return true;
        }
        return false;
    }


    /**
     * 创建flv播放服务
     * @return void
     */
    private function createFlvSever(): void
    {
        /** 保存flv服务端的socket */
        self::$flvServerSocket = $this->createServer($this->flvPort);
        logger()->info("flv服务：http://{$this->host}:{$this->flvPort}/{AppName}/{ChannelName}.flv");
        logger()->info("flv服务：ws://{$this->host}:{$this->flvPort}/{AppName}/{ChannelName}.flv");
    }

    /**
     * 创建rtmp服务
     */
    private function createRtmpServer(): void
    {
        self::$rtmpServerSocket = $this->createServer($this->rtmpPort);
        logger()->info("rtmp服务：rtmp://{$this->host}:{$this->rtmpPort}/{AppName}/{ChannelName}");
    }

    /**
     * 创建web服务器
     * @return void
     */
    private function createHlsServer(): void
    {
        self::$webServerSocket = $this->createServer($this->webPort);
        logger()->info("hls服务：http://{$this->host}:{$this->webPort}/{AppName}/{ChannelName}.m3u8");
    }

    /**
     * 创建服务器
     * @param string $port 监听端口
     * @return false|resource
     */
    private function createServer(string $port)
    {
        /**  拼接监听地址 */
        $listeningAddress = $this->protocol . '://' . $this->host . ':' . $port;
        /** 不验证https证书 */
        $contextOptions['ssl'] = ['verify_peer' => false, 'verify_peer_name' => false];
        /** 配置socket流参数 */
        $context = stream_context_create($contextOptions);
        /** 设置端口复用 解决惊群效应  */
        stream_context_set_option($context, 'socket', 'so_reuseport', 1);
        /** 设置ip复用 */
        stream_context_set_option($context, 'socket', 'so_reuseaddr', 1);
        /** 设置服务端：监听地址+端口 */
        $socket = stream_socket_server($listeningAddress, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        /** 设置非阻塞，语法是关闭阻塞 */
        stream_set_blocking($socket, 0);
        /** 将服务端保存所有socket列表  */
        self::$allSocket[(int)$socket] = $socket;
        /** 单独保存服务端 */
        $this->serverSocket[(int)$socket] = $socket;
        /** 返回服务器实例 */
        return $socket;
    }

    /**
     * 先创建一个网关，用来作为转发flv数据包
     */
    public function createGateway()
    {
        /** 使用tcp通信 */
        self::$gateway = $this->createServer('8800');
        logger()->info("gateway网关：tcp://0.0.0.0:8800");
    }

    /**
     * 获取实例
     * @return self|null
     */
    public static function instance(): ?RtmpDemo
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 启动服务
     */
    public function start(): void
    {
        /** 开启rtmp 服务 */
        $this->createRtmpServer();
        /** 创建flv服务器 */
        $this->createFlvSever();
        /** 创建hls服务器 */
        $this->createHlsServer();
        /** 创建网关 */
        $this->createGateway();
        /** 开始接收客户端请求 */
        $this->accept();
    }


    /**
     * 接受客户端的链接，并处理数据
     */
    private function accept(): void
    {
        /** 创建多个子进程阻塞接收服务端socket 这个while死循环 会导致for循环被阻塞，不往下执行，创建了子进程也没有用，直接在第一个子进程哪里阻塞了 */
        while (true) {

            /** 初始化需要监测的可写入的客户端，需要排除的客户端都为空 */
            $except = [];
            /** 需要监听socket，自动清理已报废的链接 */
            foreach (self::$allSocket as $key => $value) {
                if (!is_resource($value)) {
                    unset(self::$allSocket[$key]);
                }
            }
            $write = $read = self::$allSocket;
            /** 使用stream_select函数监测可读，可写的连接，如果某一个连接接收到数据，那么数据就会改变，select使用的foreach遍历所有的连接，查看是否可读，就是有消息的时候标记为可读 */
            /** 这里设置了阻塞60秒 */
            try {
                stream_select($read, $write, $except, 60);
            } catch (\Exception $exception) {
                logger()->error($exception->getMessage());
            }
            /** 处理可读的链接 */
            if ($read) {
                foreach ($read as $fd) {
                    $fd_key = (int)$fd;
                    /** 处理多个服务端的链接 */
                    if (in_array($fd, $this->serverSocket)) {
                        /** 读取服务端接收到的 消息，这个消息的内容是客户端连接 ，stream_socket_accept方法负责接收客户端连接 */
                        $clientSocket = stream_socket_accept($fd, 0, $remote_address); //阻塞监听 设置超时0，并获取客户端地址
                        /** 如果这个客户端连接不为空 给链接绑定可读事件，绑定协议类型，而不同的协议绑定了不同的数据处理方式 */
                        if (!empty($clientSocket)) {
                            try {
                                /** 使用tcp解码器 */
                                $connection = new TcpConnection($clientSocket, $remote_address);
                                /** 通信协议 */
                                $connection->transport = $this->transport;
                                /** 如果是flv的链接 就设置为http的协议 flv是长链接 */
                                if (self::$flvServerSocket && $fd == self::$flvServerSocket) {
                                    $connection->protocol = \MediaServer\Http\ExtHttpProtocol::class;
                                    /** 支持http的flv播放 onMessage事件处理请求数据，使用ExtHttp协议处理数据， */
                                    $connection->onMessage = [new HttpWMServer(), 'onHttpRequest'];
                                    /** 支持ws的flv播放 onWebSocketConnect事件处理请求数据 ，如果是ws链接，
                                     * ExtHttpProtocol协议自动切换为ws链接，然后在握手后调用ws链接事件，添加播放设备，返回握手信息 ，
                                     * 后续媒体MediaServer使用ws链接返回媒体数据给链接
                                     */
                                    $connection->onWebSocketConnect = [new HttpWMServer(), 'onWebsocketRequest'];
                                }
                                /** web服务器使用http协议 hls是短连接*/
                                if (self::$webServerSocket && $fd == self::$webServerSocket) {
                                    /** 更换协议为http */
                                    $connection->protocol = Http::class;
                                    /** 绑定消息处理回调函数 */
                                    $connection->onMessage = [new Http(), 'onHlsMessage'];
                                }

                                /** 网关服务器 保存flv客户端，使用tcp协议 ，添加可读事件 */
                                if (self::$gateway && $fd == self::$gateway) {
                                    $connection->protocol = 'gateway';
                                    /** 单独保存flv的客户端 */
                                    RtmpDemo::$flvClients[(int)$clientSocket] = $clientSocket;
                                    /** 这个服务端的作用是，把rtmp服务器的数据转发给客户端 ，那么就是可写事件 */
                                    self::add($clientSocket, self::EV_READ, [$this, 'gatewayRead']);
                                    self::add($clientSocket, self::EV_WRITE, [$this, 'gatewayWrite']);
                                    /** 要求服务端强制更新关键帧 */
                                    //MediaServer::$hasSendImportantFrame = false;
                                }

                                /** rtmp 服务 长链接 协议直接处理了数据，不会触发onMessage事件，无需设置onMessage */
                                if (self::$rtmpServerSocket && $fd == self::$rtmpServerSocket) {
                                    /** 绑定协议类型为WMBufferStream */
                                    $connection->protocol = new \MediaServer\Utils\WMBufferStream($connection);
                                }
                            } catch (\Exception|\RuntimeException $exception) {
                                logger()->error($exception->getMessage());
                            }
                        }
                        /** 将这个客户端连接保存，目测这里如果不保存，应该是无法发送和接收消息的，就是要把所有的连接都保存在内存中 */
                        RtmpDemo::$allSocket[(int)$clientSocket] = $clientSocket;
                    } else {
                        /** 已经是建立过的链接，则直接该链接的读事件 */
                        if (isset($this->_allEvents[$fd_key][self::EV_READ])) {
                            \call_user_func_array(
                                $this->_allEvents[$fd_key][self::EV_READ][0],
                                array($this->_allEvents[$fd_key][self::EV_READ][1])
                            );
                        }
                    }

                }
            }
            /** 处理可写的链接 */
            if ($write) {
                foreach ($write as $fd) {
                    $fd_key = (int)$fd;
                    /** 调用预定义的可写回调函数 */
                    if (isset($this->_allEvents[$fd_key][self::EV_WRITE])) {
                        \call_user_func_array(
                            $this->_allEvents[$fd_key][self::EV_WRITE][0],
                            array($this->_allEvents[$fd_key][self::EV_WRITE][1])
                        );
                    }
                }
            }
        }
    }

    /** 网关服务器 */
    public static $gateway = null;


    /** 创建flv客户端和网关建立链接 */
    public function createFlvClient()
    {
        /** 初始化客户端设置 */
        $contextOptions = [];
        /** 设置参数 */
        $context = stream_context_create($contextOptions);
        /** 创建客户端 STREAM_CLIENT_CONNECT 同步请求，STREAM_CLIENT_ASYNC_CONNECT 异步请求*/
        $socket = @stream_socket_client("tcp://127.0.0.1:8800", $errno, $errstr, 3, STREAM_CLIENT_ASYNC_CONNECT, $context);
        /** 涉及到socket通信的地方，调用RuntimeException都会导致进程退出，抛出异常：Fatal error: Uncaught RuntimeException ，这是个很诡异的事情 */
        if ($errno) {
            var_dump($errno, $errstr);
            return;
        }
        /** 设置位非阻塞状态 */
        stream_set_blocking($socket, false);
        /** 将服务端保存所有socket列表  */
        self::$allSocket[(int)$socket] = $socket;

        self::$flvClient = $socket;
        /** 给客户端创建读写事件 ,不需要想服务端发送任何数据 */
        self::add(self::$flvClient, self::EV_READ, [$this, 'flvRead']);
        self::add(self::$flvClient, self::EV_WRITE, [$this, 'flvWrite']);
        return $socket;
    }

    /** flv客户端 */
    public static $flvClient = null;

    /**
     * 子进程，启动flv代理服务
     */
    public function startFlvGateway()
    {
        /** 将内存限制设置为1024MB ，使用内存作为缓存，解决直播时候内存不足的问题 */
        ini_set('memory_limit', '1024M');
        /** 创建flv服务器 */
        $this->createFlvSever();
        /** 先创建一个客户端和flv服务器通信 */
        $this->createFlvClient();
        /** 开始接收客户端请求 */
        $this->acceptFlv();
    }


    /** 文件暂存区 */
    public static $readBuffer = "";

    /** 关键帧 */
    public static $importantFrame = [];

    /** 关键帧总数 */
    public static $keyFrameCount = 0;

    /** 后面客户端播放后追加的关键帧 */
    public static $addKeyFrameAfterPlay = [];

    /** 解码用的三个关键帧 */
    public static array $seqs = [];

    /**
     * 这里是flv客户端向播放器推送数据
     * @param $fd
     * @return void
     * @comment 读取二进制数据出了问题
     */
    public function flvRead($fd)
    {
        $buffer = fread($fd, 15);
        self::$readBuffer .= $buffer;
        /** 获取第一个报文结束符\r\n\r\n */
        if ($pos = strpos(self::$readBuffer, "\r\n\r\n")) {
            /** 获取完整的内容 */
            $content = substr(self::$readBuffer, 0, $pos + 4);
            /** 更新暂存区 */
            self::$readBuffer = substr(self::$readBuffer, $pos + 4);
            /** 拆分为数组 */
            $array = explode("\r\n", $content);

            $type = ($array[0]);
            $timestamp = ($array[1]);
            $important = $array[2];
            $count = $array[3];
            $path = $array[4];
            $seq = $array[5];
            $frame = $array[6];
            $string = $type . "\r\n" . $timestamp . "\r\n" . $important . "\r\n" . $count . "\r\n" . $path . "\r\n" . $seq . "\r\n" . $frame . "\r\n\r\n";
            /** 目前播放器可以拉流，缓冲数据，无法播放，不知道是什么原因 */
            if ($type == MediaFrame::VIDEO_FRAME) {
                $frame = new VideoFrame($frame, $timestamp);
            } elseif ($type == MediaFrame::AUDIO_FRAME) {
                $frame = new AudioFrame($frame, $timestamp);
            } else {
                $frame = new MetaDataFrame($frame);
            }
            /** 保存解码设置 meta，音频和视频各保存一帧 */
            if (in_array($seq, ['aac', 'avc', 'meta'])) {
                var_dump("接收到解码关键帧");
                self::$seqs[$path][$seq] = $frame;
            }


            /** 存储所有的音视频帧 （这个方法不合理，当内存耗尽之后，进程会崩溃退出，等后面想个办法再处理） */
            if ($important) {
                self::$importantFrame[$path][] = $frame;
                if ((int)$seq != 4){
                    var_dump($seq);
                    self::$seqs[$path][$seq] = $frame;
                }
            }

            /** 给所有客户端发送关键帧 */
            foreach (self::$playerClients as $client) {
                /** 必须是客户端 */
                if (is_resource($client)) {
                    /** 必须已经发送了flv头和关键帧，否则浏览器无法解析文件 */
                    if (isset(self::$hasSendKeyFrame[$path][(int)$client])) {
                        self::frameSend($frame, $client);
                    }
                }
            }
        }
    }


    /**
     * 检测关键帧，追加到缓存中
     * @param MediaFrame $frame
     * @return void
     * @comment 方法有效，但是当推流开始后，将不会再发送关键帧，所以暂时用不上
     */
    public static function addKeyFrame(MediaFrame $frame, string $path)
    {
        /** 将音频帧的关键帧加入到队列 */
        if ($frame->FRAME_TYPE == MediaFrame::AUDIO_FRAME) {
            $aacPack = $frame->getAACPacket();
            $isAACSequence = false;
            if ($aacPack->aacPacketType === AACPacket::AAC_PACKET_TYPE_SEQUENCE_HEADER) {
                $isAACSequence = true;
            }

            if ($isAACSequence) {

                if ($aacPack->aacPacketType == AACPacket::AAC_PACKET_TYPE_SEQUENCE_HEADER) {

                } else {
                    var_dump("收到音频关键帧");
                    //音频关键帧缓存
                    /** 保存音频关键帧 */
                    self::$importantFrame[$path][] = $frame;
                }
            }
        }

        if ($frame->FRAME_TYPE == MediaFrame::VIDEO_FRAME) {
            $avcPack = $frame->getAVCPacket();
            $isAVCSequence = false;
            //read avc
            /** 元数据 描述信息 */
            if ($avcPack->avcPacketType === AVCPacket::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
                $isAVCSequence = true;
            }

            if ($isAVCSequence) {

                /** 保存视频帧 */
                if ($frame->frameType === VideoFrame::VIDEO_FRAME_TYPE_KEY_FRAME
                    &&
                    $avcPack->avcPacketType === AVCPacket::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
                    //skip avc sequence
                } else {
                    /** 保存视频关键帧 */
                    self::$importantFrame[$path][] = $frame;
                    var_dump("收到视频关键帧");
                }
            }
        }
    }

    /** 客户端是否已经发送了关键帧 */
    public static array $clientHasSendKeyFrame = [];

    /**
     * 发送数据到客户端
     * @param $frame MediaFrame
     * @return mixed
     * @comment 发送音频，视频，元数据
     */
    public static function frameSend($frame, $client)
    {
        //   logger()->info("send ".get_class($frame)." timestamp:".($frame->timestamp??0));
        switch ($frame->FRAME_TYPE) {
            case MediaFrame::VIDEO_FRAME:
                return self::sendVideoFrame($frame, $client);
            case MediaFrame::AUDIO_FRAME:
                return self::sendAudioFrame($frame, $client);
            case MediaFrame::META_FRAME:
                return self::sendMetaDataFrame($frame, $client);
        }
    }

    /**
     * 发送元数据
     * @param $metaDataFrame MetaDataFrame|MediaFrame
     * @return mixed
     */
    public static function sendMetaDataFrame($metaDataFrame, $client)
    {
        /** 组装数据 */
        $tag = new FlvTag();
        $tag->type = Flv::SCRIPT_TAG;
        $tag->timestamp = 0;
        $tag->data = (string)$metaDataFrame;
        $tag->dataSize = strlen($tag->data);
        /** 将数据打包编码 */
        $chunks = Flv::createFlvTag($tag);
        /** 发送 */
        self::write($chunks, $client);
    }

    /**
     * 发送音频帧
     * @param $audioFrame AudioFrame|MediaFrame
     * @return mixed
     */
    public static function sendAudioFrame($audioFrame, $client)
    {
        $tag = new FlvTag();
        $tag->type = Flv::AUDIO_TAG;
        $tag->timestamp = $audioFrame->timestamp;
        $tag->data = (string)$audioFrame;
        $tag->dataSize = strlen($tag->data);
        $chunks = Flv::createFlvTag($tag);
        self::write($chunks, $client);
    }

    /** 是否已经发送了第一个flv块*/
    public static $hasSendHeader = [];

    /** 使用网关播放的播放器是否发送了开始播放命令 */
    public static $hasStartPlay = [];

    /** 开始播放命令 */
    public static function startPlay($client)
    {
        /** 首先发送开播命令 */
        $flvHeader = "FLV\x01\x00" . pack('NN', 9, 0);
        $flvHeader[4] = chr(ord($flvHeader[4]) | 4);
        $flvHeader[4] = chr(ord($flvHeader[4]) | 1);
        self::write($flvHeader, $client);
        /** 发送完关键帧结束，才可以发送普通帧 */
        self::$hasStartPlay[(int)$client] = 1;
        var_dump("发送开播命令完成");
    }

    /**
     * 发送数据
     * @param $data
     * @return null
     * @comment 已验证过，此方法可以正确的传输flv数据，但是无法播放，那么问题就出在数据上，可能是数据转16进制，再转二进制出错了。
     */
    public static function write($data, $client)
    {
        /** 判断是否是发送第一个分块 */
        if (!isset(self::$hasSendHeader[(int)$client])) {
            /** 配置flv头 */
            $content = "HTTP/1.1 200 OK\r\n";
            $content .= "Cache-Control: no-cache\r\n";
            $content .= "Content-Type: video/x-flv\r\n";
            $content .= "Transfer-Encoding: chunked\r\n";
            $content .= "Connection: keep-alive\r\n";
            $content .= "Server: xiaosongshu\r\n";
            $content .= "Access-Control-Allow-Origin: *\r\n";
            $content .= "\r\n";
            /** 向浏览器发送数据 */
            $string = $content . \dechex(\strlen($data)) . "\r\n$data\r\n";
            /** 标记已发送过头部了 */
            self::$hasSendHeader[(int)$client] = 1;
        } else {
            /** 直接发送分块后的flv数据 */
            $string = \dechex(\strlen($data)) . "\r\n$data\r\n";
        }
        /** 不切片，直接发送，因为我怀疑如果切片，会导致浏览器拉去数据失败，掉帧，播放失败 */
        @fwrite($client, $string);
    }


    /**
     * 发送视频帧
     * @param $videoFrame VideoFrame|MediaFrame
     * @return mixed
     */
    public static function sendVideoFrame($videoFrame, $client)
    {
        $tag = new FlvTag();
        $tag->type = Flv::VIDEO_TAG;
        $tag->timestamp = $videoFrame->timestamp;
        $tag->data = (string)$videoFrame;
        $tag->dataSize = strlen($tag->data);
        $chunks = Flv::createFlvTag($tag);
        self::write($chunks, $client);
    }

    /** 客户端需要发送给服务端的数据队列 */
    public static array $client2ServerData = [];

    /**
     * 客户端向网关发送数据
     * @param resource $fd 网关服务器的链接
     * @comment 法相服务端一直可读，会一直发送数据，这里要判断，只有当有数据的时候才发送，不然对面服务器要崩溃
     */
    public function flvWrite($fd)
    {
        /** 一次发送一条命令，防止阻塞服务 */
        $buffer = array_shift(self::$client2ServerData);
        if (!empty($buffer)) {
            $path = $buffer['path'];
            /** 暂时只是通知服务端需要播放的资源 */
            fwrite($fd, "{$path}\r\n\r\n");
        }
    }

    /** 网关服务器读取客户端发送的数据暂存区 */
    public static string $gatewayServerBuffer = '';

    /**
     * 网关监测客户端可读事件
     * @param $fd
     * @comment 这里是主服务器
     */
    public function gatewayRead($fd)
    {
        if (is_resource($fd)) {
            $buffer = fread($fd, 15);
            self::$gatewayServerBuffer .= $buffer;
            if ($pos = strpos(self::$gatewayServerBuffer, "\r\n\r\n")) {
                /** 获取完整的内容 */
                $content = substr(self::$gatewayServerBuffer, 0, $pos + 4);
                /** 更新暂存区 */
                self::$gatewayServerBuffer = substr(self::$gatewayServerBuffer, $pos + 4);
                /** 暂时只传递了path ,后期可能会传递其他数据 */
                $array = explode("\r\n",$content);
                $path = $array[0];
                /** 按照路径将客户端分开保存 */
                RtmpDemo::$flvClientsInfo[$path][] = $fd;
                /** 在这里发送关键帧，确保客户端一定可以接收到关键帧 ，按路径分发 */
                $decodeFrame = [MediaServer::$metaKeyFrame[$path]??[],MediaServer::$avcKeyFrame[$path]??[],MediaServer::$aacKeyFrame[$path]??[]];
                if (in_array($fd,self::$flvClients)){
                    /** 客户端会掉帧，所以发两次解码关键帧 */
                    self::sendKeyFrame($fd,$decodeFrame);
                    self::sendKeyFrame($fd,$decodeFrame);
                    /** 然后发送一次连续帧 */
                    self::sendKeyFrame($fd,RtmpDemo::$gatewayImportantFrame[$path]??[]);
                }

            }
        }
    }
    /** 网关客户端相关的数据 */
    public static array $flvClientsInfo = [];
    /** 服务端网关缓存 */
    public static array $gatewayBuffer = [];
    /** 存放关键帧 ，当代理客户端链接后，立刻发送关键帧 */
    public static array $gatewayImportantFrame = [];


    /** 按固定长度切割字符串 */
    public static function splitString($str, $length)
    {
        $result = [];
        for ($i = 0; $i < strlen($str); $i += $length) {
            $result[] = substr($str, $i, $length);
        }
        return $result;
    }


    /**
     * 发送关键帧
     * @param $fd
     * @param $array
     */
    public static function sendKeyFrame($fd, $array)
    {

        foreach ($array as $buffer) {
            if (empty($buffer)){
                break;
            }
            if ($buffer['cmd'] == 'frame') {
                /** 保持数据的原始性，尽量不添加其他数据 */
                $type = $buffer['data']['type'];
                $timestamp = $buffer['data']['timestamp'];
                $data = $buffer['data']['frame'];
                $important = $buffer['data']['important'];
                $path = $buffer['data']['path'];
                $count = $buffer['data']['keyCount'];
                $seq = $buffer['data']['order'];
                if (in_array($seq,['aac','avc','meta'])){
                    var_dump($seq);
                }
                /** 使用http之类的文本分隔符 ，一整个报文之间用换行符分割 ，这个鸡儿协议真难搞 */
                $string = $type . "\r\n" . $timestamp . "\r\n" . $important . "\r\n" . $count . "\r\n" . $path . "\r\n" . $seq . "\r\n" . $data . "\r\n\r\n";
                /** 他么的这数据也太长了，将数据切片发送 */
                $stringArray = self::splitString($string, 1024);
                if (is_resource($fd)) {
                    foreach ($stringArray as $item) {
                        @fwrite($fd, $item);
                    }
                }
            }
        }
        var_dump("发送关键帧完成");
    }


    /**
     * 发送关键帧
     * @param MediaFrame $frame
     * @param string $path
     * @return void
     */
    public static function changeFrame2ArrayAndSend(MediaFrame $frame, string $path)
    {
        $newFrame = [
            'cmd' => 'frame',
            'socket' => null,
            'data' => [
                'path' => $path,
                'frame' => $frame->_buffer,
                'timestamp' => $frame->timestamp ?? 0,
                'type' => $frame->FRAME_TYPE,
                'important' => 1,
                'order' => 4,
                'keyCount' => 0
            ]
        ];

        /** 发送 */
        RtmpDemo::$gatewayBuffer[$path][] = $newFrame;
        /** 更新关键帧 I帧 P帧 */
        RtmpDemo::$gatewayImportantFrame[$path][] = $newFrame;
    }

    /**
     * 网关监测客户端可写事件
     * @param $fd
     * @comment 这里是服务器发送给客户端
     * @note  这里还应该将客户端按路径区分
     */
    public function gatewayWrite($fd)
    {
        /** 然后发送普通帧，区分了路径之后，按道理应该每一个进程或者线程单独处理一路数据，
         * 但是，这里是单进程，那就循环处理每一路资源，可能性能会下降，但是不管了，这里只管实现功能，不管性能了
         */
        foreach (self::$gatewayBuffer as $path => $buffers) {
            $buffer = array_shift($buffers);
            if (!empty($buffer)) {
                if ($buffer['cmd'] == 'frame') {
                    /** 保持数据的原始性，尽量不添加其他数据 */
                    $type = $buffer['data']['type'];
                    $timestamp = $buffer['data']['timestamp'];
                    $data = $buffer['data']['frame'];
                    $important = $buffer['data']['important'];
                    $seq = $buffer['data']['order'];
                    $path = $buffer['data']['path'];
                    /** 使用http之类的文本分隔符 ，一整个报文之间用换行符分割 ，这个鸡儿协议真难搞 */
                    $string = $type . "\r\n" . $timestamp . "\r\n" . $important . "\r\n0\r\n" . $path . "\r\n" . $seq . "\r\n" . $data . "\r\n\r\n";

                    /** 他么的这数据也太长了，将数据切片发送 */
                    $stringArray = self::splitString($string, 1024);
                    if (is_resource($fd)) {
                        foreach ($stringArray as $item) {
                            try {
                                @fwrite($fd, $item);
                            } catch (\Exception $exception) {
                                unset(RtmpDemo::$allSocket[(int)$fd]);
                                break;
                            }

                        }
                    }
                }
            }
        }

    }

    /** 已发送关键帧 */
    public static array $hasSendKeyFrame = [];

    public static int $playKeyFrameLimit = 1000;

    /**
     * 向播放器推送关键帧
     * @param $client
     * @return void
     */
    public static function sendKeyFrameToPlayer($client, $path)
    {
        if (isset(self::$importantFrame[$path])) {

            /** 先发送解码的关键帧 */
            if (isset(self::$seqs[$path])) {
                foreach (self::$seqs[$path] as $frame) {
                    self::frameSend($frame, $client);
                }
                var_dump("发送解码命令完成" . count(self::$seqs[$path]));
            } else {
                var_dump("解码的关键帧不存在");
            }

            /** 再发送其他的关键帧 */
            $count = count(self::$importantFrame[$path]);
            /** 只发送最近的self::$playKeyFrameLimit帧 ，并且更新关键帧，防止内存溢出，同时保证有足够的关键帧解码 */
            if ($count > self::$playKeyFrameLimit) {
                self::$importantFrame[$path] = array_slice(self::$importantFrame[$path], $count - self::$playKeyFrameLimit, self::$playKeyFrameLimit);
            }

            foreach (self::$importantFrame[$path] as $frame) {
                self::frameSend($frame, $client);
            }

            self::$hasSendKeyFrame[$path][(int)$client] = 1;
            var_dump("发送关键帧完成" . count(self::$importantFrame[$path]));
        }
    }

    /** 链接到本flv服务器的客户端 */
    public static array $flvClients = [];

    /** 播放器客户端 */
    public static array $playerClients = [];

    public static array $clientTcpConnections = [];

    /**
     * 接受客户端的链接，并处理数据
     */
    private function acceptFlv(): void
    {
        /** 创建多个子进程阻塞接收服务端socket 这个while死循环 会导致for循环被阻塞，不往下执行，创建了子进程也没有用，直接在第一个子进程哪里阻塞了 */
        while (true) {
            /** 初始化需要监测的可写入的客户端，需要排除的客户端都为空 */
            $except = [];
            /** 需要监听socket，自动清理已报废的链接 */
            foreach (self::$allSocket as $key => $value) {
                if (!is_resource($value)) {
                    /** 如果客户端掉线了，那么需要重新创建一个代理客户端 */
                    if ($value == self::$flvClient) {
                        $this->createFlvClient();
                    }
                    /** 删除已掉线的所有客户端 */
                    unset(self::$allSocket[$key]);
                }
            }
            $write = $read = self::$allSocket;
            /** 使用stream_select函数监测可读，可写的连接，如果某一个连接接收到数据，那么数据就会改变，select使用的foreach遍历所有的连接，查看是否可读，就是有消息的时候标记为可读 */
            /** 这里设置了阻塞60秒 */
            try {
                stream_select($read, $write, $except, 60);
            } catch (\Exception $exception) {
                logger()->error($exception->getMessage());
            }

            /** 处理可读的链接 */
            if ($read) {
                foreach ($read as $fd) {
                    $fd_key = (int)$fd;
                    /** 处理多个服务端的链接 */
                    //if (in_array($fd, $this->serverSocket)) {
                    if (in_array($fd, [self::$flvServerSocket])) {
                        /** 读取服务端接收到的 消息，这个消息的内容是客户端连接 ，stream_socket_accept方法负责接收客户端连接 */
                        $clientSocket = stream_socket_accept($fd, 0, $remote_address); //阻塞监听 设置超时0，并获取客户端地址
                        /** 如果这个客户端连接不为空 给链接绑定可读事件，绑定协议类型，而不同的协议绑定了不同的数据处理方式 */
                        if (!empty($clientSocket)) {
                            try {
                                /** 使用tcp解码器 */
                                $connection = new TcpConnection($clientSocket, $remote_address);
                                /** 通信协议 */
                                $connection->transport = $this->transport;
                                /** 播放器链接这个代理服务器 */
                                /** 如果是flv的链接 就设置为http的协议 flv是长链接 */
                                if (self::$flvServerSocket && $fd == self::$flvServerSocket) {
                                    $connection->protocol = \MediaServer\Http\ExtHttpProtocol::class;
                                    /** 支持http的flv播放 onMessage事件处理请求数据，使用ExtHttp协议处理数据， */
                                    $connection->onMessage = [new HttpWMServer(), 'onHttpRequest'];
                                    /** 支持ws的flv播放 onWebSocketConnect事件处理请求数据 ，如果是ws链接，
                                     * ExtHttpProtocol协议自动切换为ws链接，然后在握手后调用ws链接事件，添加播放设备，返回握手信息 ，
                                     * 后续媒体MediaServer使用ws链接返回媒体数据给链接
                                     */
                                    $connection->onWebSocketConnect = [new HttpWMServer(), 'onWebsocketRequest'];

                                    new \MediaServer\Http\ExtHttpProtocol($connection);
                                    /** 保存播放器客户端 */
                                    self::$playerClients[(int)$clientSocket] = $clientSocket;
                                    /** 保存链接 */
                                    self::$clientTcpConnections[(int)$clientSocket] = $connection;
                                }

                            } catch (\Exception|\RuntimeException $exception) {
                                logger()->error($exception->getMessage());
                            }
                        }
                        /** 将这个客户端连接保存，目测这里如果不保存，应该是无法发送和接收消息的，就是要把所有的连接都保存在内存中 */
                        RtmpDemo::$allSocket[(int)$clientSocket] = $clientSocket;


                    } else {

                        /** 已经是建立过的链接，则直接该链接的读事件 */
                        if (isset($this->_allEvents[$fd_key][self::EV_READ])) {

                            \call_user_func_array(
                                $this->_allEvents[$fd_key][self::EV_READ][0],
                                array($this->_allEvents[$fd_key][self::EV_READ][1])
                            );
                        }
                    }

                }
            }
            /** 处理可写的链接 */
            if ($write) {
                foreach ($write as $fd) {
                    $fd_key = (int)$fd;
                    /** 调用预定义的可写回调函数 */
                    if (isset($this->_allEvents[$fd_key][self::EV_WRITE])) {
                        \call_user_func_array(
                            $this->_allEvents[$fd_key][self::EV_WRITE][0],
                            array($this->_allEvents[$fd_key][self::EV_WRITE][1])
                        );
                    }
                }
            }
        }
    }
}
