<?php

namespace MediaServer\Ts;

/**
 * @purpose pes数据包
 */
class PesTag
{
    /**
     * PES包头（Packet Start Code Prefix）：
     * 长度：3字节
     * 值：0x000001
     */
    public $header = "0x000001";
    /**
     * 流ID（Stream ID）：
     * 长度：1字节
     * 描述：标识数据流类型（如视频、音频）。
     */
    public $streamId = 0;
    /**
     * PES包长度（Packet Length）：
     * 长度：2字节
     * 描述：PES包的长度。
     */
    public $packetLength = 0;
    /**
     * 可选的PES头字段和有效负载：
     * 长度：可变
     * 描述：包含时间戳（PTS/DTS）、数据等。
     */
    public $payload = "";

}