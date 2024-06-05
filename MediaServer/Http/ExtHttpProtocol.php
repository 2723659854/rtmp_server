<?php

namespace MediaServer\Http;

use Root\rtmp\TcpConnection;
use Root\Protocols\Http;
use Root\Protocols\Websocket;

/**
 * @purpose 自定义http鞋以及的input方法
 */
class ExtHttpProtocol extends Http
{
    public $protocol;
    public $onMessage;
    public $onWebSocketConnect;

    public function __construct(TcpConnection $connection)
    {
        $this->protocol = $connection->protocol;
        $this->onMessage = $connection->onMessage;
        $this->onWebSocketConnect = $connection->onWebSocketConnect;
        var_dump("设置完成");
    }

    /**
     * 只负责获取包数据长度
     * @param $recv_buffer
     * @param TcpConnection $connection
     * @return float|int|mixed|string
     */
    public static function input($recv_buffer, TcpConnection $connection)
    {
        static $input = [];
        /** 返回缓存 */
        if (!isset($recv_buffer[512]) && isset($input[$recv_buffer])) {
            return $input[$recv_buffer];
        }
        /** 数据太大 */
        $crlf_pos = \strpos($recv_buffer, "\r\n\r\n");
        if (false === $crlf_pos) {
            // Judge whether the package length exceeds the limit.
            if (\strlen($recv_buffer) >= 16384) {
                $connection->close("HTTP/1.1 413 Request Entity Too Large\r\n\r\n", true);
                return 0;
            }
            return 0;
        }
        /** 获取输入数据长度 */
        $length = $crlf_pos + 4;
        /** 使用strstr 方法获取method 如果strstr加入第三个参数设置为TRUE，则会返回被搜索字符第一次出现前面的字符串 */
        $method = \strstr($recv_buffer, ' ', true);

        if (!\in_array($method, ['GET', 'POST', 'OPTIONS', 'HEAD', 'DELETE', 'PUT', 'PATCH'])) {
            $connection->close("HTTP/1.1 400 Bad Request\r\n\r\n", true);
            return 0;
        }
        /** 解析头部 */
        $header = \substr($recv_buffer, 0, $crlf_pos);
        /** 如果对面是要建立ws链接，那么升级为ws链接 */
        if (\preg_match("/\r\nUpgrade: websocket/i", $header)) {
            /** 切换为ws协议 */
            //upgrade websocket
            $connection->protocol = Websocket::class;
            return Websocket::input($recv_buffer, $connection);
        }
        /** 解析包长度 */
        if ($pos = \strpos($header, "\r\nContent-Length: ")) {
            $length = $length + (int)\substr($header, $pos + 18, 10);
            $has_content_length = true;
        } else if (\preg_match("/\r\ncontent-length: ?(\d+)/i", $header, $match)) {
            $length = $length + $match[1];
            $has_content_length = true;
        } else {
            $has_content_length = false;
            if (false !== stripos($header, "\r\nTransfer-Encoding:")) {
                $connection->close("HTTP/1.1 400 Bad Request\r\n\r\n", true);
                return 0;
            }
        }
        /** 数据长度过大 */
        if ($has_content_length) {
            if ($length > $connection->maxPackageSize) {
                $connection->close("HTTP/1.1 413 Request Entity Too Large\r\n\r\n", true);
                return 0;
            }
        }
        /** 如果保存的数据还没有超过512个 */
        if (!isset($recv_buffer[512])) {
            //部分相同请求做缓存 相同请求做缓存
            $input[$recv_buffer] = $length;
            /** 已经超过512个，则清空 ，防止内存占用过大 */
            if (\count($input) > 512) {
                unset($input[key($input)]);
            }
        }

        return $length;
    }

}