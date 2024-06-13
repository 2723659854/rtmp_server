<?php


namespace MediaServer\MediaReader;



use MediaServer\Utils\BinaryStream;

/**
 * @purpose 视频数据包
 */
class AVCPacket
{
    /** HEVC sequence header 是 H265 视频编码中的一个重要部分，也被称为 SPS（Sequence Parameter Set）信息。它包含了关于视频序列的全局参数，如分辨率、帧率、色彩空间等。*/
    const AVC_PACKET_TYPE_SEQUENCE_HEADER = 0;
    /**
     * HEVC NALU 的全称是 network abstraction layer (NAL) unit，即网络抽象层单元。它是 H265 视频编码中的一个重要概念。
     * 可以把视频的压缩和传输过程比作搬家，首先将待搬的东西大致分类并装进不同的箱子里，然后贴上内容标签，再逐个箱子搬走。NALU 就好比这个过程中贴了标签的箱子，
     * 是一种能够表示内部数据类型的语法结构，也是视频数据存放和传输的基本单元。
     * NALU 数据常表现为 RBSP（字节流）的形式，RBSP 则表示箱子的体积信息。在 H265 中，NALU 不仅可以表示视频参数集（VPS）、序列参数集（SPS）、
     * 图像参数集（PPS）等，还可以表示补充增强信息（SEI）等。
     * 在实际应用中，需要确保 SPS 信息的正确性和完整性，以获得良好的视频解码效果。
     * */
    const AVC_PACKET_TYPE_NALU = 1;
    const AVC_PACKET_TYPE_END_SEQUENCE = 2;



    public $avcPacketType;
    public $compositionTime;
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