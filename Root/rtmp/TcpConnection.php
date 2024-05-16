<?php

namespace Root\rtmp;

use Root\Io\RtmpDemo;

/**
 * @purpose 这里是tcp协议操作类
 */
class TcpConnection extends ConnectionInterface
{
    /**
     * 最大缓存
     * @var int
     */
    const READ_BUFFER_SIZE = 65535;

    /**
     * 初始化状态
     * @var int
     */
    const STATUS_INITIAL = 0;

    /**
     * 连接中
     * @var int
     */
    const STATUS_CONNECTING = 1;

    /**
     * 链接已建立
     * @var int
     */
    const STATUS_ESTABLISHED = 2;

    /**
     * 链接关闭中
     * @var int
     */
    const STATUS_CLOSING = 4;

    /**
     * 链接已关闭
     * @var int
     */
    const STATUS_CLOSED = 8;

    /**
     * 接收到数据回调函数
     * @var callable
     */
    public $onMessage = null;

    /**
     * @var null ws链接处理事件
     */
    public $onWebSocketConnect = null;

    /**
     * 当tcp发送fin新号的时候触发这个回调函数
     * @var callable
     */
    public $onClose = null;

    /**
     * 当发生错误的时候触发这个回调
     * @var callable
     */
    public $onError = null;

    /**
     * 当缓存区满的时候触发
     * @var callable
     */
    public $onBufferFull = null;

    /**
     * 当缓存区已空闲的时候触发
     * @var callable
     */
    public $onBufferDrain = null;

    /**
     * 使用的协议
     * @var ProtocolInterface
     */
    public $protocol = null;

    /**
     * Transport (tcp/udp/unix/ssl).
     * 数据传输协议
     * @var string
     */
    public $transport = 'tcp';

    /**
     * 链接所属工作进程
     */
    public $worker = null;

    /**
     * 已读字节数
     * @var int
     */
    public $bytesRead = 0;

    /**
     * 已写字节数
     * @var int
     */
    public $bytesWritten = 0;

    /**
     * 链接的id
     * @var int
     */
    public $id = 0;

    /**
     * 复制的上面的id,当客户端被复制的时候
     * @var int
     */
    protected $_id = 0;

    /**
     * 最大发送长度，如果达到这个字节，上面的暂存区满函数会被触发
     * @var int
     */
    public $maxSendBufferSize = 1048576;

    /**
     * 内容
     * @var object|null
     */
    public $context = null;

    /**
     * 默认最大发送长度
     * @var int
     */
    public static $defaultMaxSendBufferSize = 1048576;

    /**
     * 当前链接最大包长度
     * @var int
     */
    public $maxPackageSize = 1048576;

    /**
     * 默认链接最大包长度
     * @var int
     */
    public static $defaultMaxPackageSize = 10485760;

    /**
     * id记录器
     * @var int
     */
    protected static $_idRecorder = 1;

    /**
     * socket链接
     * @var resource
     */
    protected $_socket = null;

    /**
     * 发送暂存数据
     * @var string
     */
    protected $_sendBuffer = '';

    /**
     * 接收暂存数据
     * @var string
     */
    protected $_recvBuffer = '';

    /**
     * 当前包长度
     * @var int
     */
    protected $_currentPackageLength = 0;

    /**
     * 链接状态
     * @var int
     */
    protected $_status = self::STATUS_ESTABLISHED;

    /**
     * 客户端地址
     * @var string
     */
    protected $_remoteAddress = '';

    /**
     * 暂停状态？
     * @var bool
     */
    protected $_isPaused = false;

    /**
     * SSL 完成握手与否
     *
     * @var bool
     */
    protected $_sslHandshakeCompleted = false;

    /**
     * 当前实例所有的链接
     * @var array
     */
    public static $connections = array();

