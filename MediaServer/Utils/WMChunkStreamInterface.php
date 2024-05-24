<?php


namespace MediaServer\Utils;


use Evenement\EventEmitterInterface;

/**
 * @purpose rtmp 协议接口
 */
interface WMChunkStreamInterface extends  EventEmitterInterface
{

    public function write($data);

    public function end($data = null);

    public function close();

}