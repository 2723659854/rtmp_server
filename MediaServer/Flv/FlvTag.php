<?php


namespace MediaServer\Flv;


/**
 * @purpose flv帧
 * @note flv 基本数据包
 * @comment 里面包含了音频数据或者视频数据或者脚本命令
 */
class FlvTag
{
    /** 类型 */
    public $type;
    /** 数据大小 */
    public $dataSize;
    /** 时间戳 */
    public $timestamp;
    /** 流id */
    public $streamId = 0;
    /** 数据 */
    public $data;

}
