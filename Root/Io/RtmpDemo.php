<?php

namespace Root\Io;

use Root\rtmp\TcpConnection;

/**
 * @purpose select的IO多路复用模型
 * @note 提供http服务器服务
 * @note 提供异步客户端服务
 */
class RtmpDemo
{
    /** 服务端 */
    protected $socket = NULL;

    /** 设置连接回调事件 */
    public $onConnect = NULL;

    /** 设置接收消息回调 */
    public $onMessage = NULL;

    /** 启动事件 */
    public $onWorkerStart = null;

    /** ws链接事件 */
    public $onWebSocketConnect = null;

    /** 存放所有socket */
    public static $allSocket;

    /** @var string $host 监听的ip和协议 */
    public $host = '0.0.0.0';

    /** @var string $port 监听的端口 */
    public $port = '1935';

    /** @var string $protocol 通信协议 */
    public $protocol = 'tcp';

    /** 异步http客户端 */
    private static $success = [];
    /** 需要发送的请求 */
    private static $request = [];
    /** 异步http客户端 */
    private static $fail = [];

    /** 异步请求的 原始数据 */
    public static $asyncRequestData = [];

    /** 客户端上传数据最大请求时间 ，如果超过这个时间就断开这个连接 默认6分钟 */
    private static $maxRequestTime = 360;

    public static $instance = null;

    /**
     * Read event.
     *
     * @var int
     */
    const EV_READ = 1;

    /**
     * Write event.
     *
     * @var int
     */
    const EV_WRITE = 2;

    /** 所有的事件 */
    public array $_allEvents = [];

    /** 读事件 */
    public array $_readFds = [];

    /** 写事件 */
    public array $_writeFds = [];

