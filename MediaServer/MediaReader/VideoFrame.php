<?php


namespace MediaServer\MediaReader;


use MediaServer\Utils\BinaryStream;

/**
 * @purpose 视频帧
 * @note 一个视频帧包含一个avc,同理，一个音频帧包含一个aac
 */
class VideoFrame extends BinaryStream implements MediaFrame
{
    public $FRAME_TYPE=self::VIDEO_FRAME;

    const VIDEO_CODEC_NAME = [
        '',
        'Jpeg',
        'Sorenson-H263',
        'ScreenVideo',
        'On2-VP6',
        'On2-VP6-Alpha',
        'ScreenVideo2',
        'H264',
        '',
        '',
        '',
        '',
        'H265'
    ];


    /**
     * 在 RTMP（实时消息传输协议）中，codecId 表示数据编码的标识符。它用于标识视频帧使用的编码格式。
     * codecId 的可选值及其对应的编码格式如下：
     * 1：表示 JPEG 编码。
     * 2：表示 Sorenson H.263 编码。
     * 4：表示 On2 VP6 编码。
     * 5：表示 On2 VP6 with alpha channel 编码。
     * 6：表示 Screen video version2 编码。
     * 7：表示 AVC H264 编码，也就是 H.264 编码。
     * 12：表示 H265 编码。
     * 在实际使用中，主要关注 AVC（H264 编码）和 H265 编码的 codecId。对于 H265 编码，codecId 一般为 7 或 12。而对于 H264 编码，codecId 通常为 7。
     * 需要注意的是，不同的应用程序或系统可能会对 codecId 的定义和使用有所差异。在具体的场景中，还需要参考相关的文档和规范来确定 codecId 的具体含义和用法。
     */
    const VIDEO_FRAME_TYPE_KEY_FRAME = 1;
    const VIDEO_FRAME_TYPE_INTER_FRAME = 2;
    const VIDEO_FRAME_TYPE_DISPOSABLE_INTER_FRAME = 3;
    const VIDEO_FRAME_TYPE_GENERATED_KEY_FRAME = 4;
    const VIDEO_FRAME_TYPE_VIDEO_INFO_FRAME = 5;


    const VIDEO_CODEC_ID_JPEG = 1;
    const VIDEO_CODEC_ID_H263 = 2;
    const VIDEO_CODEC_ID_SCREEN = 3;
    const VIDEO_CODEC_ID_VP6_FLV = 4;
    const VIDEO_CODEC_ID_VP6_FLV_ALPHA = 5;
    const VIDEO_CODEC_ID_SCREEN_V2 = 6;
    const VIDEO_CODEC_ID_AVC = 7;


    public $frameType;
    public $codecId;
    public $timestamp = 0;

    public function __toString()
    {
        return $this->dump();
    }


    /** 获取视频编码名称 */
    public function getVideoCodecName()
    {
        return self::VIDEO_CODEC_NAME[$this->codecId];
    }

    public $pts;
    public $dts;

    /** 初始化视频编码格式 */
    public function __construct($data, $timestamp = 0)
    {
        /** 装载数据 方便对数据的读取 */
        parent::__construct($data);
        /** 时间戳 */
        $this->timestamp = $timestamp;
        $firstByte = $this->readTinyInt();
        /** 类型和编码都是使用掩码计算的 */
        $this->frameType = $firstByte >> 4;
        $this->codecId = $firstByte & 15;

        // 假设视频帧没有B帧，PTS等于DTS
        $this->pts = $this->timestamp;
        $this->dts = $this->timestamp;

    }


    /**
     * 视频数据包
     * @var AVCPacket
     */
    protected $avcPacket;

    /**
     * 获取视频帧数据
     * @return AVCPacket
     */
    public function getAVCPacket()
    {
        if (!$this->avcPacket) {
            $this->avcPacket = new AVCPacket($this);
        }

        return $this->avcPacket;
    }

    /**
     * 获取视频帧的 payload 数据
     * @param int $length 要读取的 payload 数据长度，默认为0，表示读取全部数据
     * @return string 返回读取的 payload 数据
     */
    public function getPayload($length = 0)
    {
        // 如果未指定长度，则读取全部数据
        if ($length === 0) {
            return $this->readRaw();
        } else {
            return $this->readRaw($length);
        }
    }

    /**
     * 是否是音频数据
     * @return false
     */
    public function isAudio()
    {
        return false;
    }

    /**
     * 销毁数据包
     * @return void
     */
    public function destroy(){
        $this->avcPacket=null;
    }

}