    /**
     * 状态码字典
     * @var array
     */
    public static $_statusToString = array(
        self::STATUS_INITIAL     => 'INITIAL',
        self::STATUS_CONNECTING  => 'CONNECTING',
        self::STATUS_ESTABLISHED => 'ESTABLISHED',
        self::STATUS_CLOSING     => 'CLOSING',
        self::STATUS_CLOSED      => 'CLOSED',
    );

    /**
     * 初始化
     * @param resource $socket 客户端链接
     * @param string   $remote_address 客户端地址
     */
    public function __construct($socket, $remote_address = '')
    {
        /** 连接数+1 */
        ++self::$statistics['connection_count'];
        /** 给每一个连接分配一个id */
        $this->id = $this->_id = self::$_idRecorder++;
        /** 如果已经到了php的最大整数 归零 */
        if(self::$_idRecorder === \PHP_INT_MAX){
            self::$_idRecorder = 0;
        }
        /** 保存链接 */
        $this->_socket = $socket;
        /** 设置非阻塞，语法是关闭阻塞 异步 */
        \stream_set_blocking($this->_socket, 0);
        // 与hhvm兼容 就是与php被编译后的高级别字节码兼容
        if (\function_exists('stream_set_read_buffer')) {
            /** 设置指定流的读取缓冲区大小 就是不缓存数据，直接读取 ，流式读取 */
            \stream_set_read_buffer($this->_socket, 0);
        }
        /** 给这个连接设置可读事件回调函数，就是这个链接可读的时候，Io模型调用baseRead事件来读取数据 */
        RtmpDemo::instance()->add($this->_socket, EventInterface::EV_READ, array($this, 'baseRead'));
        /** 初始化链接的暂存区大小，包大小 ，并保存客户端地址 */
        $this->maxSendBufferSize        = self::$defaultMaxSendBufferSize;
        $this->maxPackageSize           = self::$defaultMaxPackageSize;
        $this->_remoteAddress           = $remote_address;
        /** 保存链接 */
        static::$connections[$this->id] = $this;
        /** 初始化链接的内容为空 */
        $this->context = new \stdClass;
    }

    /**
     * 获取链接的状态
     * @param bool $raw_output 是否返回原始状态码
     *
     * @return int|string
     */
    public function getStatus($raw_output = true)
    {
        if ($raw_output) {
            return $this->_status;
        }
        return self::$_statusToString[$this->_status];
    }

