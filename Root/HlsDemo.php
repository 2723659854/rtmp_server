<?php

namespace Root;

use MediaServer\MediaReader\MediaFrame;

/**
 * @purpose hls协议测试版
 * @comment  生成ts文件无法播放，执行本项目下面的 php check.php 可以检查文件，发现文件有两个pid= 0 和pid =17
 * 导致无法播放。使用ffmpeg检查ffmpeg -i segment0.ts output.mp4，不会报错。但是显示没有任何媒体信息，那么ts文件里面没有可读的数据。
 * 但是生成的ts文件看着和ffmpeg生成差不多，但是都是乱码，看不明白，有什么软件能查看吗？有懂得朋友帮忙修改一下
 */
class HlsDemo
{
    /** 切片时间 3秒 */
    public static $duration = 3;

    /**
     * 创建ts头
     * @param int $pid
     * @param $payload_unit_start_indicator
     * @param $continuity_counter
     * @param $adaptation_field_control
     * @return string
     */
    public static function createTsHeader(int $pid, $payload_unit_start_indicator = 0, $continuity_counter = 0, $adaptation_field_control = 1)
    {
        $sync_byte = 0x47;
        $header = chr($sync_byte);
        $header .= chr((($payload_unit_start_indicator << 6) | ($pid >> 8)) & 0xFF);
        $header .= chr($pid & 0xFF);
        $header .= chr(($adaptation_field_control << 4) | ($continuity_counter & 0x0F));
        return $header;
    }

    /**
     * 创建pes头
     * @param $stream_id
     * @param $payload
     * @param $pts
     * @param $dts
     * @return string
     */
    public static function createPes($stream_id, $payload, $pts, $dts = null)
    {
        $pes_start_code = "\x00\x00\x01";
        $pes_packet_length = 3 + 5 + strlen($payload) + ($dts !== null ? 5 : 0);
        $flags = 0x80;
        $header_data_length = 5;
        if ($dts !== null) {
            $flags |= 0x40;
            $header_data_length += 5;
        }

        $pes_header = $pes_start_code;
        $pes_header .= chr($stream_id);
        $pes_header .= chr($pes_packet_length >> 8);
        $pes_header .= chr($pes_packet_length & 0xFF);
        $pes_header .= chr(0x80);
        $pes_header .= chr($flags);
        $pes_header .= chr($header_data_length);

        $pes_header .= chr(($pts >> 29) & 0x0E | 0x21);
        $pes_header .= chr(($pts >> 22) & 0xFF);
        $pes_header .= chr(($pts >> 14) & 0xFE | 0x01);
        $pes_header .= chr(($pts >> 7) & 0xFF);
        $pes_header .= chr(($pts << 1) & 0xFE | 0x01);

        if ($dts !== null) {
            $pes_header .= chr(($dts >> 29) & 0x0E | 0x11);
            $pes_header .= chr(($dts >> 22) & 0xFF);
            $pes_header .= chr(($dts >> 14) & 0xFE | 0x01);
            $pes_header .= chr(($dts >> 7) & 0xFF);
            $pes_header .= chr(($dts << 1) & 0xFE | 0x01);
        }

        return $pes_header . $payload;
    }

    /**
     * 创建pat包
     * @param $pmtPid
     * @return string
     * @note 节目关联表：主要的作用就是指明了 PMT 表的 PID 值。
     */
    public static function createPatPacket($pmtPid)
    {
        $pat = "\x00\xB0\x0D\x00\x01\xC1\x00\x00\x00\x01" . chr($pmtPid >> 8) . chr($pmtPid & 0xFF) . "\x00\x00";
        return str_pad($pat, 188, chr(0xFF));
    }

