<?php

namespace MediaServer\Ts;

/**
 * @purpose ES数据包结构
 */
class EsTag
{
    /**
     * 起始码（Start Code）或起始字节（Start Byte）：
     * 对于视频来说，通常以 0x00 0x00 0x01 或 0x00 0x00 0x00 0x01 开始。
     * 对于音频来说，起始码可能因编码标准而异，如 AAC 可能以特定的帧同步字开始。
     */
    public $startCode = "\x00\x00\x01"; // Assuming start code for video, adjust as per specific codec

    /**
     * 数据载荷（Payload）：
     * 包含了编码后的音频或视频数据。
     * 对于视频，通常是包含压缩的图像数据。
     * 对于音频，通常是包含压缩的音频帧数据。
     */
    public $payload = "";

    /**
     * 时间戳（Timestamp）：
     * 可能会在某些协议或格式中包含时间戳信息，用于同步音视频数据的播放。
     */
    public $timestamp = 0;

    /**
     * 元数据（Metadata）：
     * 有时会在 ES 数据包中包含一些元数据，例如关键帧标识（对于视频）、音频配置信息（对于音频）等。
     */
    public $metadata = "";
}
