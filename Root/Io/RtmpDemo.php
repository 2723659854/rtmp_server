<?php

namespace Root\Io;

use Root\Protocols\Http;
use Root\Request;
use Root\Response;
use Root\rtmp\TcpConnection;

/**
 * @purpose 使用了select的IO多路复用模型
 */
class RtmpDemo
{
    /** 设置接收http数据的回调 */
    public $onMessage = NULL;

    /** 设置接收ws数据的回调 */
    public $onWebSocketConnect = null;

    /** 存放所有socket 注意内存泄漏 */
    public static $allSocket;

    /** @var string $host 监听的ip */
    private $host = '0.0.0.0';

    /** @var string $port RTMP监听的端口 可修改 */
    public $rtmpPort = '1935';

    /** @var string $flvPort flv监听端口 可修改 */
    public $flvPort = '8501';

    /** @var string $webPort web端口 */
    public $webPort = '80';

    /** @var string $protocol 通信协议 */
    private $protocol = 'tcp';

    /** rtmp服务器实例 */
    public static $instance = null;

    /**
     * 读事件
     * @var int
     */
    const EV_READ = 1;

    /**
     * 写事件
     * @var int
     */
    const EV_WRITE = 2;

    /** 所有的事件 */
    private array $_allEvents = [];

    /** 读事件 */
    private array $_readFds = [];

    /** 写事件 */
    private array $_writeFds = [];

    /** flv服務端 */
    private static $flvServerSocket = null;

    /** web服务器 */
    private static $webServerSocket = null;

    /**
     * PHP 默认支持的协议
     * @var array
     */
    protected static $_builtinTransports = array(
        'tcp' => 'tcp',
        'udp' => 'udp',
        'unix' => 'unix',
        'ssl' => 'tcp'
    );

    /** @var string $transport 默认通信传输协议 */
    public $transport = 'tcp';

    /** 监听地址 */
    public string $listeningAddress = '';

    /** 服务端socket */
    private array $serverSocket = [];

    /**
     * 初始化
     * RtmpDemo constructor.
     */
    public function __construct()
    {
    }

    /**
     * 解析协议和监听地址
     * @param string $socketName 协议名称
     * @return string|void
     * @throws \Exception
     */
    public function parseSocketAddress($socketName)
    {
        if (!$socketName) {
            return;
        }
        /** 获取协议类型和监听地址 */
        list($scheme, $address) = \explode(':', $socketName, 2);
        /** 如果不是php自带的协议类型 */
        if (!isset(static::$_builtinTransports[$scheme])) {
            $scheme = \ucfirst($scheme);
            /** 加载扩展协议 */
            $this->protocol = \substr($scheme, 0, 1) === '\\' ? $scheme : 'Protocols\\' . $scheme;
            if (!isset(static::$_builtinTransports[$this->transport])) {
                /** 不支持的协议 */
                throw new \Exception('Bad transport ' . \var_export($this->transport, true));
            }
        } else {
            $this->transport = $scheme;
        }
        /** 返回监听地址 */
        return static::$_builtinTransports[$this->transport] . ":" . $address;
    }