    /**
     * 创建pmt包
     * @param int $pcr_pid
     * @param int $video_pid
     * @param int $audio_pid
     * @return string
     * @note 节目映射表：主要的作用就是指明了音视频流的 PID 值。
     */
    public static function createPmtPacket(int $pcr_pid, int $video_pid, int $audio_pid)
    {
        $pmt = "\x02\xB0\x17\x00\x01\xC1\x00\x00";
        $pmt .= chr($pcr_pid >> 8) . chr($pcr_pid & 0xFF) . "\xF0\x00";
        $pmt .= "\x1B" . chr($video_pid >> 8) . chr($video_pid & 0xFF) . "\xF0\x00";
        $pmt .= "\x0F" . chr($audio_pid >> 8) . chr($audio_pid & 0xFF) . "\xF0\x00";
        return str_pad($pmt, 188, chr(0xFF));
    }

    /**
     * 创建网络信息包
     * @return string
     */
    public static function createNitPacket()
    {
        $nit = "\x40\xF0\x11\x00\x01\xC1\x00\x00\x00\x01\xC1\x00\x00\x00\x01\xC1\x00\x00";
        return str_pad($nit, 188, chr(0xFF));
    }

    /**
     * 打包ts文件
     * @param int $pid
     * @param string $payload
     * @param $fileHandle
     * @param int $continuity_counter
     * @param int $payload_unit_start_indicator
     * @param int $adaptation_field_control
     * @return void
     */
    public static function writeTsPacket(int $pid, string $payload, $fileHandle, int &$continuity_counter, int $payload_unit_start_indicator = 0, int $adaptation_field_control = 1)
    {
        $packetSize = 188;
        $payloadSize = $packetSize - 4;
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
     * 将媒体数据打包并生成索引文件
     * @param MediaFrame $frame
     * @param string $playStreamPath
     * @return void
     * @note 协议入口
     */
    public static function make(MediaFrame $frame, string $playStreamPath)
    {
        /** ts存放路径 */
        $outputDir = app_path($playStreamPath);
        /** 切片时间 */
        $segmentDuration = self::$duration;
        /** 当前时间 */
        $nowTime = time();
        /** 媒体数据key */
        $mediaKey = $playStreamPath . '_media';
        /** 切片操作时间key */
        $lastCutTimeKey = $playStreamPath . '_time';
        /** 切片目录key */
        $tsFilesKey = $playStreamPath . '_ts';
        /** 将媒体数据投入到缓存 */
        Cache::push($mediaKey, $frame);
        /** 获取最近一次切片时间 */
        if (Cache::has($lastCutTimeKey)) {
            $lastCutTime = Cache::get($lastCutTimeKey);
        } else {
            $lastCutTime = $nowTime;
            Cache::set($lastCutTimeKey, $nowTime);
        }
        /** 比较当前时间和最近一次切片操作时间 若超过切片时间，则开始本次切片  */
        if (($nowTime - $lastCutTime) >= $segmentDuration) {
            /** 获取所有媒体数据 */
            $mediaData = Cache::flush($mediaKey);
            /** 并更写最近一次切片操作时间 */
            Cache::set($lastCutTimeKey, $nowTime);
        } else {
            /** 否则不操作 */
            return;
        }
        /** 创建目录 */
        if (!is_dir($outputDir)) {
            @mkdir($outputDir, 0777, true);
        }
        /**
         * $continuity_counter参数用于跟踪传输流中连续包的计数器。在传输流中，每个包都有一个序列号（0-15），
         * 用于确保数据包的顺序和完整性。$continuity_counter的作用是确保连续的传输包具有正确的序列号，以满足MPEG-TS协议的要求。
         */
        $continuity_counter = 0;
        /** 获取所有ts目录 */
        $tsFiles = Cache::flush($tsFilesKey);
        /** 生成ts名称 */
        $tsFile = 'segment' . count($tsFiles) . '.ts';
        /** ts存放路径 */
        $tsFileName = $outputDir . '/' . $tsFile;
        /** 打开ts文件 */
        $fileHandle = @fopen($tsFileName, 'wb');
        /** 写入pat包，指定pmt的pid */
        $patPacket = self::createPatPacket(4096); // PMT 的 PID 设置为 4096
        self::writeTsPacket(0, $patPacket, $fileHandle, $continuity_counter, 1);
        /** 创建pmt包 ，指定音视频媒体ID ，视频的pid是256 ，音频是257，pcr_id 同步流ID一般和视频一致 */
        $pmtPacket = self::createPmtPacket(256, 256, 257);
        self::writeTsPacket(4096, $pmtPacket, $fileHandle, $continuity_counter, 1);
        /** 创建网络NIT 包*/
        $nitPacket = self::createNitPacket();
        self::writeTsPacket(17, $nitPacket, $fileHandle, $continuity_counter, 1);

        /** 循环处理音视频数据信息 */
        foreach ($mediaData as $data) {
            /** 视频数据 */
            if ($data->FRAME_TYPE == MediaFrame::VIDEO_FRAME) {
                /** 播放时间戳 */
                $pts = $data->timestamp; // 确保PTS单位是90kHz
                /** 解码时间戳 */
                $dts = $pts;
                /** 视频帧本身就是被打包的es数据 */
                $videoEs = $data->getAVCPacket()->stream->dump();
                /** 打包成pes */
                $videoPes = self::createPes(0xE0, $videoEs, $pts, $dts);
                /** 打包成ts包 */
                self::writeTsPacket(256, $videoPes, $fileHandle, $continuity_counter);
            } elseif ($data->FRAME_TYPE == MediaFrame::AUDIO_FRAME) {
                /** 音频只有播放时间戳 */
                $pts = $data->timestamp; // 确保PTS单位是90kHz
                /** 音频本身就是被打包成es的包 */
                $audioEs = $data->getAACPacket()->stream->dump();
                /** 打包成pes */
                $audioPes = self::createPes(0xC0, $audioEs, $pts);
                /** 写入到ts包 */
                self::writeTsPacket(257, $audioPes, $fileHandle, $continuity_counter);
            } elseif ($data->FRAME_TYPE == MediaFrame::META_FRAME) {
                // 处理META_FRAME数据
                $metaEs = $data->dump();
                $metaPes = self::createPes(0xFC, $metaEs, $data->timestamp);
                self::writeTsPacket(258, $metaPes, $fileHandle, $continuity_counter, 1);
            }
        }
        /** 关闭ts文件 */
        @fclose($fileHandle);
        /** 将ts文件追加到目录 */
        $tsFiles[] = $tsFile;
        /** 生成索引文件 */
        self::generateM3U8($tsFiles, $outputDir);
        /** 将目录重新存入到缓存 */
        foreach ($tsFiles as $fileName) {
            Cache::push($tsFilesKey, $fileName);
        }
    }

    /**
     * 生成索引文件
     * @param array $tsFiles
     * @param string $outputDir
     * @return void
     */
    public static function generateM3U8(array $tsFiles, string $outputDir)
    {
        $m3u8Content = "#EXTM3U\n";
        $m3u8Content .= "#EXT-X-VERSION:3\n";
        $m3u8Content .= "#EXT-X-TARGETDURATION:3\n";
        $m3u8Content .= "#EXT-X-MEDIA-SEQUENCE:0\n";
        foreach ($tsFiles as $tsFile) {
            $m3u8Content .= "#EXTINF:3.000,\n";
            $m3u8Content .= $tsFile . "\n";
        }
        $m3u8Content .= "#EXT-X-ENDLIST\n";
        file_put_contents($outputDir . '/playlist.m3u8', $m3u8Content);
    }

    /**
     * 清空数据
     * @param string $playStreamPath
     * @return void
     */
    public static function close(string $playStreamPath)
    {
        /** 媒体数据key */
        $mediaKey = $playStreamPath . '_media';
        Cache::clear($mediaKey);
        /** 切片操作时间key */
        $lastCutTimeKey = $playStreamPath . '_time';
        Cache::clear($lastCutTimeKey);
        /** 切片目录key */
        $tsFilesKey = $playStreamPath . '_ts';
        Cache::clear($tsFilesKey);
    }
}

