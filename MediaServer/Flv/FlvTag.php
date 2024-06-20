<?php


namespace MediaServer\Flv;


/**
 * @purpose flv帧
 * @note flv 基本数据包
 * @comment 里面包含了音频数据或者视频数据或者脚本命令
 * @note
 * <code>
 * |  Tag Type  | Data Size |  Timestamp  | Timestamp Extended | Stream ID |
 * |     1      |    3      |      3      |         1          |     3     |
 * </code>
 * Tag Type：1字节
 * Data Size：3字节
 * Timestamp：3字节
 * Timestamp Extended：1字节
 * Stream ID：3字节（通常为0）
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
