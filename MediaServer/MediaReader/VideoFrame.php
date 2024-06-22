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


    /** 關鍵幀（Key Frame）是一個完整、自包含的影像幀，不需要參考其他幀進行解碼。它通常是一個關鍵幀序列的開始，用來提供從頭開始解碼影像的基礎。
     * 在 H.264 和其他視頻編碼標準中，關鍵幀通常被標記為 I 幀（Intra Frame）
     */
    const VIDEO_FRAME_TYPE_KEY_FRAME = 1;

    /** 預測幀（Inter Frame）是一個依賴於前一個或後一個關鍵幀或其他預測幀的影像幀。預測幀通常只包含更新的像素信息，而不是完整的影像數據。
     * 在 H.264 中，預測幀可以是 P 幀（Predicted Frame）或 B 幀（Bi-directional Predicted Frame）
     */
    const VIDEO_FRAME_TYPE_INTER_FRAME = 2;

    /** 一次性預測幀（Disposable Inter Frame）是一種在解碼器端處理時可以丟棄的預測幀。它們通常用於提高視頻編碼的效率，但不是解碼過程的必要部分。
     * 這種幀類型在某些編碼器中使用，以改進視頻流的壓縮比率。可丢弃
     */
    const VIDEO_FRAME_TYPE_DISPOSABLE_INTER_FRAME = 3;

    /** 生成的關鍵幀（Generated Key Frame）是在視頻流中動態生成的關鍵幀，而不是基於實際影像內容。這些幀通常用於特定的應用中，
     * 如視訊會議系統或視訊流媒體，以確保解碼器能夠正確地同步和恢復視頻流。
     */
    const VIDEO_FRAME_TYPE_GENERATED_KEY_FRAME = 4;

    /** 視頻信息幀（Video Info Frame）包含了視頻流的一些元數據或者其他重要信息，而不是實際的視頻幀數據。這些幀可以包含如視頻解析度、幀率、
     * 編碼器配置等信息，用於視頻流的初始化和配置。
     */
    const VIDEO_FRAME_TYPE_VIDEO_INFO_FRAME = 5;


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

    public $_buffer ='';

    /** 初始化视频编码格式 */
    public function __construct($data, $timestamp = 0)
    {

        $this->_buffer = $data;
        /** 装载数据 方便对数据的读取 */
        parent::__construct($data);
        /** 时间戳 这个是dts */
        $this->timestamp = $timestamp;
        $firstByte = $this->readTinyInt();
        /** 类型和编码都是使用掩码计算的 */
        $this->frameType = $firstByte >> 4;
        $this->codecId = $firstByte & 15;

        // 假设视频帧没有B帧，PTS等于DTS

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