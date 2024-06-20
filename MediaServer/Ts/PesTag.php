<?php

namespace MediaServer\Ts;

/**
 * Class PesTag
 * @package MediaServer\Ts
 *
 * @purpose PES数据包结构
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
     */
    public $streamId = 0;

    /**
     * PES包长度（Packet Length）：
     * 长度：2字节
     * 描述：PES包的长度。包括PES头字段和有效负载的长度。
     */
    public $packetLength = 0;

    /**
     * 可选的PES头字段和有效负载：
     * 长度：可变
     * 描述：包含时间戳（PTS/DTS）、数据等。
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
