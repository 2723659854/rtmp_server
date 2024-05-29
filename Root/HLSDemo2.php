<?php


namespace Root;

use MediaServer\MediaReader\MediaFrame;

/**
 * @purpose 备份代码
 * @time 2024年5月29日16:26:18
 */
class HLSDemo2
{
    public static $duration = 3;

    public static function createTsHeader($pid, $payload_unit_start_indicator = 0, $continuity_counter = 0, $adaptation_field_control = 1)
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
        $pes_packet_length = 3 + 5 + strlen($payload) + ($dts !== null ? 5 : 0); // header size + payload size
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

    public static function createPatPacket()
    {
        $pat = "\x00\xB0\x0D\x00\x01\xC1\x00\x00\x00\x01\xF0\x01\x2E";
        return str_pad($pat, 188, chr(0xFF));
    }

    public static function createPmtPacket($pcr_pid, $video_pid, $audio_pid)
    {
        $pmt = "\x02\xB0\x17\x00\x01\xC1\x00\x00";
        $pmt .= chr($pcr_pid >> 8) . chr($pcr_pid & 0xFF) . "\xF0\x00";
        $pmt .= "\x1B" . chr($video_pid >> 8) . chr($video_pid & 0xFF) . "\xF0\x00";
        $pmt .= "\x0F" . chr($audio_pid >> 8) . chr($audio_pid & 0xFF) . "\xF0\x00";
        return str_pad($pmt, 188, chr(0xFF));
    }

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

    public static function make(MediaFrame $frame, string $playStreamPath)
    {
        $outputDir = app_path($playStreamPath);
        $segmentDuration = self::$duration;
        $nowTime = time();
        Cache::push($playStreamPath, $frame);

        if (Cache::has($playStreamPath)) {
            $lastCutTime = Cache::get($playStreamPath);
        } else {
            $lastCutTime = $nowTime;
            Cache::set($playStreamPath, $nowTime);
        }

        if (($nowTime - $lastCutTime) > $segmentDuration) {
            $mediaData = Cache::flush($playStreamPath);
            Cache::set($playStreamPath, $nowTime);
        } else {
            return;
        }

        if (!is_dir($outputDir)) {
            @mkdir($outputDir, 0777, true);
        }

        $continuity_counter = 0;
        $tsFiles = Cache::flush('ts_' . $playStreamPath);
        $tsFile = 'segment' . count($tsFiles) . '.ts';
        $tsFileName = $outputDir . '/' . $tsFile;
        $fileHandle = @fopen($tsFileName, 'wb');
        $patPacket = self::createPatPacket();
        self::writeTsPacket(0, $patPacket, $fileHandle, $continuity_counter, 1);
        $pmtPacket = self::createPmtPacket(256, 256, 257);
        self::writeTsPacket(4096, $pmtPacket, $fileHandle, $continuity_counter, 1);

        foreach ($mediaData as $data) {
            if ($data->FRAME_TYPE == MediaFrame::VIDEO_FRAME) {
                $pts = $data->timestamp * 90000; // 时间戳转换成90kHz时钟
                $dts = $pts;
                $videoEs = $data->getAVCPacket()->stream->dump();
                $videoPes = self::createPesHeader(0xE0, $videoEs, $pts, $dts);
                self::writeTsPacket(256, $videoPes, $fileHandle, $continuity_counter, 1);
            }

            if ($data->FRAME_TYPE == MediaFrame::AUDIO_FRAME) {
                $pts = $data->timestamp * 90000; // 时间戳转换成90kHz时钟
                $audioEs = $data->getAACPacket()->stream->dump();
                $audioPes = self::createPesHeader(0xC0, $audioEs, $pts);
                self::writeTsPacket(257, $audioPes, $fileHandle, $continuity_counter, 1);
            }

            if ($data->FRAME_TYPE == MediaFrame::META_FRAME) {
                $metaEs = "\x00\x00\x00\x01" . $data->dump();
                $metaPes = self::createPesHeader(0xFC, $metaEs, $data->timestamp * 90000); // 时间戳转换成90kHz时钟
                self::writeTsPacket(258, $metaPes, $fileHandle, $continuity_counter, 1);
            }
        }

        @fclose($fileHandle);

        $tsFiles[] = $tsFile;
        self::generateM3U8($tsFiles, $outputDir);
        foreach ($tsFiles as $fileName) {
            Cache::push('ts_' . $playStreamPath, $fileName);
        }
    }

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
        $m3u8Content .= "#EXT-X-ENDLIST\n";
        file_put_contents($outputDir . '/playlist.m3u8', $m3u8Content);
    }
}

