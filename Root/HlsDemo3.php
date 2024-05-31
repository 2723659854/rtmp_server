<?php


namespace Root;

use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\VideoFrame;

class HlsDemo3
{
    private static $tsBuffer = ''; // 用于存储 TS 数据的缓冲区
    private static $lastTimestamp = 0; // 上一个 TS 文件生成的时间点
    private static $videoPID = 256; // 视频流的 PID
    private static $audioPID = 257; // 音频流的 PID
    private static $tsIndex = 0; // TS 文件索引
    private static $segmentDuration = 3000; // 每段 TS 文件的持续时间（单位：毫秒）

    public static function make($data)
    {
        // 判断数据包类型
        if ($data instanceof AudioFrame) {
            // 音频数据包
            self::processAudioFrame($data);
        } elseif ($data instanceof VideoFrame) {
            // 视频数据包
            self::processVideoFrame($data);
        }

        // 判断是否满足生成 TS 文件的条件（每隔3秒生成一个 TS 文件）
        if ($data->timestamp - self::$lastTimestamp >= self::$segmentDuration) {
            self::generateTSFile();
            self::resetTimestamp($data->timestamp);
        }
    }

    private static function processAudioFrame(AudioFrame $audioFrame)
    {
        // 生成音频 PES 包
        $audioPESPacket = self::generatePESPacket($audioFrame, self::$audioPID);
        self::$tsBuffer .= $audioPESPacket;
    }

    private static function processVideoFrame(VideoFrame $videoFrame)
    {
        // 生成视频 PES 包
        $videoPESPacket = self::generatePESPacket($videoFrame, self::$videoPID);
        self::$tsBuffer .= $videoPESPacket;
    }

    private static function generatePESPacket($frame, $pid)
    {
        // 生成 PES 头部
        $pesHeader = "\x00\x00\x01";
        if ($frame instanceof VideoFrame) {
            $pesHeader .= "\xE0"; // 视频流的 Stream ID
        } else {
            $pesHeader .= "\xC0"; // 音频流的 Stream ID
        }

        // PES 包长度
        $pesPacketLength = strlen($frame->getPayload()) + 8;
        $pesHeader .= pack('n', $pesPacketLength);

        // Flags
        $pesHeader .= "\x80"; // Flags，PTS 只读标志
        $pesHeader .= "\x80"; // PES header data length

        // PTS 时间戳
        $pts = $frame->pts;
        $pesHeader .= chr((($pts >> 29) & 0x0E) | 0x21);
        $pesHeader .= chr(($pts >> 22) & 0xFF);
        $pesHeader .= chr((($pts >> 14) & 0xFE) | 0x01);
        $pesHeader .= chr(($pts >> 7) & 0xFF);
        $pesHeader .= chr((($pts << 1) & 0xFE) | 0x01);

        // 生成 TS 包
        $payload = $pesHeader . $frame->getPayload();
        $tsPacket = self::generateTSPacket($payload, $pid);

        return $tsPacket;
    }

    private static function generateTSPacket($payload, $pid)
    {
        $tsPackets = '';
        $payloadLen = strlen($payload);
        $continuityCounter = 0;

        for ($i = 0; $i < $payloadLen; $i += 184) {
            $tsHeader = "\x47"; // Sync byte
            $tsHeader .= pack('n', ($i == 0 ? 0x4000 : 0x0000) | $pid); // Payload unit start indicator (1 bit), PID (13 bits)
            $tsHeader .= chr($continuityCounter++ & 0x0F); // Continuity counter (4 bits)

            $payloadChunk = substr($payload, $i, 184);
            $tsPacket = $tsHeader . $payloadChunk;

            // 如果payload不足188字节，补充填充数据
            $paddingSize = 188 - strlen($tsPacket);
            if ($paddingSize > 0) {
                $tsPacket .= str_repeat("\xFF", $paddingSize); // 使用0xFF进行填充
            }

            $tsPackets .= $tsPacket;
        }

        return $tsPackets;
    }

    private static function generateTSFile()
    {
        // 生成 PAT 和 PMT 表
        $patPacket = self::generatePAT();
        $pmtPacket = self::generatePMT();

        // 拼接 TS 包
        $tsData = $patPacket . $pmtPacket . self::$tsBuffer;

        // 写入 TS 文件
        $tsFilename = app_path('/a/b') . "/segment" . self::$tsIndex . ".ts";
        file_put_contents($tsFilename, $tsData);

        // 更新索引文件
        self::updateM3U8File($tsFilename);

        // 重置缓冲区
        self::$tsBuffer = '';
        self::$tsIndex++;
    }

    private static function generatePAT()
    {
        // PAT 表头部
        $patHeader = "\x00\x00\xB0\x0D"; // Table ID, section syntax indicator, section length
        $patHeader .= "\x00\x01"; // Transport stream ID
        $patHeader .= "\xC1\x00\x00"; // Version number, current/next indicator, section number, last section number
        $patHeader .= "\x00\x01"; // Program number
        $patHeader .= "\xE1\x00"; // Network PID

        // CRC32 校验
        $crc = crc32($patHeader);
        $patHeader .= pack('N', $crc);

        // 生成 TS 包
        return self::generateTSPacket($patHeader, 0);
    }

    private static function generatePMT()
    {
        // PMT 表头部
        $pmtHeader = "\x02\x00\xB0\x17"; // Table ID, section syntax indicator, section length
        $pmtHeader .= "\x00\x01"; // Program number
        $pmtHeader .= "\xC1\x00\x00"; // Version number, current/next indicator, section number, last section number
        $pmtHeader .= "\xE1\x00"; // PCR PID
        $pmtHeader .= "\xF0\x00"; // Program info length

        // ES info (视频)
        $pmtHeader .= "\x1B"; // Stream type (H.264 video)
        $pmtHeader .= "\xE1\x00"; // Elementary PID
        $pmtHeader .= "\xF0\x00"; // ES info length

        // ES info (音频)
        $pmtHeader .= "\x0F"; // Stream type (AAC audio)
        $pmtHeader .= "\xE1\x01"; // Elementary PID
        $pmtHeader .= "\xF0\x00"; // ES info length

        // CRC32 校验
        $crc = crc32($pmtHeader);
        $pmtHeader .= pack('N', $crc);

        // 生成 TS 包
        return self::generateTSPacket($pmtHeader, 4096);
    }

    private static function updateM3U8File($tsFilename)
    {
        $m3u8FilePath = app_path('/a/b') . '/playlist.m3u8';
        $m3u8Content = '';

        // 如果文件存在，读取现有内容
        if (file_exists($m3u8FilePath)) {
            $m3u8Content = file_get_contents($m3u8FilePath);
        } else {
            // 初始化 M3U8 文件头
            $m3u8Content = "#EXTM3U\n";
            $m3u8Content .= "#EXT-X-VERSION:3\n";
            $m3u8Content .= "#EXT-X-TARGETDURATION:3\n";
            $m3u8Content .= "#EXT-X-MEDIA-SEQUENCE:0\n";
        }

        // 追加新的 TS 文件信息
        $m3u8Content .= "#EXTINF:3.0,\n";
        $m3u8Content .= basename($tsFilename) . "\n";

        // 写入索引文件
        file_put_contents($m3u8FilePath, $m3u8Content);
    }

    private static function resetTimestamp($timestamp)
    {
        // 重置时间戳
        self::$lastTimestamp = $timestamp;
    }
}
