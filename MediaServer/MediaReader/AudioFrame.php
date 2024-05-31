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


    public $pts = 0;

    public $dts = 0;

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

        $this->pts = $this->timestamp;
        $this->dts = $this->timestamp;
    }

    /**
     * 获取毫秒pts,dts
     * @return float[]|int[]
     */
    public function getPtsAndDts()
    {
        // 如果需要将时间戳转换为毫秒单位，可以使用采样率进行计算
        $samplerate = $this->getAudioSamplerate();
        $ptsMilliseconds = $this->pts / $samplerate * 1000;
        $dtsMilliseconds = $this->dts / $samplerate * 1000;

        return ['pts'=>$ptsMilliseconds,'dts'=>$dtsMilliseconds];
    }

    /**
     * 是否是音频数据
     * @return true
     */
    public function isAudio()
    {
        return true;
    }

    public function __toString()
    {
        return $this->dump();
    }

    /**
     * 获取音频帧的 payload 数据
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