<?php

namespace Root;

use MediaServer\MediaReader\MediaFrame;

/**
 * @purpose hls协议服务
 * @comment 本协议可能存在很大问题，生成的ts文件无法播放，可能会导致播放器崩溃。期望有对hls协议比较了解的同仁帮助修正，谢谢
 * @note 在MediaServer::publisherOnFrame() 里面开启调用
 */
class HLSDemo
{
    /** 切片时间 */
    public static $duration = 3;

    /**
     * 创建TS包头
     * @param $pid
     * @param $payload_unit_start_indicator
     * @param $continuity_counter
     * @param $adaptation_field_control
     * @return string
     */
    public static function createTsHeader($pid, $payload_unit_start_indicator = 0, $continuity_counter = 0, $adaptation_field_control = 0)
    {
        $sync_byte = 0x47;
        $header = chr($sync_byte);
        $header .= chr((($payload_unit_start_indicator << 6) | ($pid >> 8)) | ($adaptation_field_control << 4));
        $header .= chr($pid & 0xFF);
        $header .= chr(($continuity_counter & 0x0F));
        return $header;
    }

    /**
     * 创建PES包头
     * @param $stream_id
     * @param $payload
     * @param $pts
     * @param $dts
     * @return string
     */
    public static function createPesHeader($stream_id, $payload, $pts = null, $dts = null)
    {
        $pes_start_code = "\x00\x00\x01";
        $pes_packet_length = strlen($payload) + 6 + (($pts !== null) ? 5 : 0); // header size + payload size
        $header = $pes_start_code;
        $header .= chr($stream_id);
        $header .= chr($pes_packet_length >> 8);
        $header .= chr($pes_packet_length & 0xFF);
        $header .= (($pts !== null) ? "\x80" : "") . (($dts !== null) ? "\x40" : "") . "\x05";
        if ($pts !== null) {
            $header .= chr(0x20 | (($pts >> 29) & 0x07));
            $header .= chr(($pts >> 22) & 0xFF);
            $header .= chr((($pts >> 15) & 0xFF) | 0x01);
            $header .= chr(($pts >> 7) & 0xFF);
            $header .= chr((($pts & 0xFF) << 1) | 0x01);
        }
        if ($dts !== null) {
            $header .= chr(0x11);
            $header .= chr(0x22);
            $header .= chr(0x33);
            $header .= chr(0x44);
            $header .= chr(0x55);
        }
        return $header . $payload;
    }

    /**
     * 创建PAT包
     * @return string
     */
    public static function createPatPacket()
    {
        $pat = "\x00\xB0\x0D\x00\x01\xC1\x00\x00\x00\x01\xF0\x01\x2E";
        return str_pad($pat, 188, chr(0xFF));
    }

    /**
     * 创建PMT包
     * @param $pcr_pid
     * @param $video_pid
     * @param $audio_pid
     * @return string
     */
    public static function createPmtPacket($pcr_pid, $video_pid, $audio_pid)
    {
        $pmt = "\x02\xB0\x17\x00\x01\xC1\x00\x00";
        $pmt .= chr($pcr_pid >> 8) . chr($pcr_pid & 0xFF) . "\xF0\x00";
        $pmt .= "\x1B" . chr($video_pid >> 8) . chr($video_pid & 0xFF) . "\xF0\x00";
        $pmt .= "\x0F" . chr($audio_pid >> 8) . chr($audio_pid & 0xFF) . "\xF0\x00";
        return str_pad($pmt, 188, chr(0xFF));
    }

    /**
     * 写入TS包
     * @param $pid
     * @param $payload
     * @param $fileHandle
     * @param $continuity_counter
     * @param $payload_unit_start_indicator
     * @param $adaptation_field_control
     * @return void
     */
    public static function writeTsPacket($pid, $payload, $fileHandle, &$continuity_counter, $payload_unit_start_indicator = 0, $adaptation_field_control = 1)
    {
        $packetSize = 188;
        $payloadSize = $packetSize - 4; // 4 bytes for TS header

        $dataLen = strlen($payload);
        $i = 0;

        while ($i < $dataLen) {
            $header = self::createTsHeader($pid, ($i == 0) ? $payload_unit_start_indicator : 0, $continuity_counter, $adaptation_field_control);
            $continuity_counter = ($continuity_counter + 1) % 16;

            $chunk = substr($payload, $i, $payloadSize);
            $i += $payloadSize;

            if (strlen($chunk) < $payloadSize) {
                $chunk = str_pad($chunk, $payloadSize, chr(0xFF));
            }

            $packet = $header . $chunk;
            fwrite($fileHandle, $packet);
        }
    }

