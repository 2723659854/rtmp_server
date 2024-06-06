<?php

namespace MediaServer\Utils;

use Evenement\EventEmitterTrait;

use Root\rtmp\TcpConnection;
use Root\Protocols\Http\Chunk;
use Root\Response;

/**
 * @purpose 流式数据切片
 */
class WMHttpChunkStream implements  WMChunkStreamInterface
{
    use EventEmitterTrait;

    /**
     * @var TcpConnection
     */
    public $connection;

    protected $sendHeader = false;

    /**
     * 初始化
     * WMHttpChunkStream constructor.
     * @param $connection TcpConnection
     */
    public function __construct($connection){
        /** 保存链接 */
        $this->connection = $connection;
        /** 绑定关闭事件 */
        $this->connection->onClose = function ($con){
            /** 触发close事件 */
            $this->emit('close');
            /** 清空链接和事件 */
            $this->connection = null;
            $this->removeAllListeners();
        };
        /** 定义错误事件 */
        $this->connection->onError = function ($con,$code,$msg){
            /** 触发error 抛出异常 */
            $this->emit('error',[new \Exception($msg,$code)]);
        };
    }

    /**
     * 发送数据
     * @param $data
     * @return void
     * @note rtmp和flv的数据是一样的，他们的区别是：加了http的flvHeader,然后也是长链接connection:keep-alive ，每一个包有长度，
     * 用\r\n分割数据
     */
    public function write($data)
    {
        /** 如果还没有发送头部 */
        if(!$this->sendHeader){
            $this->sendHeader = true;
            /** 发送flv数据给客户端 */
            $this->connection->send(new Response(200,[
                /** 禁止使用缓存 */
                'Cache-Control' => 'no-cache',
                /** 资源类型 flv */
                'Content-Type' => 'video/x-flv',
                /** 允许跨域 */
                'Access-Control-Allow-Origin' => '*',
                /** 长链接 */
                'Connection' => 'keep-alive',
                /** 数据是分块的，而不是告诉客户端数据的大小，通常用于流式传输 */
                'Transfer-Encoding' => 'chunked'
            ],$data));
        }else{
            /** 发送flv的块数据 数据格式:十六进制的长度+\r\n数据\r\n */
            $this->connection->send(new Chunk($data));
        }

    }

    /**
     * 数据包发送完毕
     * @param $data
     * @return void
     */
    public function end($data = null)
    {
        //empty chunk end
        $this->connection->send(new Chunk(''));
    }

    /**
     * 关闭链接
     * @return void
     */
    public function close()
    {
        $this->connection->close();
    }
}
