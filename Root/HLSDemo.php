<?php

namespace Root;

use MediaServer\MediaReader\MediaFrame;

/**
 * @purpose hls协议
 * @comment 感觉打脑壳额，生成的ts文件看着像那么回事，但是无法播放。应该是哪里参数不对。有兴趣的朋友可以帮忙修正以下。
 * @note 里面有两张pat包，错误，需要修正
 * 正确的ts报数据应该是：
 * Done
PID: 0000 (0x0000) 1 packets：这是 PAT（Program Association Table）包，应该只有一个。
PID: 0017 (0x0011) 1 packets：这是 PMT（Program Map Table）包，应该只有一个。
PID: 0256 (0x0100) 622 packets：这是视频流的包，数量很多，符合预期。
PID: 0257 (0x0101) 46 packets：这是音频流的包，数量较少，但也是预期的。
PID: 4096 (0x1000) 1 packets：扩展的数据pid。
上面的数据是ffmpeg生成的
 * 本协议生成的包的数据：
 *Done
5749 MPEG TS packets read
PID: 0000 (0x0000)      2 packets
PID: 0256 (0x0100)      5591 packets
PID: 0257 (0x0101)      154 packets
PID: 4096 (0x1000)      2 packets

 * 缺少了一个pid=17，多了一个pid=0=》多了一个pat表 少了一个pmt表
 * 需要手动生成 这两张表，仔细检查一下
 */
class HLSDemo
{
    /** 切片时间3秒 */
    public static $duration = 3;

    /**
     * 创建ts头
     * @param int $pid
     * @param $payload_unit_start_indicator
     * @param $transport_priority
     * @param $transport_scrambling_control
     * @param $adaptation_field_control
     * @param $continuity_counter
     * @return string
     * @comment 参考 https://blog.csdn.net/m0_37599645/article/details/117135283
     */
    public static function createTsHeader(int $pid, $payload_unit_start_indicator = 0, $transport_priority = 0, $transport_scrambling_control = 0, $adaptation_field_control = 1, $continuity_counter = 0)
    {
        $sync_byte = 0x47;
        $header = chr($sync_byte);
        $header .= chr((($payload_unit_start_indicator << 7) | ($transport_priority << 6) | ($pid >> 8)) & 0xFF);
        $header .= chr($pid & 0xFF);
        $header .= chr((($transport_scrambling_control << 6) | ($adaptation_field_control << 4) | ($continuity_counter & 0x0F)) & 0xFF);
        return $header;
    }

    /**
     * 打包成ts
     * @param int $pid
     * @param string $payload
     * @param $fileHandle
     * @param int $continuity_counter
     * @param int $payload_unit_start_indicator
     * @param int $transport_priority
     * @param int $transport_scrambling_control
     * @param int $adaptation_field_control
     * @return void
     * @comment 参考 https://blog.csdn.net/m0_37599645/article/details/117135283
     */
    public static function writeTsPacket(int $pid, string $payload, $fileHandle, int &$continuity_counter, int $payload_unit_start_indicator = 0, int $transport_priority = 0, int $transport_scrambling_control = 0, int $adaptation_field_control = 1)
    {
        $packetSize = 188;
        $payloadSize = $packetSize - 4; // 4 bytes for TS header
        $dataLen = strlen($payload);
        $i = 0;
        while ($i < $dataLen) {
            $header = self::createTsHeader($pid, ($i == 0) ? $payload_unit_start_indicator : 0, $transport_priority, $transport_scrambling_control, $adaptation_field_control, $continuity_counter);
            $continuity_counter = ($continuity_counter + 1) % 16;

            $chunk = substr($payload, $i, $payloadSize);
            $i += $payloadSize;

            $adaptation_field_length = $packetSize - strlen($header) - strlen($chunk) - 1; // 1 byte for adaptation_field_length
            $adaptation_field = "\x00"; // Adaptation field length

            if ($adaptation_field_length > 0) {
                // Padding adaptation field to meet required length
                $adaptation_field .= str_repeat("\xFF", $adaptation_field_length - 1); // Subtract 1 for adaptation_field_length byte
                $adaptation_field .= "\x00"; // Discontinuity indicator and random access indicator set to 0
            }

            $packet = $header . $adaptation_field . $chunk;
            fwrite($fileHandle, $packet);
        }
    }

    /**
     * 创建pes头
     * @param $stream_id
     * @param $payload
     * @param $pts
     * @param $dts
     * @return string
     * @comment 参考 https://blog.csdn.net/m0_37599645/article/details/117135283
     */
    public static function createPesHeader($stream_id, $payload, $pts, $dts = null)
    {
        $pes_start_code = "\x00\x00\x01";
        $pes_packet_length = 3 + 2 + strlen($payload); // header size + payload size
        $flags = 0x80; // '10' for PTS
        $header_data_length = 5; // PTS is always present
        if ($dts !== null) {
            $flags |= 0x40; // '1' for DTS
            $header_data_length += 5;
        }

        $pes_header = $pes_start_code;
        $pes_header .= chr($stream_id);
        $pes_header .= chr($pes_packet_length >> 8);
        $pes_header .= chr($pes_packet_length & 0xFF);
        $pes_header .= chr(0x80); // marker bits '10'
        $pes_header .= chr($flags);
        $pes_header .= chr($header_data_length);

        // PTS
        $pes_header .= chr(0x21 | (($pts >> 29) & 0x0E));
        $pes_header .= chr(($pts >> 22) & 0xFF);
        $pes_header .= chr(0x01 | (($pts >> 14) & 0xFE));
        $pes_header .= chr(($pts >> 7) & 0xFF);
        $pes_header .= chr(0x01 | (($pts << 1) & 0xFE));

        // DTS
        if ($dts !== null) {
            $pes_header .= chr(0x11 | (($dts >> 29) & 0x0E));
            $pes_header .= chr(($dts >> 22) & 0xFF);
            $pes_header .= chr(0x01 | (($dts >> 14) & 0xFE));
            $pes_header .= chr(($dts >> 7) & 0xFF);
            $pes_header .= chr(0x01 | (($dts << 1) & 0xFE));
        }

        return $pes_header . $payload;
    }