    /**
     * 向客户端发送数据
     * @param mixed $send_buffer 需要发送的内容
     * @param bool  $raw 是否发送原始数据，就是是否需要编码
     * @return bool|null
     */
    public function send($send_buffer, $raw = false)
    {
        /** 链接被关闭，不发送 */
        if ($this->_status === self::STATUS_CLOSING || $this->_status === self::STATUS_CLOSED) {
            return false;
        }

        /** 在发送之前对数据进行编码 */
        if (false === $raw && $this->protocol !== null) {
            $parser      = $this->protocol;
            $send_buffer = $parser::encode($send_buffer, $this);
            if ($send_buffer === '') {
                return;
            }
        }

        /** 如果链接尚未建立 并且协议是ssl ，需要加密，那就要先建立ssl链接 */
        if ($this->_status !== self::STATUS_ESTABLISHED ||
            ($this->transport === 'ssl' && $this->_sslHandshakeCompleted !== true)
        ) {
            if ($this->_sendBuffer && $this->bufferIsFull()) {
                ++self::$statistics['send_fail'];
                return false;
            }
            /** 暂存数据 */
            $this->_sendBuffer .= $send_buffer;
            $this->checkBufferWillFull();
            return;
        }

        /** 如果暂存区是空的 */
        // Attempt to send data directly.
        if ($this->_sendBuffer === '') {
            /** 如果传输协议是ssl */
            if ($this->transport === 'ssl') {
                /** 添加baseWrite事件 IO模型在链接可写的时候触发这个函数 */
                RtmpDemo::instance()->add($this->_socket, EventInterface::EV_WRITE, array($this, 'baseWrite'));
                /** 暂存数据 */
                $this->_sendBuffer = $send_buffer;
                $this->checkBufferWillFull();
                return;
            }
            /** 初始化长度为0 */
            $len = 0;
            try {
                /** 向客户端发送数据 */
                $len = @\fwrite($this->_socket, $send_buffer);
            } catch (\Exception $e) {

                var_dump($e->getMessage());
            }
            /** 发送成功，更新已发送长度 */

            if ($len === \strlen($send_buffer)) {
                $this->bytesWritten += $len;
                return true;
            }
            /** 如果只发送了一部分数据 ，就要更新待发送数据，更新暂存区数据 */

            if ($len > 0) {
                $this->_sendBuffer = \substr($send_buffer, $len);
                $this->bytesWritten += $len;
            } else {
                /** 如果链接已经关闭了 */
                if (!\is_resource($this->_socket) || \feof($this->_socket)) {
                    ++self::$statistics['send_fail'];
                    /** 触发错误事件 */
                    if ($this->onError) {
                        try {
                            \call_user_func($this->onError, $this, 2, 'client closed');
                        } catch (\Exception $e) {
                            
                            var_dump($e->getMessage());
                        }
                    }
                    /** 销毁链接 */
                    $this->destroy();
                    return false;
                }
                /** 还原暂存区数据 */
                $this->_sendBuffer = $send_buffer;
            }
            /** 给客户端添加可写事件，当客户端链接空闲的时候，发送数据 */
            RtmpDemo::instance()->add($this->_socket, EventInterface::EV_WRITE, array($this, 'baseWrite'));
            // Check if the send buffer will be full.
            $this->checkBufferWillFull();
            return;
        }

        if ($this->bufferIsFull()) {
            ++self::$statistics['send_fail'];
            return false;
        }

        $this->_sendBuffer .= $send_buffer;
        // Check if the send buffer is full.
        $this->checkBufferWillFull();
    }

    /**
     * 获取客户端IP
     * @return string
     */
    public function getRemoteIp()
    {
        $pos = \strrpos($this->_remoteAddress, ':');
        if ($pos) {
            return (string) \substr($this->_remoteAddress, 0, $pos);
        }
        return '';
    }

    /**
     * 获取客户端端口
     * @return int
     */
    public function getRemotePort()
    {
        if ($this->_remoteAddress) {
            return (int) \substr(\strrchr($this->_remoteAddress, ':'), 1);
        }
        return 0;
    }

    /**
     * 获取客户端地址
     * @return string
     */
    public function getRemoteAddress()
    {
        return $this->_remoteAddress;
    }

    /**
     * 获取本机IP
     * @return string
     */
    public function getLocalIp()
    {
        $address = $this->getLocalAddress();
        $pos = \strrpos($address, ':');
        if (!$pos) {
            return '';
        }
        return \substr($address, 0, $pos);
    }

    /**
     * 获取本机端口
     * @return int
     */
    public function getLocalPort()
    {
        $address = $this->getLocalAddress();
        $pos = \strrpos($address, ':');
        if (!$pos) {
            return 0;
        }
        return (int)\substr(\strrchr($address, ':'), 1);
    }

    /**
     * 获取本机地址
     * @return string
     */
    public function getLocalAddress()
    {
        if (!\is_resource($this->_socket)) {
            return '';
        }
        return (string)@\stream_socket_get_name($this->_socket, false);
    }

    /**
     * 获取暂存区数据长度
     * @return integer
     */
    public function getSendBufferQueueSize()
    {
        return \strlen($this->_sendBuffer);
    }

    /**
     * 获取接收数据暂存区数据长度
     * @return integer
     */
    public function getRecvBufferQueueSize()
    {
        return \strlen($this->_recvBuffer);
    }

    /**
     * 是否ip4
     * return bool.
     */
    public function isIpV4()
    {
        if ($this->transport === 'unix') {
            return false;
        }
        return \strpos($this->getRemoteIp(), ':') === false;
    }

