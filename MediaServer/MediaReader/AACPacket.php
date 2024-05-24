<?php

namespace MediaServer\MediaReader;



use MediaServer\Utils\BinaryStream;
use MediaServer\Utils\BitReader;

/**
 * @purpose 音频数据包
 */
class AACPacket
{
    /** aac采样率 */
    const AAC_SAMPLE_RATE = [
        96000, 88200, 64000, 48000,
        44100, 32000, 24000, 22050,
        16000, 12000, 11025, 8000,
        7350, 0, 0, 0
    ];
    /** aac声道 */
    const AAC_CHANNELS = [
        0, 1, 2, 3, 4, 5, 6, 8
    ];
    /** aac序列号头部 */
    const AAC_PACKET_TYPE_SEQUENCE_HEADER = 0;
    /** aac原始数据 */
    const AAC_PACKET_TYPE_RAW = 1;


    /** 包类型 */
    public $aacPacketType;

    /**
     * 数据流
     * @var BinaryStream
     */
    public $stream;

    /**
     * AACPacket constructor.
     * @param $stream BinaryStream 二进制媒体资源
     */
    public function __construct($stream)
    {
        $this->stream=$stream;
        /** 包类型 读取一个字节 */
        $this->aacPacketType=$stream->readTinyInt();

    }

    /**
     * aac序列参数
     * @var AACSequenceParameterSet
     * @note 主要包括声道和采样率
     */
    protected $aacSequenceParameterSet;

    /**
     * 获取音频序列参数
     * @return AACSequenceParameterSet
     */
    public function getAACSequenceParameterSet(){

        if(!$this->aacSequenceParameterSet){
            /** 如果没有设置参数 ，那么就解析参数 */
            $this->aacSequenceParameterSet=new AACSequenceParameterSet($this->stream->readRaw());
        }
        return $this->aacSequenceParameterSet;
    }

}
