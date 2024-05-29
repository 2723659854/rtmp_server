<?php


namespace Root;

use MediaServer\MediaReader\MediaFrame;


class HLSDemo2
{
    /** 切片时间3秒 */
    public static $duration = 3;

    /**
     * 创建ts包头
     * @param int $pid
     * @param int $payload_unit_start_indicator
     * @param int $continuity_counter
     * @param int $adaptation_field_control
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
     * 创建pes包头
     * @param int $stream_id
     * @param string $payload
     * @param int $pts
     * @param int|null $dts
     * @return string
     */
    public static function createPesHeader($stream_id, $payload, $pts, $dts = null)
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
     * 生成ts包
     * @param int $pid
     * @param string $payload
     * @param resource $fileHandle
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
     * hls协议入口
     * @param MediaFrame $frame
     * @param string $playStreamPath
     * @return void
     */
    public static function make(MediaFrame $frame, string $playStreamPath)
    {
        /** ts数据包保存路径 */
        $outputDir = app_path($playStreamPath);
        /** 切片时间 */
        $segmentDuration = self::$duration;
        /** 当前时间 */
        $nowTime = time();
        /** 先将媒体数据投递到缓存中 */
        Cache::push($playStreamPath, $frame);
        /** 获取上一次切片操作时间 */
        if (Cache::has($playStreamPath)) {
            $lastCutTime = Cache::get($playStreamPath);
        } else {
            /** 如果没有，则设置当前时间为最后一次切片时间 */
            $lastCutTime = $nowTime;
            Cache::set($playStreamPath, $nowTime);
        }
        /** 比较当前时间和上一次切片时间，若大于切片时间，则取出全部媒体数据准备打包。 */
        if (($nowTime - $lastCutTime) > $segmentDuration) {
            $mediaData = Cache::flush($playStreamPath);
            Cache::set($playStreamPath, $nowTime);
        } else {
            /** 否则，退出等待下一次操作 */
            return;
        }
        /** 创建目录 */
        if (!is_dir($outputDir)) {
            @mkdir($outputDir, 0777, true);
        }
        /** 切片的序号，因为可能一个音视频帧数据很大，会被分割成很多个小的包，*/
        $continuity_counter = 0;
        /** 取出所有的切片文件名称 */
        $tsFiles = Cache::flush('ts_' . $playStreamPath);
        /** 设置切片文件名称 */
        $tsFile = 'segment' . count($tsFiles) . '.ts';
        /** 设置新切片文件路径 */
        $tsFileName = $outputDir . '/' . $tsFile;
        /** 打开切片文件 */
        $fileHandle = @fopen($tsFileName, 'wb');
        /** 先创建pat表 */
        $patPacket = self::createPatPacket();
        /** 写入pat表数据 */
        self::writeTsPacket(0, $patPacket, $fileHandle, $continuity_counter, 1);
        /** 创建pmt表 */
        $pmtPacket = self::createPmtPacket(256, 257,258);
        /** 写入pmt数据 */
        self::writeTsPacket(4096, $pmtPacket, $fileHandle, $continuity_counter, 1);
        /** 循环处理媒体数据 */
        foreach ($mediaData as $data) {
            /** 处理视频帧数据 */
            if ($data->FRAME_TYPE == MediaFrame::VIDEO_FRAME) {
                /** 获取播放时间戳 */
                $pts = $data->timestamp * 90000; // 时间戳转换成90kHz时钟
                /** 获取解码时间戳 */
                $dts = $pts;
                /** 获取视频帧es数据，因为这个已经是被编码器编译过的，不需要再次编码 */
                $videoEs = $data->getAVCPacket()->stream->dump();
                /** 创建视频帧的pes包 */
                $videoPes = self::createPesHeader(0xE0, $videoEs, $pts, $dts);
                /** 写入ts包 */
                self::writeTsPacket(256, $videoPes, $fileHandle, $continuity_counter, 1);
            }
            /** 如果是音频数据包 */
            if ($data->FRAME_TYPE == MediaFrame::AUDIO_FRAME) {
                /** 处理播放时间戳 */
                $pts = $data->timestamp * 90000; // 时间戳转换成90kHz时钟
                /** 处理aac音频数据 */
                $audioEs = $data->getAACPacket()->stream->dump();
                /** 创建音频pes */
                $audioPes = self::createPesHeader(0xC0, $audioEs, $pts);
                /** 创建ts包 */
                self::writeTsPacket(257, $audioPes, $fileHandle, $continuity_counter, 1);
            }
            /** 如果是媒体元数据 */
            if ($data->FRAME_TYPE == MediaFrame::META_FRAME) {
                /** 创建es包 */
                $metaEs = "\x00\x00\x00\x01" . $data->dump();
                /** 创建pes包 */
                $metaPes = self::createPesHeader(0xFC, $metaEs, $data->timestamp);
                /** 创建ts包 */
                self::writeTsPacket(258, $metaPes, $fileHandle, $continuity_counter, 1);
            }
        }
        /** 关闭ts文件 */
        @fclose($fileHandle);
        /** 追加到切片文件目录 */
        $tsFiles[] = $tsFile;
        /** 生成索引文件 */
        self::generateM3U8($tsFiles, $outputDir);
        /** 将目录存入缓存 */
        foreach ($tsFiles as $fileName) {
            Cache::push('ts_' . $playStreamPath, $fileName);
        }
    }

    /**
     * 生成播放列表索引
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
     * 创建pat节目表
     * @return string
     */
    public static function createPatPacket()
    {
        $pat = "\x00\xB0\x0D\x00\x01\xC1\x00\x00\x00\x01\xF0\x01\x2E";
        return str_pad($pat, 188, chr(0xFF));
    }

    /**
     * pmt信息流表
     * @param int $pcr_pid
     * @param int $video_pid
     * @param int $audio_pid
     * @return string
     */
    public static function createPmtPacket(int $pcr_pid, int $video_pid, int $audio_pid)
    {
        $pmt = "\x02\xB0\x17\x00\x01\xC1\x00\x00";
        $pmt .= chr($pcr_pid >> 8) . chr($pcr_pid & 0xFF) . "\xF0\x00";
        $pmt .= "\x1B" . chr($video_pid >> 8) . chr($video_pid & 0xFF) . "\xF0\x00";
        $pmt .= "\x0F" . chr($audio_pid >> 8) . chr($audio_pid & 0xFF) . "\xF0\x00";
        return str_pad($pmt, 188, chr(0xFF));
    }
}