    /**
     * 是否Ip6
     * return bool.
     */
    public function isIpV6()
    {
        if ($this->transport === 'unix') {
            return false;
        }
        return \strpos($this->getRemoteIp(), ':') !== false;
    }

    /**
     * 暂停链接，删除这个链接的可读事件，不再读取链接的数据
     * @return void
     */
    public function pauseRecv()
    {

        RtmpDemo::instance()->del($this->_socket, EventInterface::EV_READ);

        $this->_isPaused = true;
    }

    /**
     * 恢复链接，
     * @return void
     */
    public function resumeRecv()
    {
        if ($this->_isPaused === true) {
            /** 给链接添加可读事件 */
            RtmpDemo::instance()->add($this->_socket, EventInterface::EV_READ, array($this, 'baseRead'));
            $this->_isPaused = false;
            /** 并且立即读取数据 */
            $this->baseRead($this->_socket, false);
        }
    }



    /**
     * 读取链接的数据
     * @param resource $socket 链接
     * @param bool $check_eof 是否检查已接收完数据
     * @return void
     */
    public function baseRead($socket, $check_eof = true)
    {
        /** ssl 握手 */
        if ($this->transport === 'ssl' && $this->_sslHandshakeCompleted !== true) {
            if ($this->doSslHandshake($socket)) {
                $this->_sslHandshakeCompleted = true;
                /** 如果有可发送数据则添加可写事件 */
                if ($this->_sendBuffer) {
                    RtmpDemo::instance()->add($socket, EventInterface::EV_WRITE, array($this, 'baseWrite'));
                }
            } else {
                return;
            }
        }
        /** 读取的数据 */
        $buffer = '';
        try {
            /** 按最大长度读取数据 */
            $buffer = @\fread($socket, self::READ_BUFFER_SIZE);
        } catch (\Exception $e) {}

        /** 如果数据为空 */
        // Check connection closed.
        if ($buffer === '' || $buffer === false) {
            /** 如果要检查 ，关闭链接 */
            if ($check_eof && (\feof($socket) || !\is_resource($socket) || $buffer === false)) {
                $this->destroy();
                return;
            }
        } else {
            /** 更新已读取长度 ，更新暂存区数据 */
            $this->bytesRead += \strlen($buffer);
            $this->_recvBuffer .= $buffer;
        }
        /** 获取这个链接的通信协议 */
        if ($this->protocol !== null) {

            /** 這裡的協議出現了問題 */
            $parser = $this->protocol;
            /** 如果有数据 */
            while ($this->_recvBuffer !== '' && !$this->_isPaused) {
                // The current packet length is known.
                if ($this->_currentPackageLength) {
                    /** 如果当前包的长度大于暂存区数据长度，说明还没有接收完数据，请继续接收数据 */
                    // Data is not enough for a package.
                    if ($this->_currentPackageLength > \strlen($this->_recvBuffer)) {
                        break;
                    }
                } else {
                    // Get current package length.
                    try {
                        /** 检查包的完整性 */
                        $this->_currentPackageLength = $parser::input($this->_recvBuffer, $this);
                    } catch (\Exception $e) {}
                    // The packet length is unknown.
                    /** 如果返回的是0 则需要读取更多的数据，就是客户端数据还没有发送完，还不知道包的长度是多少 */
                    if ($this->_currentPackageLength === 0) {
                        break;
                        /** 包长度小于 系统设定最大包长度 */
                    } elseif ($this->_currentPackageLength > 0 && $this->_currentPackageLength <= $this->maxPackageSize) {
                        // Data is not enough for a package.
                        /** 还没有接收完数据 */
                        if ($this->_currentPackageLength > \strlen($this->_recvBuffer)) {
                            break;
                        }
                    } // Wrong package.
                    else {
                        /** 错误的包 */
                        var_dump('Error package. package_length=' . \var_export($this->_currentPackageLength, true));
                        $this->destroy();
                        return;
                    }
                }
                /** 更新请求数 */
                // The data is enough for a packet.
                ++self::$statistics['total_request'];
                // The current packet length is equal to the length of the buffer.
                /** 当前读取的数据长度和客户端传输数据长度一致 清空暂存区 */
                if (\strlen($this->_recvBuffer) === $this->_currentPackageLength) {
                    $one_request_buffer = $this->_recvBuffer;
                    $this->_recvBuffer  = '';
                } else {
                    /** 处理的数据 只截取包的长度 ，更新暂存区内容 */
                    // Get a full package from the buffer.
                    $one_request_buffer = \substr($this->_recvBuffer, 0, $this->_currentPackageLength);
                    // Remove the current package from the receive buffer.
                    $this->_recvBuffer = \substr($this->_recvBuffer, $this->_currentPackageLength);
                }
                // Reset the current packet length to 0.
                /** 还原包的长度 */
                $this->_currentPackageLength = 0;
                /** 如果未定义消息处理回调函数 */
                if (!$this->onMessage) {
                    continue;
                }
                try {
                    /** 先对数据解码 调用这个协议定义的回调事件处理逻辑 */
                    // Decode request buffer before Emitting onMessage callback.
                    \call_user_func($this->onMessage, $this, $parser::decode($one_request_buffer, $this));
                } catch (\Exception $e) {
                    var_dump($e->getMessage());
                }
            }
            return;
        }

        if ($this->_recvBuffer === '' || $this->_isPaused) {
            return;
        }

        // Applications protocol is not set.
        ++self::$statistics['total_request'];
        if (!$this->onMessage) {
            $this->_recvBuffer = '';
            return;
        }
        try {
            \call_user_func($this->onMessage, $this, $this->_recvBuffer);
        } catch (\Exception $e) {
            var_dump($e->getMessage());
        }
        // Clean receive buffer.
        $this->_recvBuffer = '';
    }

