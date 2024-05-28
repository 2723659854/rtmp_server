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

    /** 切片时间 */
    public static  $duration = 3;

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
        $segmentDuration = self::$duration;

        $nowTime = time();
        /** 将数据投递到缓存中 */
        Cache::push($playStreamPath,$frame);
        /** 获取上一次切片的时间 */
        if (Cache::has($playStreamPath)){
            $lastCutTime = Cache::get($playStreamPath);
        }else{
            /** 说明还没有开始切片 ，这是第一个数据包，不用切片 */
            $lastCutTime = $nowTime;
            /** 初始化操作时间 */
            Cache::set($playStreamPath,$nowTime);
        }
        /** 如果上一次的操作时间和当前时间的间隔大于等于切片时间，则开始切片 */
        if (($nowTime-$lastCutTime)>$segmentDuration){
            /** 刷新数据 */
            $mediaData = Cache::flush($playStreamPath);
            /** 更新操作时间 */
            Cache::set($playStreamPath,$nowTime);
        }else{
            /** 否则直接退出操作 */
            return ;
        }
        /** 创建存放切片文件目录 */
        if (!is_dir($outputDir)) {
            @mkdir($outputDir, 0777, true);
        }


        /** 计数器 */
        $continuity_counter = 0;

        /** 获取ts包 */
        $tsFiles = Cache::flush('ts_'.$playStreamPath);
        /** ts文件名称 */
        $tsFile = 'segment' . count($tsFiles) . '.ts';
        /** ts存放路径 */
        $tsFileName = $outputDir . '/' . $tsFile;
        /** 打开ts切片文件 */
        $fileHandle = @fopen($tsFileName, 'wb');
        /**
         * 在 HLS（Http Live Streaming）中，TS 流的结构是一个 TS 文件包含一个 PAT 包、一个 PMT 包和若干个 PES 包。每个ts单元含有一个pes头+多个es包
         * 在对每个 TS 文件进行解析时，首个 TS 包必定是 PAT 包。在 PAT 包的解析过程中，可以解析出 PMT 的 PID 信息，并将 PMT 类和 PID 入队列。
         * 没有找到具体的协议规定，网上说法不一致，不知道怎么搞了
         */
        foreach ($mediaData as $data){
            if ($data->FRAME_TYPE == MediaFrame::VIDEO_FRAME) {
                $videoEs = self::createEsPacket($data);
                $videoPes = self::createPesHeader(0xE0, (string)$videoEs); // 0xE0 是视频流的 stream_id
                self::writeTsPacket(256, $videoPes, $fileHandle, $continuity_counter);
            }
            if ($data->FRAME_TYPE == MediaFrame::AUDIO_FRAME) {
                $audioEs = self::createEsPacket($data);
                $audioPes = self::createPesHeader(0xC0, (string)$audioEs); // 0xC0 是音频流的 stream_id
                self::writeTsPacket(257, $audioPes, $fileHandle, $continuity_counter);
            }
        }
        /** 关闭切片文件 */
        @fclose($fileHandle);

        /** 追加ts切片文件 */
        $tsFiles[]=$tsFile;
        /** 生成播放索引 */
        self::generateM3U8($tsFiles, $outputDir);
        /** 重新缓存所有的ts目录 */
        foreach ($tsFiles as $fileName){
            Cache::push('ts_'.$playStreamPath,$fileName);
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

        return implode('',$es_packets);
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


}