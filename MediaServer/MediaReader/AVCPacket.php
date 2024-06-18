<?php


namespace MediaServer\MediaReader;



use MediaServer\Utils\BinaryStream;

/**
 * @purpose 视频数据包
 */
class AVCPacket
{
    /** HEVC sequence header 是 H265 视频编码中的一个重要部分，也被称为 SPS（Sequence Parameter Set）信息。它包含了关于视频序列的全局参数，如分辨率、帧率、色彩空间等。*/
    /** AVC 序列頭（Sequence Header）是包含了 H.264 編碼器的配置信息的一個特殊封包類型。它包括了 SPS（Sequence Parameter Set）和
     * PPS（Picture Parameter Set）等參數，這些參數描述了影像的特性和編碼的方式。解碼器在解碼 AVC 視頻流之前需要先接收和解析序列頭信息。
     */
    const AVC_PACKET_TYPE_SEQUENCE_HEADER = 0;

    /** NALU（Network Abstraction Layer Unit）是 AVC 中最小的編碼單元，每個 NALU 包含了視頻流中的部分數據。AVC 封包類型為 NALU 表示該封包包含了一個或多個 NALU。*/
    /** 里面装了视频数据，表示这是一个独立的片段 ，可以单独解码 */
    const AVC_PACKET_TYPE_NALU = 1;
    /** AVC 結束序列（End of Sequence）指示了視頻流中 AVC 編碼的結束。這種類型的封包可以用來表示一個 AVC 視頻流的結束或者一個特定的區段結束。 */
    const AVC_PACKET_TYPE_END_SEQUENCE = 2;


    /** 包类型 */
    public $avcPacketType;
    /** 在一些视频编码格式中（如 AVC / H.264 和 HEVC / H.265），每个视频帧都可以有一个与之相关的 compositionTime。
     * 这个时间戳告诉解码器在解码完成后多久才应该开始显示该帧。通常情况下，compositionTime 可以是正数、零或者负数
     */
    public $compositionTime;
    /** 流 */
    public $stream;

    /**
     * 视频数据包初始化
     * AVCPacket constructor.
     * @param $stream BinaryStream
     */
    public function __construct($stream)
    {
        $this->stream=$stream;
        /** 视频数据包编码格式 */
        $this->avcPacketType=$stream->readTinyInt();
        //todo 这个合成时间戳很重要 后面转换为hls协议需要用到
        /** compositionTime校正时间 = pts显示时间戳 - dts校正时间戳 */
        $this->compositionTime=$stream->readInt24();
    }


    /**
     * avc配置参数
     * @var AVCSequenceParameterSet
     * @note 在向RTMP服务器推送音频流或者视频流时，首先要推送一个音频tag（AAC sequence header）和视频tag（AVC sequence header），没有这些信息播放端是无法解码音视频流的
     */
    protected $avcSequenceParameterSet;

    /**
     * 获取画面帧的参数
     * @return AVCSequenceParameterSet
     */
    public function getAVCSequenceParameterSet(){

        /** 如果没有avc的配置参数 ，那么需要解码 */
        if(!$this->avcSequenceParameterSet){
            $this->avcSequenceParameterSet=new AVCSequenceParameterSet($this->stream->readRaw());
        }
        return $this->avcSequenceParameterSet;
    }
}