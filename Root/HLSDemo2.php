<?php


namespace Root;

use MediaServer\MediaReader\MediaFrame;

/**
 * @purpose hls协议
 * @comment 感觉打脑壳额，生成的ts文件看着像那么回事，但是无法播放。应该是哪里参数不对。有兴趣的朋友可以帮忙修正以下。
 * @note 里面有两张pat包，错误，需要修正
 * 正确的ts报数据应该是：
 * Done
 * PID: 0000 (0x0000) 1 packets：这是 PAT（Program Association Table）包，应该只有一个。
 * PID: 0017 (0x0011) 1 packets：这是 PMT（Program Map Table）包，应该只有一个。
 * PID: 0256 (0x0100) 622 packets：这是视频流的包，数量很多，符合预期。
 * PID: 0257 (0x0101) 46 packets：这是音频流的包，数量较少，但也是预期的。
 * PID: 4096 (0x1000) 1 packets：扩展的数据pid。
 * 上面的数据是ffmpeg生成的
 * 本协议生成的包的数据：
 *Done
 * 5749 MPEG TS packets read
 * PID: 0000 (0x0000)      2 packets
 * PID: 0256 (0x0100)      5591 packets
 * PID: 0257 (0x0101)      154 packets
 * PID: 4096 (0x1000)      2 packets
 * 缺少了一个pid=17，多了一个pid=0=》多了一个pat表 少了一个pmt表
 * 需要手动生成 这两张表，仔细检查一下
 */
class HLSDemo2
{

    public static $duration = 3;

    public static function createTsHeader(int $pid, $payload_unit_start_indicator = 0, $continuity_counter = 0, $adaptation_field_control = 1)
    {
        $sync_byte = 0x47;
        $header = chr($sync_byte);
        $header .= chr((($payload_unit_start_indicator << 6) | ($pid >> 8)) & 0xFF);
        $header .= chr($pid & 0xFF);
        $header .= chr(($adaptation_field_control << 4) | ($continuity_counter & 0x0F));
        return $header;
    }

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

    public static function createPatPacket(int $pmt_pid)
    {
        $pat = "\x00"; // pointer_field
        $pat .= "\x00\xB0"; // table_id = 0x00, section_syntax_indicator = 1, '0' = 0, reserved = 3 bits, section_length = 13 bits
        $pat .= "\x0D\x00\x01"; // transport_stream_id = 0x0001
        $pat .= "\xC1\x00\x00"; // version_number = 0, current_next_indicator = 1, section_number = 0, last_section_number = 0
        $pat .= "\x00\x01"; // program_number = 0x0001
        $pat .= chr(($pmt_pid >> 8) & 0xFF) . chr($pmt_pid & 0xFF); // reserved = 3 bits, PID = 13 bits
        $crc = crc32($pat);
        $pat .= chr(($crc >> 24) & 0xFF) . chr(($crc >> 16) & 0xFF) . chr(($crc >> 8) & 0xFF) . chr($crc & 0xFF);
        return str_pad($pat, 188, chr(0xFF));
    }

    public static function createPmtPacket(int $pcr_pid, int $video_pid, int $audio_pid)
    {
        $pmt = "\x02\xB0\x17\x00\x01\xC1\x00\x00";
        $pmt .= chr($pcr_pid >> 8) . chr($pcr_pid & 0xFF) . "\xF0\x00";
        $pmt .= "\x1B" . chr($video_pid >> 8) . chr($video_pid & 0xFF) . "\xF0\x00";
        $pmt .= "\x0F" . chr($audio_pid >> 8) . chr($audio_pid & 0xFF) . "\xF0\x00";
        return str_pad($pmt, 188, chr(0xFF));
    }

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
     * @param MediaFrame $frame 流媒体数据包
     * @param string $playStreamPath 播放路径
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
        $wrotePat = false;
        $wrotePmt = false;

        foreach ($mediaData as $data) {
            if (!$wrotePat) {
                $patPacket = self::createPatPacket(17); // PMT PID = 17
                self::writeTsPacket(0, $patPacket, $fileHandle, $continuity_counter, 1);
                $wrotePat = true;
            }

            if (!$wrotePmt) {
                $pmtPacket = self::createPmtPacket(256, 256, 257); // PCR PID = 256, video PID = 256, audio PID = 257
                self::writeTsPacket(17, $pmtPacket, $fileHandle, $continuity_counter, 1);
                $wrotePmt = true;
            }

            if ($data->FRAME_TYPE == MediaFrame::VIDEO_FRAME) {
                $pts = $data->timestamp * 90000;
                $dts = $pts;
                $videoEs = $data->getAVCPacket()->stream->dump();
                $videoPes = self::createPesHeader(0xE0, $videoEs, $pts, $dts);
                self::writeTsPacket(256, $videoPes, $fileHandle, $continuity_counter, 1);
            }

            if ($data->FRAME_TYPE == MediaFrame::AUDIO_FRAME) {
                $pts = $data->timestamp * 90000;
                $audioEs = $data->getAACPacket()->stream->dump();
                $audioPes = self::createPesHeader(0xC0, $audioEs, $pts);
                self::writeTsPacket(257, $audioPes, $fileHandle, $continuity_counter, 1);
            }

            if ($data->FRAME_TYPE == MediaFrame::META_FRAME) {
                $metaEs = "\x00\x00\x00\x01" . $data->dump();
                $metaPes = self::createPesHeader(0xFC, $metaEs, $data->timestamp);
                self::writeTsPacket(256, $metaPes, $fileHandle, $continuity_counter, 1);
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
     * @param array $tsFiles ts目录
     * @param string $outputDir 存放路径
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
}