    /**
     * Base write handler.
     *
     * @return void|bool
     */
    public function baseWrite()
    {
        \set_error_handler(function(){});
        if ($this->transport === 'ssl') {
            $len = @\fwrite($this->_socket, $this->_sendBuffer, 8192);
        } else {
            $len = @\fwrite($this->_socket, $this->_sendBuffer);
        }
        \restore_error_handler();
        if ($len === \strlen($this->_sendBuffer)) {
            $this->bytesWritten += $len;
            RtmpDemo::instance()->del($this->_socket, EventInterface::EV_WRITE);
            $this->_sendBuffer = '';
            // Try to emit onBufferDrain callback when the send buffer becomes empty.
            if ($this->onBufferDrain) {
                try {
                    \call_user_func($this->onBufferDrain, $this);
                } catch (\Exception $e) {
                    
                    var_dump($e->getMessage());
                }
            }
            if ($this->_status === self::STATUS_CLOSING) {
                $this->destroy();
            }
            return true;
        }
        if ($len > 0) {
            $this->bytesWritten += $len;
            $this->_sendBuffer = \substr($this->_sendBuffer, $len);
        } else {
            ++self::$statistics['send_fail'];
            $this->destroy();
        }
    }

    /**
     * SSL handshake.
     *
     * @param resource $socket
     * @return bool
     */
    public function doSslHandshake($socket){
        return true;
    }

    /**
     * This method pulls all the data out of a readable stream, and writes it to the supplied destination.
     *
     * @param self $dest
     * @return void
     */
    public function pipe(self $dest)
    {
        $source              = $this;
        $this->onMessage     = function ($source, $data) use ($dest) {
            $dest->send($data);
        };
        $this->onClose       = function ($source) use ($dest) {
            $dest->close();
        };
        $dest->onBufferFull  = function ($dest) use ($source) {
            $source->pauseRecv();
        };
        $dest->onBufferDrain = function ($dest) use ($source) {
            $source->resumeRecv();
        };
    }

