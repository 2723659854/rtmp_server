<?php


namespace MediaServer\Rtmp;


/**
 * @purpose rtmp 事件处理器
 */
trait RtmpEventHandlerTrait
{
    /**
     * 默认不做任何处理
     * @return void
     */
    public function rtmpEventHandler()
    {
        //logger()->info("rtmpEventHandler");
    }
}