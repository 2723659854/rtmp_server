<?php

namespace Root;

use MediaServer\MediaReader\MediaFrame;

/**
 * @purpose 本操作类实现将aac和avc数据打包成ts包并生成播放索引文件
 * @comment 本操作类目前尚未验证，有懂hls协议的小伙伴可以修正
 */
class HLSDemo
{

    /**
     * 创建TS包头
     * @param $pid
     * @param $payload_unit_start_indicator
     * @param $continuity_counter
     * @return string
     */
    public static function createTsHeader($pid, $payload_unit_start_indicator = 0, $continuity_counter = 0)
    {
        $sync_byte = 0x47;
        $header = chr($sync_byte);
        $header .= chr(($payload_unit_start_indicator << 6) | ($pid >> 8));
        $header .= chr($pid & 0xFF);
        $header .= chr($continuity_counter & 0xF);
        return $header;
    }

    /**
     * 创建PES包头
     * @param $stream_id
     * @param $payload
     * @return string
     */
    public static function createPesHeader($stream_id, $payload)
    {
        $pes_start_code = "\x00\x00\x01";
        $pes_packet_length = strlen($payload) + 8;
        $header = $pes_start_code;
        $header .= chr($stream_id);
        $header .= chr($pes_packet_length >> 8);
        $header .= chr($pes_packet_length & 0xFF);
        $header .= "\x80\x80\x05\x21\x00\x01\x00\x01\x00";
        return $header . $payload;
    }

    /**
     * 封装ES包
     * @param MediaFrame $data
     * @return string
     */
    public static function createEsPacket(MediaFrame $data)
    {

        // 封装H.264视频数据为ES包
        if ($data->FRAME_TYPE == MediaFrame::VIDEO_FRAME) {
            return self::createVideoESPacket($data->_data);
        }
        // 封装MP3音频数据为ES包
        if ($data->FRAME_TYPE == MediaFrame::AUDIO_FRAME) {
            return self::createAudioESPacket($data->_data);
        }
    }

    /**
     * 写入TS包
     * @param $pid
     * @param $payload
     * @param $fileHandle
     * @param $continuity_counter
     * @return void
     */
    public static function writeTsPacket($pid, $payload, $fileHandle, &$continuity_counter)
    {
        $packetSize = 188;
        $header = self::createTsHeader($pid, 1, $continuity_counter);
        $continuity_counter = ($continuity_counter + 1) % 16;
        $payloadSize = $packetSize - strlen($header);
        $dataLen = strlen($payload);
        for ($i = 0; $i < $dataLen; $i += $payloadSize) {
            $chunk = substr($payload, $i, $payloadSize);
            $packet = $header . $chunk;
            $packet = str_pad($packet, $packetSize, chr(0xFF));
            fwrite($fileHandle, $packet);
        }
    }

    /**
     * 生成M3U8文件
     * @param $tsFiles
     * @param $outputDir
     * @return void
     */
    public static function generateM3U8($tsFiles, $outputDir)
    {
        $m3u8Content = "#EXTM3U\n";
        $m3u8Content .= "#EXT-X-VERSION:3\n";
        $m3u8Content .= "#EXT-X-TARGETDURATION:3\n";
        $m3u8Content .= "#EXT-X-MEDIA-SEQUENCE:0\n";

        foreach ($tsFiles as $tsFile) {
            $m3u8Content .= "#EXTINF:3.000,\n";
            $m3u8Content .= $tsFile . "\n";
        }

        file_put_contents($outputDir . '/playlist.m3u8', $m3u8Content);
    }


    /**
     * 音视频数据打包成ts并生成m3u8索引文件
     * @param MediaFrame $frame 音视频数据包
     * @param string $playStreamPath
     * @return mixed
     * @note 后期不写人文件，而是直接将数据存入到内存，否则这个转hls的任务会影响其他两个协议，会掉帧
     * @note 本方法生成的索引文件和ts文件无法播放，会引起播放器崩溃，需要修正，生成的切片不对，索引文件也不对
     */
    public static function make(MediaFrame $frame,string $playStreamPath)
    {
        /** hls 索引 目录  */
        $outputDir = app_path($playStreamPath);
        /** 切片时间3秒 */
        $segmentDuration = 3;

        if (!is_dir($outputDir)) {
            @mkdir($outputDir, 0777, true);
        }

        $tsFiles = [];
        $continuity_counter = 0;
        $segmentIndex = 0;
        $startTime = time();

        // 伪代码：实际实现中需要从RTMP流中读取数据
        while (true) {
            $data = $frame;
            // 创建TS文件
            $tsFileName = $outputDir . '/segment' . $segmentIndex . '.ts';
            $tsFiles[] = 'segment' . $segmentIndex . '.ts';
            $fileHandle = @fopen($tsFileName, 'wb');

            // 封装ES包（假设数据已经是ES包）
            if ($data->FRAME_TYPE == MediaFrame::VIDEO_FRAME) {
                $videoEs = self::createEsPacket($data);
                // 创建PES包
                $videoPes = self::createPesHeader(0xE0, (string)$videoEs); // 0xE0 是视频流的 stream_id
                self::writeTsPacket(256, $videoPes, $fileHandle, $continuity_counter);
            }
            if ($data->FRAME_TYPE == MediaFrame::AUDIO_FRAME) {
                $audioEs = self::createEsPacket($data);
                $audioPes = self::createPesHeader(0xC0, (string)$audioEs); // 0xC0 是音频流的 stream_id
                self::writeTsPacket(257, $audioPes, $fileHandle, $continuity_counter);
            }


            @fclose($fileHandle);

            $segmentIndex++;

            // 切片时间控制
            if (time() - $startTime >= $segmentDuration) {
                $startTime = time();
                self::generateM3U8($tsFiles, $outputDir);
            }
        }
    }

    // 示例函数：解析NAL单元类型
    public static function parseNALUnitType($nalu)
    {
        // NAL头部的第一个字节的后5位表示NAL单元类型
        return ord(substr($nalu, 0, 1)) & 0x1F;
    }

    // 创建ES包头
    public static function createESPacketHeader($nal_unit_type)
    {
        // 创建NAL头部
        $nal_header = "\x00\x00\x00\x01"; // 帧开始
        // 组合NAL头部和NAL单元类型
        return $nal_header . chr($nal_unit_type);
    }

    // 封装H.264视频数据为ES包
    public static function createVideoESPacket($video_data)
    {
        $es_packets = [];

        // 查找NAL单元的起始位置
        $start = 0;
        while (($start = strpos($video_data, "\x00\x00\x01", $start)) !== false) {
            $start += 3; // 跳过NAL头部的三个字节
            // 查找下一个NAL单元的起始位置
            $end = strpos($video_data, "\x00\x00\x01", $start);
            if ($end === false) {
                $end = strlen($video_data);
            }
            // 提取NAL单元数据
            $nal_unit = substr($video_data, $start, $end - $start);
            // 解析NAL单元类型
            $nal_unit_type = self::parseNALUnitType($nal_unit);
            // 创建ES包头
            $es_packet_header = self::createESPacketHeader($nal_unit_type);
            // 组合ES包头和NAL单元数据
            $es_packet = $es_packet_header . $nal_unit;
            // 添加到ES包数组中
            $es_packets[] = $es_packet;
            // 更新起始位置
            $start = $end;
        }

        return implode('',$es_packets);
    }

    // 封装MP3音频数据为ES包
    public static function createAudioESPacket($audio_data)
    {
        // MP3音频数据即为ES包
        return $audio_data;
    }


}