    /**
     * Remove $length of data from receive buffer.
     *
     * @param int $length
     * @return void
     */
    public function consumeRecvBuffer($length)
    {
        $this->_recvBuffer = \substr($this->_recvBuffer, $length);
    }

    /**
     * Close connection.
     *
     * @param mixed $data
     * @param bool $raw
     * @return void
     */
    public function close($data = null, $raw = false)
    {
        if($this->_status === self::STATUS_CONNECTING){
            $this->destroy();
            return;
        }

        if ($this->_status === self::STATUS_CLOSING || $this->_status === self::STATUS_CLOSED) {
            return;
        }

        if ($data !== null) {
            $this->send($data, $raw);
        }

        $this->_status = self::STATUS_CLOSING;

        if ($this->_sendBuffer === '') {
            $this->destroy();
        } else {
            $this->pauseRecv();
        }
    }

    /**
     * Get the real socket.
     *
     * @return resource
     */
    public function getSocket()
    {
        return $this->_socket;
    }

    /**
     * Check whether the send buffer will be full.
     *
     * @return void
     */
    protected function checkBufferWillFull()
    {
        if ($this->maxSendBufferSize <= \strlen($this->_sendBuffer)) {
            if ($this->onBufferFull) {
                try {
                    \call_user_func($this->onBufferFull, $this);
                } catch (\Exception $e) {
                    
                    var_dump($e->getMessage());
                }
            }
        }
    }

    /**
     * Whether send buffer is full.
     *
     * @return bool
     */
    protected function bufferIsFull()
    {
        // Buffer has been marked as full but still has data to send then the packet is discarded.
        if ($this->maxSendBufferSize <= \strlen($this->_sendBuffer)) {
            if ($this->onError) {
                try {
                    \call_user_func($this->onError, $this, 2, 'send buffer full and drop package');
                } catch (\Exception $e) {
                    var_dump($e->getMessage());
                }
            }
            return true;
        }
        return false;
    }

    /**
     * Whether send buffer is Empty.
     *
     * @return bool
     */
    public function bufferIsEmpty()
    {
        return empty($this->_sendBuffer);
    }

    /**
     * Destroy connection.
     *
     * @return void
     */
    public function destroy()
    {
        // Avoid repeated calls.
        if ($this->_status === self::STATUS_CLOSED) {
            return;
        }
        // Remove event listener.
        RtmpDemo::instance()->del($this->_socket, EventInterface::EV_READ);
        RtmpDemo::instance()->del($this->_socket, EventInterface::EV_WRITE);

        // Close socket.
        try {
            @\fclose($this->_socket);
        } catch (\Exception $e) {
            var_dump($e->getMessage());
        }

        $this->_status = self::STATUS_CLOSED;
        // Try to emit onClose callback.
        if ($this->onClose) {
            try {
                \call_user_func($this->onClose, $this);
            } catch (\Exception $e) {

                var_dump($e->getMessage());
            }
        }
        // Try to emit protocol::onClose
        if ($this->protocol && \method_exists($this->protocol, 'onClose')) {
            try {
                \call_user_func(array($this->protocol, 'onClose'), $this);
            } catch (\Exception $e) {
                var_dump($e->getMessage());
            }
        }
        $this->_sendBuffer = $this->_recvBuffer = '';
        $this->_currentPackageLength = 0;
        $this->_isPaused = $this->_sslHandshakeCompleted = false;
        if ($this->_status === self::STATUS_CLOSED) {
            // Cleaning up the callback to avoid memory leaks.
            $this->onMessage = $this->onClose = $this->onError = $this->onBufferFull = $this->onBufferDrain = null;
            if ($this->worker) {
                unset($this->worker->connections[$this->_id]);
            }
            unset(static::$connections[$this->_id]);
        }
    }

    /**
     * Destruct.
     *
     * @return void
     */
    public function __destruct()
    {
        self::$statistics['connection_count']--;
    }
}
