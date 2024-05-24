<?php

namespace MediaServer\MediaReader;


use MediaServer\Utils\BitReader;

/**
 * @purpose 音频参数设置
 * @note 音频序列参数 声道和采样率
 */
class AACSequenceParameterSet extends BitReader
{
    /** objType：对象类型，通常为2，表示音频对象。*/
    public $objType;
    /** sampleIndex：采样索引，用于标识音频样本的起始位置。 */
    public $sampleIndex;
    /** sampleRate：采样率，单位为赫兹（Hz），表示音频的采样频率。 */
    public $sampleRate;
    /** channels：声道数，通常为2表示双声道，1表示单声道。 */
    public $channels;
    /** sbr：SBR（Spectral Band Replication），表示频谱带复制，用于提高音频的带宽。 */
    public $sbr;
    /** ps：PS（Parametric Stereo），表示参数立体声，用于增强音频的立体感。 */
    public $ps;
    /** extObjectType：扩展对象类型，通常为0，表示没有扩展对象。*/
    public $extObjectType;

    /**
     * 读取数据
     * @param $data
     */
    public function __construct($data)
    {
        parent::__construct($data);
        $this->readData();
    }

    /**
     * 获取音频配置
     * @return string
     * @note
     * Main：表示主配置文件，通常用于中高码率的音频编码，提供较好的音频质量。
     * HEv2：表示高效配置文件，适用于低码率的音频编码，在较低的比特率下提供较好的音频质量。
     * HE：表示高效配置文件，也适用于低码率的音频编码。
     * LC：表示低复杂性配置文件，是比较传统的AAC编码方式，主要用于中高码率。
     * SSR：表示可缩放采样率配置文件，可以根据音频的内容动态调整采样率，以提高编码效率。
     * LTP：表示长期预测配置文件，通过对音频信号进行长期预测来提高编码效率。
     * SBR：表示频谱带复制技术，它可以将高频信号存储在少量的数据中，解码器可以根据这些数据恢复出高频信号，从而提高音频的质量。
     * PS：表示参数立体声技术，可以通过对立体声信号进行参数化处理来减少音频数据的量，同时保持较好的立体声效果。
     *
     * 数据越到 压缩率越高
     */
    public function getAACProfileName()
    {
        switch ($this->objType) {
            case 1:
                return 'Main';
            case 2:
                if ($this->ps > 0) {
                    return 'HEv2';
                }
                if ($this->sbr > 0) {
                    return 'HE';
                }
                return 'LC';
            case 3:
                return 'SSR';
            case 4:
                return 'LTP';
            case 5:
                return 'SBR';
            default:
                return '';
        }
    }

    /**
     * 读取数据
     * @return void
     */
    public function readData()
    {
        /** 对象类型 音频 */
        $objectType = ($objectType = $this->getBits(5)) === 31 ? ($this->getBits(6) + 32) : $objectType;
        $this->objType = $objectType;
        /** 采样率 */
        $sampleRate = ($sampleIndex = $this->getBits(4)) === 0x0f ? $this->getBits(24) : AACPacket::AAC_SAMPLE_RATE[$sampleIndex];
        /** 采样索引，起始位置 */
        $this->sampleIndex = $sampleIndex;
        $this->sampleRate = $sampleRate;
        /** 声道 */
        $channelConfig = $this->getBits(4);

        if ($channelConfig < count(AACPacket::AAC_CHANNELS)) {
            $channels = AACPacket::AAC_CHANNELS[$channelConfig];
            $this->channels = $channels;
        }
        /** 初始化频谱带复制 */
        $this->sbr = -1;
        /** 初始化立体声 */
        $this->ps = -1;
        /** 类型是：Elementary Stream Descriptor基本流，  或者 Parametric Stereo立体声*/
        if ($objectType == 5 || $objectType == 29) {
            if ($objectType == 29) {
                $this->ps = 1;
            }
            $this->extObjectType = 5;
            $this->sbr = 1;
            $this->sampleRate = ($sampleIndex = $this->getBits(4)) === 0x0f ? $this->getBits(24) : AACPacket::AAC_SAMPLE_RATE[$sampleIndex];
            $this->sampleIndex = $sampleIndex;
            $this->objType = ($objectType = $this->getBits(5)) === 31 ? ($this->getBits(6) + 32) : $objectType;
        }


    }


}