<?php


namespace MediaServer\MediaReader;

use MediaServer\Utils\BinaryStream;

/**
 * @purpose 音频帧数据
 */
class AudioFrame extends BinaryStream implements MediaFrame
{
    public $FRAME_TYPE=self::AUDIO_FRAME;
    /** 音频编码格式 */
    const AUDIO_CODEC_NAME = [
        '',
        'ADPCM',
        'MP3',
        'LinearLE',
        'Nellymoser16',
        'Nellymoser8',
        'Nellymoser',
        'G711A',
        'G711U',
        '',
        'AAC',
        'Speex',
        '',
        'OPUS',
        'MP3-8K',
        'DeviceSpecific',
        'Uncompressed'
    ];

    /** 码率 */
    const AUDIO_SOUND_RATE = [
        5512, 11025, 22050, 44100
    ];

    /** 默认的aac编码 */
    const SOUND_FORMAT_AAC = 10;

    /** 编码格式 */
    public $soundFormat;
    /** 采样率 */
    public $soundRate;
    /** 大小 */
    public $soundSize;
    /** 类型 */
    public $soundType;
    /** 时间戳 */
    public $timestamp = 0;


    /**
     * 初始化
     * @param $data
     * @param $timestamp
     */
    public function __construct($data, $timestamp = 0)
    {
        /** 读取数据 */
        parent::__construct($data);
        /** 时间戳 */
        $this->timestamp = $timestamp;
        /** 第一个字节 */
        $firstByte = $this->readTinyInt();
        /** 音频帧格式 */
        $this->soundFormat = $firstByte >> 4;
        /** 音频码率 */
        $this->soundRate = $firstByte >> 2 & 3;
        /** 音频数据大小*/
        $this->soundSize = $firstByte >> 1 & 1;
        /** 音频类别 */
        $this->soundType = $firstByte & 1;

    }

    public function __toString()
    {
        return $this->dump();
    }

    /**
     * 获取音频编码
     * @return string
     */
    public function getAudioCodecName()
    {
        return self::AUDIO_CODEC_NAME[$this->soundFormat];
    }

    /** 获取音频取样频率 */
    public function getAudioSamplerate()
    {
        $rate = self::AUDIO_SOUND_RATE[$this->soundRate];
        switch ($this->soundFormat) {
            case 4:
                $rate = 16000;
                break;
            case 5:
                $rate = 8000;
                break;
            case 11:
                $rate = 16000;
                break;
            case 14:
                $rate = 8000;
                break;
        }
        return $rate;
    }

    /**
     * @var AACPacket
     */
    protected $aacPacket;

    /**
     * 获取音频数据包
     * @return AACPacket
     */
    public function getAACPacket()
    {
        if (!$this->aacPacket) {
            $this->aacPacket = new AACPacket($this);
        }

        return $this->aacPacket;
    }


    public function destroy()
    {
        $this->aacPacket = null;
    }

}