    /** 添加事件 */
    public function add($fd, $flag, $func, $args = array())
    {
        switch ($flag) {
            case self::EV_READ:
            case self::EV_WRITE:
                $count = $flag === self::EV_READ ? \count($this->_readFds) : \count($this->_writeFds);
                if ($count >= 1024) {
                    //echo "Warning: system call select exceeded the maximum number of connections 1024, please install event/libevent extension for more connections.\n";
                    echo "系统最大支持1024个链接\n";
                } else if (\DIRECTORY_SEPARATOR !== '/' && $count >= 256) {
                    echo "Warning: system call select exceeded the maximum number of connections 256.\n";
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
     * Parse local socket address.
     *
     * @throws \Exception
     */
    public function parseSocketAddress()
    {
        if (!$this->_socketName) {
            return;
        }
        // Get the application layer communication protocol and listening address.
        list($scheme, $address) = \explode(':', $this->_socketName, 2);
        // Check application layer protocol class.
        if (!isset(static::$_builtinTransports[$scheme])) {
            $scheme = \ucfirst($scheme);
            $this->protocol = \substr($scheme, 0, 1) === '\\' ? $scheme : 'Protocols\\' . $scheme;
            var_dump($this->protocol);
            if (!isset(static::$_builtinTransports[$this->transport])) {
                throw new \Exception('Bad worker->transport ' . \var_export($this->transport, true));
            }
        } else {
            $this->transport = $scheme;
        }
        //local socket
        return static::$_builtinTransports[$this->transport] . ":" . $address;
    }




    /**
     * PHP built-in protocols.
     *
     * @var array
     */
    protected static $_builtinTransports = array(
        'tcp' => 'tcp',
        'udp' => 'udp',
        'unix' => 'unix',
        'ssl' => 'tcp'
    );

    public $transport = 'tcp';

    /**
     * Unix user of processes, needs appropriate privileges (usually root).
     *
     * @var string
     */
    public $user = '';

    /**
     * Unix group of processes, needs appropriate privileges (usually root).
     *
     * @var string
     */
    public $group = '';

    /**
     * Context of socket.
     *
     * @var resource
     */
    protected $_context = null;

    protected $_socketName = '';
    /**
     * reuse port.
     *
     * @var bool
     */
    public $reusePort = true;

    /**
     * Listening socket.
     *
     * @var resource
     */
    protected $_mainSocket = null;

    /** 删除事件 */
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

    /** 静态化调用 */
    public static function __callStatic($name, $arguments)
    {
        return RtmpDemo::instance()->{$name}(...$arguments);
    }

    const DEFAULT_BACKLOG = 102400;

    public function init($socket_name = '', array $context_option = array())
    {
        // Context for socket.
        if ($socket_name) {
            $this->_socketName = $socket_name;
            if (!isset($context_option['socket']['backlog'])) {
                $context_option['socket']['backlog'] = static::DEFAULT_BACKLOG;
            }
            $this->_context = \stream_context_create($context_option);
        }
    }

    /**
     * Get socket name.
     *
     * @return string
     */
    public function getSocketName()
    {
        return $this->_socketName ? \lcfirst($this->_socketName) : 'none';
    }

    /** 监听地址 */
    public string $listeningAddress = '';

    /** 服务端socket */
    public array $serverSocket = [];

    /** 初始化 */
    public function __construct()
    {
        /** @var string $listeningAddress 拼接监听地址 */
        if ($this->listeningAddress) {
            $listeningAddress = $this->listeningAddress;
        } else {
            $listeningAddress = $this->protocol . '://' . $this->host . ':' . $this->port;
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
    }

    public function startFlv()
    {
        /** @var string $listeningAddress 拼接监听地址 */
        if ($this->listeningAddress) {
            $listeningAddress = $this->listeningAddress;
        } else {
            $listeningAddress = $this->protocol . '://' . $this->host . ':' . $this->port;
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

        self::$flvServerSocket = $socket;
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

    /** 启动服务 */
    public function start()
    {
        if ($this->onWorkerStart){
            call_user_func($this->onWorkerStart,$this);
        }
        /** 调试模式 */
        $this->accept();
    }


    /** 所有的flv播放器客戶端 */
    public static array $flvClients = [];
    /** flv服務端 */
    public static $flvServerSocket = null;
    /** 接收客户端消息 */
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
                var_dump($exception->getMessage());
                debug_print_backtrace();
            }

            /** 处理可读的链接 */
            if ($read) {
                foreach ($read as $fd) {
                    $fd_key = (int)$fd;
                    /** 处理多个服务端的链接 */
                    if (in_array($fd,$this->serverSocket)) {
                        /** 读取服务端接收到的 消息，这个消息的内容是客户端连接 ，stream_socket_accept方法负责接收客户端连接 */
                        $clientSocket = stream_socket_accept($fd, 0, $remote_address); //阻塞监听 设置超时0，并获取客户端地址
                        /** 把flv的客戶端單獨保存有用 */
                        if (self::$flvServerSocket && $fd == self::$flvServerSocket){
                            self::$flvClients[(int)$clientSocket] = $clientSocket;
                        }
                        //触发事件的连接的回调
                        /** 如果这个客户端连接不为空，并且本服务的onConnect是回调函数 */
                        if (!empty($clientSocket) && is_callable($this->onConnect)) {
                            /** 把客户端连接传递到onConnect回调函数 */
                            try {
                                $connection = new TcpConnection($clientSocket, $remote_address);
                                $connection->protocol               = $this->protocol;
                                //$connection->protocol               = "\MediaServer\Http\ExtHttpProtocol";
                                $connection->transport              = $this->transport;
                                /** 支持http的flv播放 */
                                $connection->onMessage              = $this->onMessage;
                                /** 支持ws的flv播放 */
                                $connection->onWebSocketConnect = $this->onWebSocketConnect;
                                call_user_func($this->onConnect, $connection);
                            } catch (\Exception|\RuntimeException $exception) {
                                self::dumpError($exception);
                            }

                        }
                        /** 将这个客户端连接保存，目测这里如果不保存，应该是无法发送和接收消息的，就是要把所有的连接都保存在内存中 */
                        RtmpDemo::$allSocket[(int)$clientSocket] = $clientSocket;
                    } else {
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


    /**
     * 打印系统异常信息
     * @param $exception
     * @return void
     * @note 应该记录到日志的
     */
    private static function dumpError($exception)
    {
        //var_dump("发生错误",$exception->getCode(),$exception->getFile(),$exception->getLine(),$exception->getMessage());
        dump_error($exception);
    }

}
