<?php

namespace MediaServer\MediaReader;


use MediaServer\Utils\BitReader;

/**
 * @purpose 音频参数设置
 */
class AACSequenceParameterSet extends BitReader
{
    public $objType;
    public $sampleIndex;
    public $sampleRate;
    public $channels;
    public $sbr;
    public $ps;
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
     * 获取音频资源文件
     * @return string
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
        $objectType = ($objectType = $this->getBits(5)) === 31 ? ($this->getBits(6) + 32) : $objectType;
        $this->objType = $objectType;
        $sampleRate = ($sampleIndex = $this->getBits(4)) === 0x0f ? $this->getBits(24) : AACPacket::AAC_SAMPLE_RATE[$sampleIndex];
        $this->sampleIndex = $sampleIndex;
        $this->sampleRate = $sampleRate;
        $channelConfig = $this->getBits(4);

        if ($channelConfig < count(AACPacket::AAC_CHANNELS)) {
            $channels = AACPacket::AAC_CHANNELS[$channelConfig];
            $this->channels = $channels;
        }

        $this->sbr = -1;
        $this->ps = -1;
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