    /**
     * 音视频数据打包成ts并生成m3u8索引文件
     * @param MediaFrame $frame 音视频数据包
     * @param string $playStreamPath
     * @return mixed
     */
    public static function make(MediaFrame $frame, string $playStreamPath)
    {
        /** hls 索引 目录  */
        $outputDir = app_path($playStreamPath);
        /** 切片时间3秒 */
        $segmentDuration = self::$duration;

        $nowTime = time();
        /** 将数据投递到缓存中 */
        Cache::push($playStreamPath, $frame);
        /** 获取上一次切片的时间 */
        if (Cache::has($playStreamPath)) {
            $lastCutTime = Cache::get($playStreamPath);
        } else {
            /** 说明还没有开始切片 ，这是第一个数据包，不用切片 */
            $lastCutTime = $nowTime;
            /** 初始化操作时间 */
            Cache::set($playStreamPath, $nowTime);
        }
        /** 如果上一次的操作时间和当前时间的间隔大于等于切片时间，则开始切片 */
        if (($nowTime - $lastCutTime) > $segmentDuration) {
            /** 刷新数据 */
            $mediaData = Cache::flush($playStreamPath);
            /** 更新操作时间 */
            Cache::set($playStreamPath, $nowTime);
        } else {
            /** 否则直接退出操作 */
            return;
        }
        /** 创建存放切片文件目录 */
        if (!is_dir($outputDir)) {
            @mkdir($outputDir, 0777, true);
        }

        /** 计数器 */
        $continuity_counter = 0;

        /** 获取ts包 */
        $tsFiles = Cache::flush('ts_' . $playStreamPath);
        /** ts文件名称 */
        $tsFile = 'segment' . count($tsFiles) . '.ts';
        /** ts存放路径 */
        $tsFileName = $outputDir . '/' . $tsFile;
        /** 打开ts切片文件 */
        $fileHandle = @fopen($tsFileName, 'wb');

        /** 写入PAT包 */
        $patPacket = self::createPatPacket();
        self::writeTsPacket(0, $patPacket, $fileHandle, $continuity_counter, 1);

        /** 写入PMT包 这里的pid也不知道正不正确，应该从流中获取 */
        $pmtPacket = self::createPmtPacket(256, 256, 257); // Example PIDs
        self::writeTsPacket(4096, $pmtPacket, $fileHandle, $continuity_counter, 1);

        /** 循环将aac和avc数据写入到ts文件 ，无法播放的原因可能是写入的数据有问题 也许不应该是$data->_data吧 */
        foreach ($mediaData as $data) {
            if ($data->FRAME_TYPE == MediaFrame::VIDEO_FRAME) {
                $videoEs = self::createEsPacket($data);
                $videoPes = self::createPesHeader(0xE0, $videoEs,$data->timestamp,$data->timestamp); // 0xE0 是视频流的 stream_id
                //$videoPes = self::createPesHeader(0xE0, $videoEs); // 0xE0 是视频流的 stream_id
                self::writeTsPacket(256, $videoPes, $fileHandle, $continuity_counter, 1);
            }
            if ($data->FRAME_TYPE == MediaFrame::AUDIO_FRAME) {
                $audioEs = self::createEsPacket($data);
                $audioPes = self::createPesHeader(0xC0, $audioEs,$data->timestamp,$data->timestamp); // 0xC0 是音频流的 stream_id
                //$audioPes = self::createPesHeader(0xC0, $audioEs); // 0xC0 是音频流的 stream_id
                self::writeTsPacket(257, $audioPes, $fileHandle, $continuity_counter, 1);
            }
            if ($data->FRAME_TYPE == MediaFrame::META_FRAME) {
                $metaEs = self::createMetaESPacket($data);
                $metaPes = self::createPesHeader(0xFC, $metaEs); // 0xFC 假设为元数据流的 stream_id
                self::writeTsPacket(258, $metaPes, $fileHandle, $continuity_counter, 1);
            }
        }
        /** 关闭切片文件 */
        @fclose($fileHandle);

        /** 追加ts切片文件 */
        $tsFiles[] = $tsFile;
        /** 生成播放索引 */
        self::generateM3U8($tsFiles, $outputDir);
        /** 重新缓存所有的ts目录 */
        foreach ($tsFiles as $fileName) {
            Cache::push('ts_' . $playStreamPath, $fileName);
        }
    }

    /**
     * 封装元数据为ES包
     * @param $meta_data
     * @return string
     */
    public static function createMetaESPacket($meta_data)
    {
        // 假设meta_data是字符串形式
        $es_packet = "\x00\x00\x00\x01" . $meta_data->_data; // 加入帧开始码
        return $es_packet;
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
     * 示例函数：解析NAL单元类型
     * @param $nalu
     * @return int
     */
    public static function parseNALUnitType($nalu)
    {
        // NAL头部的第一个字节的后5位表示NAL单元类型
        return ord(substr($nalu, 0, 1)) & 0x1F;
    }

    /**
     * 创建ES包头
     * @param $nal_unit_type
     * @return string
     */
    public static function createESPacketHeader($nal_unit_type)
    {
        // 创建NAL头部
        $nal_header = "\x00\x00\x00\x01"; // 帧开始
        // 组合NAL头部和NAL单元类型
        return $nal_header . chr($nal_unit_type);
    }

    /**
     * 封装H.264视频数据为ES包
     * @param $video_data
     * @return string
     */
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

        return implode('', $es_packets);
    }

    /**
     * 封装MP3音频数据为ES包
     * @param $audio_data
     * @return mixed
     */
    public static function createAudioESPacket($audio_data)
    {
        // MP3音频数据即为ES包
        return $audio_data;
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
}
