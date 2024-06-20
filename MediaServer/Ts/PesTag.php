<?php

namespace MediaServer\Ts;

/**
 * Class PesTag
 * @package MediaServer\Ts
 *
 * @purpose PES数据包结构
 * 一个NALU就是一个frame，在ffmpeg中，H264的SPS与PPS一起打包在头一个NALU中，
 */
class PesTag
{
    /**
     * PES包头（Packet Start Code Prefix）：
     * 长度：3字节
     * 值：0x000001
     */
    public $header = "\x00\x00\x01";

    /**
     * 流ID（Stream ID）：
     * 长度：1字节
     * 描述：标识数据流类型（如视频、音频）。
     * streamID , 这里视频H264填写0xe0 ,AAC音频填写OXCO
     */
    public $streamId = 0;

    /**
     * PES包长度（Packet Length）：
     * 长度：2字节
     * 描述：PES包的长度。包括PES头字段和有效负载的长度。
     * Packet Length是指从OPTIONAL FIELD 到包最后一个字节的长度，不算前面的4字节，和自身2字节，一般来说就是3+10+NALUSIZE，
     * 这里10是指VIDEOFRAME的，如果是AUDIOFRAME则是5.
     */
    public $packetLength = 0;

    /**
     * 可选的PES头字段和有效负载：
     * 长度：可变
     * 描述：包含时间戳（PTS/DTS）、数据等。
     * PTS就是(flvTagHeader.timestamp +videoTagHeader.CompositionTime) * 90
     */
    public $payload = "";

    /**
     * 获取PES包的字节表示。
     * @return string PES包的字节表示。
     */
    public function getBytes()
    {
        $bytes = "";

        // PES包头
        $bytes .= $this->header;

        // 流ID
        $bytes .= chr($this->streamId);

        // PES包长度
        $bytes .= chr(($this->packetLength >> 8) & 0xFF);
        $bytes .= chr($this->packetLength & 0xFF);

        // 有效负载
        if (!empty($this->payload)) {
            $bytes .= $this->payload; // 有效负载内容
        }

        return $bytes;
    }
}