    /** 自适应区的长度要包含传输错误指示符标识的一个字节。pcr 是节目时钟参考，pcr、dts、pts 都是对同
     * 一个系统时钟的采样值，pcr 是递增的，因此可以将其设置为 dts 值，音频数据不需要 pcr。如果没有字
     * 段，ipad 是可以播放的，但 vlc 无法播放。打包 ts 流时 PAT 和 PMT 表是没有 adaptation field 的，
     * 不够的长度直接补 0xff 即可。视频流和音频流都需要加 adaptation field，通常加在一个帧的第一个 ts
     * 包和最后一个 ts 包中，中间的 ts 包不加。
     */


    /**
     * 创建pat节目表
     * @comment 用来指定pmt的pid
     * @note 参考 https://blog.csdn.net/m0_37599645/article/details/117135283
     */
    public static function createPatPacket(int $pmt_pid)
    {
        // PAT table_id = 0x00, section_syntax_indicator = 1, reserved = 3 bits, section_length = 13 bits
        $pat = "\x00\xB0";

        // section_length, transport_stream_id, reserved, version_number, current_next_indicator, section_number, last_section_number
        $pat .= "\x00\x0D\x00\x01\xC1\x00\x00";

        // Program_number, reserved, PID
        $pat .= "\x00\x01" . chr(($pmt_pid >> 8) & 0xFF) . chr($pmt_pid & 0xFF);

        // CRC32 placeholder (4 bytes)
        $pat .= "\x00\x00\x00\x00";

        // Calculate CRC32
        $crc = crc32($pat);

        // Append CRC32
        $pat .= chr(($crc >> 24) & 0xFF) . chr(($crc >> 16) & 0xFF) . chr(($crc >> 8) & 0xFF) . chr($crc & 0xFF);

        // Ensure the PAT packet is 188 bytes long
        return str_pad($pat, 188, chr(0xFF));
    }


    /**
     * 创建pmt信息流表
     * 参考 https://blog.csdn.net/m0_37599645/article/details/117135283
     */
     public static function createPmtPacket(int $pcr_pid, int $video_pid, int $audio_pid)
    {
        // PMT table_id = 0x02, section_syntax_indicator = 1
        $pmt = "\x02\xB0";

        // section_length (13 bits), program_number, version_number, current_next_indicator, section_number, last_section_number
        $pmt .= "\x00\x17\x00\x01\xC1\x00\x00";

        // PCR_PID (13 bits), reserved (2 bits), program_info_length
        $pmt .= chr(($pcr_pid >> 8) & 0xFF) . chr($pcr_pid & 0xFF) . "\xF0\x00";

        // Video stream
        $pmt .= "\x1B" . chr($video_pid >> 8) . chr($video_pid & 0xFF) . "\xF0\x00";

        // Audio stream
        $pmt .= "\x0F" . chr($audio_pid >> 8) . chr($audio_pid & 0xFF) . "\xF0\x00";

        // CRC32 placeholder (4 bytes)
        $pmt .= "\x00\x00\x00\x00";

        // Ensure the PMT packet is 188 bytes long
        return str_pad($pmt, 188, chr(0xFF));
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
        /*--------------------------------------------------------------------------------*/
        // todo 修正写入pat包和pmt包这里 现在被写入了两个pat和两个pmt 导致无法播放
        /** 先创建pat表 PAT（Program Association Table）节目关联表：主要的作用就是指明了 PMT 表的 PID 值。*/
        $patPacket = self::createPatPacket(17);
        self::writeTsPacket(0, $patPacket, $fileHandle, $continuity_counter, 1);
        /**  PMT（Program Map Table）节目映射表：主要的作用就是指明了音视频流的 PID 值 视频的pid是256，音频的pid257 一般使用视频的pid作为pcr_id*/
        /** 在 MPEG-TS 流中，PCR（Program Clock Reference）是一个重要的时间基准，用于同步解码过程中的音视频数据。通常，PCR PID 被设置为视频流的 PID，因为视频流通常有更稳定和精确的时间戳。 */
        /** pcr 是节目时钟参考，pcr、dts、pts 都是对同一个系统时钟的采样值，pcr 是递增的，因此可以将其设置为 dts 值，音频数据不需要 pcr。*/
        $pmtPacket = self::createPmtPacket(256, 256, 257);
        self::writeTsPacket(4096, $pmtPacket, $fileHandle, $continuity_counter, 1);
        /*--------------------------------------------------------------------------------------*/
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