    /**
     * 添加读写事件
     * @param resource $fd socket链接
     * @param int $flag 读写类型
     * @param array $func 回调函数
     * @return bool
     */
    public function add($fd, $flag, $func)
    {
        switch ($flag) {
            case self::EV_READ:
            case self::EV_WRITE:
                $count = $flag === self::EV_READ ? \count($this->_readFds) : \count($this->_writeFds);
                if ($count >= 1024) {
                    echo "系统最大支持1024个链接\n";
                } else if (\DIRECTORY_SEPARATOR !== '/' && $count >= 256) {
                    echo "系统调用选择超出了最大连接数256\n";
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
    public function del($fd, $flag)
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
     * 创建服务器
     * @return false|resource
     */
    private function createServer()
    {
        /** @var string $listeningAddress 拼接监听地址 */
        if ($this->listeningAddress) {
            $listeningAddress = $this->listeningAddress;
        } else {
            $listeningAddress = $this->protocol . '://' . $this->host . ':' . $this->rtmpPort;
        }
        echo "开始监听{$listeningAddress}\r\n";
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
     * 创建flv播放服务
     * @return void
     */
    public function createFlvSever()
    {
        /** 保存flv服务端的socket */
        self::$flvServerSocket = $this->createServer();
    }

    /**
     * 创建rtmp服务
     */
    private function createRtmpServer()
    {
        $this->createServer();
    }

    /**
     * 获取实例
     * @return self|null
     */
    public static function instance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 创建web服务
     * @return false|resource
     * @note 本项目只提供hls相关文件下载，不提供其他的web服务
     */
    public function createWebServer()
    {
        /** @var string $listeningAddress 拼接监听地址 */
        $listeningAddress = 'tcp://' . $this->host . ':' . $this->webPort;
        echo "开始监听{$listeningAddress}\r\n";
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
        /** 保存web服务器 */
        self::$webServerSocket = $socket;
        /** 返回服务器实例 */
        return $socket;

    }

    /**
     * 检查是否安装ffmpeg
     * @return bool
     */
    public function hasFfmpeg()
    {
        // 执行ffmpeg命令并捕获输出
        $output = shell_exec('ffmpeg -version 2>&1');
        // 检查输出中是否包含FFmpeg的版本信息
        if (strpos($output, 'ffmpeg version') !== false) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * 启动服务
     */
    public function start()
    {
        /** 开启rtmp 服务 */
        $this->createRtmpServer();
        /** 启动flv服务 */
        $this->startFlv();
        /** 创建web服务器 */
        $this->createWebServer();
        /** 开始接收客户端请求 */
        $this->accept();
    }

    /**
     * 启动flv服务
     * @return void
     * @comment 就是再添加一个监听地址
     */
    private function startFlv()
    {
        new \MediaServer\Http\HttpWMServer("\\MediaServer\\Http\\ExtHttpProtocol://0.0.0.0:" . $this->flvPort, $this);
    }


    /**
     * 接受客户端的链接，并处理数据
     */
    private function accept()
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
                        /** 如果这个客户端连接不为空 */
                        if (!empty($clientSocket)) {
                            try {
                                /** 使用tcp解码器 */
                                $connection = new TcpConnection($clientSocket, $remote_address);
                                /** 通信协议 */
                                $connection->transport = $this->transport;
                                /** 如果是flv的链接 就设置为http的协议 */
                                if (self::$flvServerSocket && $fd == self::$flvServerSocket) {
                                    $connection->protocol = \MediaServer\Http\ExtHttpProtocol::class;
                                    /** 支持http的flv播放 这个onMessage事件在创建flv服务器的时候被定义过 */
                                    $connection->onMessage = $this->onMessage;
                                    /** 支持ws的flv播放 这个也是在创建flv服务器的时候被定义过 */
                                    $connection->onWebSocketConnect = $this->onWebSocketConnect;
                                }
                                /** web服务器使用http协议 */
                                if (self::$webServerSocket && $fd == self::$webServerSocket){
                                    $connection->protocol = Http::class;
                                    $connection->onMessage = function ($connection,Request  $request){
                                        /** 获取文件的路径 */
                                        $path = $request->path();
                                        $file = dirname(dirname(__DIR__)).'/hls/'.$path;
                                        if (is_file($file)){
                                            /** 允许跨域 */
                                            $response = new Response(200,['Access-Control-Allow-Origin'=>'*']);
                                            /** 返回文件 */
                                            $response->file($file);
                                            /** 发送文件 */
                                            $connection->send($response);
                                        }else{
                                            /** 返回404 */
                                            $connection->send(new Response(404,['Access-Control-Allow-Origin'=>'*'],'not found'));
                                        }
                                    };
                                }
                                /** 处理rtmp链接的数据 */
                                new \MediaServer\Rtmp\RtmpStream(
                                /** 使用自定义协议处理传递过来的数据 */
                                    new \MediaServer\Utils\WMBufferStream($connection)
                                );
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
