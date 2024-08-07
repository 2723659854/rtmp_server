<?php


namespace Root;

use MediaServer\MediaReader\AACPacket;
use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\AVCPacket;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\MediaReader\VideoFrame;
use MediaServer\MediaServer;

/**
 * Class HlsDemo
 * @package Root
 * @comment hls 协议，现在可以将音频数据写入到ts文件，但是无法被解码。h264写入后无法识别。
 * @note 但是呢，生成的ts文件的pid是正确的，这也算是一大进步呢。
 * @note 存在的问题，音频aac无法解码，提示没有设置采样率，但是实际上设置了采样率不生效。视频的h264直接无法识别。
 */
class HlsDemo
{
    /** 切片时间 3秒 */
    public static $duration = 3000;


    /**
     * 生成符合HLS和MPEG标准的索引文件
     * @param array $tsFiles
     * @param string $outputDir
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
     * 打包ts文件
     * @param int $pid
     * @param string $payload
     * @param $fileHandle
     * @param int $continuity_counter
     * @param int $payload_unit_start_indicator
     * @param int $adaptation_field_control
     */
    public static function writeTsPacket(int $pid, string $payload, $fileHandle, int &$continuity_counter, int $payload_unit_start_indicator = 0, int $adaptation_field_control = 1)
    {
        $packetSize = 188;
        $headerSize = 4;
        $payloadSize = $packetSize - $headerSize;

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
     * 创建ts头
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
     * 创建sdt内容
     * @param $fileHandle
     * @return void
     */
    public static function SDT($fileHandle)
    {
        $bt = array_fill(0, 188, 0xff);
        $data = [
            0x47, 0x40, 0x11, 0x10, // TS header: Sync byte, transport error, payload unit start, transport priority, PID, scrambling control, adaptation field, continuity counter
            0x00,                   // Pointer field
            0x42,                   // Table ID
            0xF0, 0x25,             // Section syntax indicator, '0', reserved, section length
            0x00, 0x01,             // Transport stream ID
            0xC1,                   // Version number, current/next indicator
            0x00,                   // Section number
            0x00,                   // Last section number
            0xFF, 0x01, 0xFF,       // Original network ID, reserved, reserved for future use
            0x00, 0x01,             // Service ID
            0xFC,                   // EIT schedule flag, EIT present/following flag, running status, free CA mode, descriptors loop length
            0x80,                   // Descriptor tag
            0x14,                   // Descriptor length
            0x48,                   // Service descriptor tag
            0x12,                   // Service descriptor length
            0x01,                   // Service type
            0x06,                   // Service provider name length
            0x46, 0x46, 0x6D, 0x70, 0x65, 0x67, // Service provider name ("FFmpeg")
            0x09,                   // Service name length
            0x53, 0x65, 0x72, 0x76, 0x69, 0x63, 0x65, 0x30, 0x31, // Service name ("Service01")
        ];

        // Calculate CRC32 for the SDT section (excluding the TS header and pointer field)
        $crcData = array_slice($data, 5); // Exclude first 5 bytes (TS header + pointer field)
        $crc =  self::crc32_mpeg($crcData);
        $crcBytes = [
            ($crc >> 24) & 0xFF,
            ($crc >> 16) & 0xFF,
            ($crc >> 8) & 0xFF,
            $crc & 0xFF
        ];

        // Append CRC to the data
        $data = array_merge($data, $crcBytes);

        // Replace the beginning of $bt with the generated $data
        array_splice($bt, 0, count($data), $data);

        // Write the data to the file
        fwrite($fileHandle, pack('C*', ...$bt));
    }

    /**
     * 创建pat内容
     * @param $fileHandle
     * @return void
     */
    public static function PAT($fileHandle)
    {
        $bt = array_fill(0, 188, 0xff);
        $data = [
            0x47, 0x40, 0x00, 0x10, // TS header: Sync byte, transport error, payload unit start, transport priority, PID, scrambling control, adaptation field, continuity counter
            0x00,                   // Pointer field
            0x00,                   // Table ID
            0xB0, 0x0D,             // Section syntax indicator, '0', reserved, section length
            0x00, 0x01,             // Transport stream ID
            0xC1,                   // Version number, current/next indicator
            0x00,                   // Section number
            0x00,                   // Last section number
            0x00, 0x01,             // Program number
            0xF0, 0x00,             // Network PID
        ];

        // Calculate CRC32 for the PAT section (excluding the TS header and pointer field)
        $crcData = array_slice($data, 5); // Exclude first 5 bytes (TS header + pointer field)
        $crc = self::crc32_mpeg($crcData);
        $crcBytes = [
            ($crc >> 24) & 0xFF,
            ($crc >> 16) & 0xFF,
            ($crc >> 8) & 0xFF,
            $crc & 0xFF
        ];

        // Append CRC to the data
        $data = array_merge($data, $crcBytes);

        // Replace the beginning of $bt with the generated $data
        array_splice($bt, 0, count($data), $data);

        // Write the data to the file

        fwrite($fileHandle, pack('C*', ...$bt));

    }

    /**
     * 创建pmt内容
     * @param $fileHandle
     * @return void
     */
    public static function PMT($fileHandle)
    {
        $bt = array_fill(0, 188, 0xff);
        $data = [
            0x47, 0x50, 0x00, 0x10, // TS header
            0x00, 0x02, // Pointer field and table_id
            0xB0, 0x17, // Section_length
            0x00, 0x01, // Program_number
            0xC1, 0x00, 0x00, // Version_number, current_next_indicator, section_number, last_section_number
            0xE1, 0x00, // PCR_PID
            0xF0, 0x00, // Program_info_length
            0x1B, 0xE1, 0x00, // Stream_type (video), elementary_PID
            0xF0, 0x00, // ES_info_length
            0x0F, 0xE1, 0x01, // Stream_type (audio), elementary_PID
            0xF0, 0x00, // ES_info_length
        ];

        // Calculate CRC32 for the PMT section (excluding the TS header and pointer field)
        $crcData = array_slice($data, 5); // Exclude first 5 bytes (TS header + pointer field)
        $crc = self::crc32_mpeg($crcData);
        $crcBytes = [
            ($crc >> 24) & 0xFF,
            ($crc >> 16) & 0xFF,
            ($crc >> 8) & 0xFF,
            $crc & 0xFF
        ];

        // Replace the placeholder CRC with the calculated CRC
        array_splice($data, count($data) - 4, 4, $crcBytes);

        array_splice($bt, 0, count($data), $data);

        fwrite($fileHandle, pack('C*', ...$bt));
    }



    public static $avcKeyFrame;

    public static $aacKeyFrame;

    /**
     * 协议入口
     * @param MediaFrame $frame
     * @param string $playStreamPath
     */
    public static function make(MediaFrame $frame, string $playStreamPath)
    {
        if ($frame->FRAME_TYPE == MediaFrame::META_FRAME){
            return;
        }
        if ($frame->FRAME_TYPE == MediaFrame::VIDEO_FRAME){
            /** avc数据包 */
            $avc = $frame->getAVCPacket();
            /** avc数据包类型 */
            $avcPacketType = $avc->avcPacketType;
            /** avc配置头 */
            if ($avcPacketType == AVCPacket::AVC_PACKET_TYPE_SEQUENCE_HEADER) {

                self::$avcKeyFrame = [$frame->_buffer,$frame->timestamp];
                return;
            }
        }
        if ($frame->FRAME_TYPE == MediaFrame::AUDIO_FRAME){
            /** avc数据包 */
            $avc = $frame->getAACPacket();
            /** avc数据包类型 */
            $aacPacketType = $avc->aacPacketType;
            /** avc配置头 */
            if ($aacPacketType == AACPacket::AAC_PACKET_TYPE_SEQUENCE_HEADER) {

                self::$aacKeyFrame = [$frame->_buffer,$frame->timestamp];
                return;
            }
        }
        // 修正音频流的标识符
        // 修改音频流参数（假设 AAC 编码，48kHz 采样率，立体声声道）
        $audioCodec = 0x0F; // MPEG-4 AAC
        $audioSampleRate =  0xBB80; // 48kHz
        $audioChannelConfig = 0x02; // 立体声

        /** 每一种包的计数器是独立的，每一种都是连续的，需要独立计数 */
        $video_continuity_counter = 0;
        $audio_continuity_counter = 0;

        /** ts存放路径 */
        $outputDir = app_path($playStreamPath);
        /** 当前时间 */
        $nowTime = $frame->timestamp;
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
        if (($nowTime - $lastCutTime) >= self::$duration) {
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
        //$continuity_counter = 0;
        /** 获取所有ts目录 */
        $tsFiles = Cache::flush($tsFilesKey);
        /** 生成ts名称 */
        $tsFile = 'segment' . count($tsFiles) . '.ts';
        /** ts存放路径 */
        $tsFileName = $outputDir . '/' . $tsFile;
        /** 打开ts文件 */
        $fileHandle = @fopen($tsFileName, 'wb');
        /** 写入sdt */
        self::SDT($fileHandle);
        /** 写入pat */
        self::PAT($fileHandle);
        /** 写入pmt */
        self::PMT($fileHandle);

        if (self::$avcKeyFrame){
            $videoFrame = new VideoFrame(self::$avcKeyFrame[0],self::$avcKeyFrame[1]);
            array_unshift($mediaData,$videoFrame);
            var_dump("添加视频解码帧");
        }

        if (self::$aacKeyFrame){
            $audioFrame = new AudioFrame(self::$aacKeyFrame[0],self::$aacKeyFrame[1]);
            array_unshift($mediaData,$audioFrame);
            var_dump("添加音频解码帧");
        }

        /** 遍历所有媒体数据 */
        foreach ($mediaData as $frame) {
            /** 判断帧类型 */
            if ($frame->isAudio()) {
                $pid = 257;
                $stream_id = 0xC1; // 音频流ID
                $packet = $frame->getAACPacket()->stream->dump();
                // 创建 AAC PES 包
                $pesPayload = self::createAacPesPayload($packet,  $frame->timestamp, $audioCodec, $audioSampleRate, $audioChannelConfig);
                // 创建 PES 包
                $pesPacket = self::createPes($stream_id, $pesPayload, $frame->timestamp, $frame->timestamp);
                /** 写入ts文件 */
                self::writeTsPacket($pid, $pesPacket, $fileHandle, $audio_continuity_counter, 1);
            } else {
                $packet = $frame->getAVCPacket();
                $compositionTime = $packet->compositionTime;
                $pts = $frame->timestamp + $compositionTime;
                $pid = 256;
                $stream_id = 0xE0; // 视频流ID
                // 创建 H.264 PES 包
                $pesPayload = self::createH264PesPayload($packet->stream->dump(), $pts, $frame->timestamp);
                // 创建 PES 包
                $pesPacket = self::createPes($stream_id, $pesPayload, $pts, $frame->timestamp);
                /** 写入ts文件 */
                self::writeTsPacket($pid, $pesPacket, $fileHandle, $video_continuity_counter, 1);
            }
        }
        /** 关闭ts文件 */
        fclose($fileHandle);
        /** 将ts文件追加到目录 */
        $tsFiles[] = $tsFile;
        /** 生成索引文件 */
        self::generateM3U8($tsFiles, $outputDir);
        /** 将目录重新存入到缓存 */
        foreach ($tsFiles as $fileName) {
            Cache::push($tsFilesKey, $fileName);
        }
    }


    private static function createAacPesPayload(string $payload, int $pts, int $audioCodec, int $audioSampleRate, int $audioChannelConfig): string
    {
        $sampleRateIndex = 3; // 对应48kHz, ADTS采样率索引表

        $adtsHeader = "\xff\xf1" . // syncword: 12 bits, ID: 1 bit, layer: 2 bits, protection absent: 1 bit
            chr((($audioCodec - 1) << 6) | ($sampleRateIndex << 2) | (($audioChannelConfig >> 2) & 0x1)) .
            chr((($audioChannelConfig & 0x3) << 6) | ((strlen($payload) + 7) >> 11)) . // frame length (13 bits)
            chr(((strlen($payload) + 7) >> 3) & 0xFF) . // frame length (remaining 8 bits)
            chr((((strlen($payload) + 7) & 0x7) << 5) | 0x1F) . // frame length (remaining 3 bits) + buffer fullness (5 bits)
            chr(0xFC); // buffer fullness (remaining 6 bits) + 2 bits of 0

        $aacPayload = $adtsHeader . $payload;

        // 生成PES头
        $pesHeader = [
            0x00, 0x00, 0x01, // PES start code prefix
            0xC1, // Stream ID
            0x00, 0x00, // PES packet length (will be set later)
            0x80, // '10' (2 bits) + PES_scrambling_control (2 bits) + PES_priority (1 bit) + data_alignment_indicator (1 bit) + copyright (1 bit) + original_or_copy (1 bit)
            0x80, // '10' (2 bits) + PTS_DTS_flags (2 bits: '10' for PTS only) + ESCR_flag (1 bit) + ES_rate_flag (1 bit) + DSM_trick_mode_flag (1 bit) + additional_copy_info_flag (1 bit) + PES_CRC_flag (1 bit) + PES_extension_flag (1 bit)
            0x05 // PES_header_data_length (5 bytes for PTS)
        ];

        // 生成PTS
        $ptsHeader = [
            (($pts >> 29) & 0x0E) | 0x21,
            (($pts >> 22) & 0xFF),
            (($pts >> 14) & 0xFE) | 0x01,
            (($pts >> 7) & 0xFF),
            (($pts << 1) & 0xFE) | 0x01
        ];

        $pesHeader = array_merge($pesHeader, $ptsHeader);

        // 计算PES包长度
        $pesPacketLength = 3 + 5 + strlen($aacPayload); // 3 bytes for PES start code prefix, 5 bytes for PTS, rest for payload
        $pesHeader[4] = ($pesPacketLength >> 8) & 0xFF;
        $pesHeader[5] = $pesPacketLength & 0xFF;

        // 将PES头和AAC负载合并
        $pesPayload = implode('', array_map('chr', $pesHeader)) . $aacPayload;

        return $pesPayload;
    }





    private static function createH264PesPayload(string $payload, int $pts, int $dts): string
    {
        $pesHeader = "\x00\x00\x01" . chr(0xE0); // H.264 video stream ID
        $pesPacketLength = strlen($payload) + 19; // PES header length (19 bytes)
        $pesHeader .= chr(($pesPacketLength >> 8) & 0xFF);
        $pesHeader .= chr($pesPacketLength & 0xFF);
        $pesHeader .= "\x80"; // no scrambling, no priority, no alignment, PES header present
        $pesHeader .= "\xC0"; // PTS and DTS present
        $pesHeader .= "\x0A"; // PES header data length

        // PTS field encoding
        $pesHeader .= chr((($pts >> 29) & 0x0E) | 0x21); // '0010' + 3 bits of PTS[32..30] + marker '1'
        $pesHeader .= chr(($pts >> 22) & 0xFF); // PTS[29..22]
        $pesHeader .= chr((($pts >> 14) & 0xFE) | 0x01); // PTS[21..15] + marker '1'
        $pesHeader .= chr(($pts >> 7) & 0xFF); // PTS[14..7]
        $pesHeader .= chr((($pts << 1) & 0xFE) | 0x01); // PTS[6..0] + marker '1'

        // DTS field encoding
        $pesHeader .= chr((($dts >> 29) & 0x0E) | 0x11); // '0001' + 3 bits of DTS[32..30] + marker '1'
        $pesHeader .= chr(($dts >> 22) & 0xFF); // DTS[29..22]
        $pesHeader .= chr((($dts >> 14) & 0xFE) | 0x01); // DTS[21..15] + marker '1'
        $pesHeader .= chr(($dts >> 7) & 0xFF); // DTS[14..7]
        $pesHeader .= chr((($dts << 1) & 0xFE) | 0x01); // DTS[6..0] + marker '1'

        return $pesHeader . $payload;
    }


    private static function createPes(int $stream_id, string $payload, int $pts, int $dts): string
    {
        $pesHeader = "\x00\x00\x01" . chr($stream_id);

        // PES packet length: 3 bytes (start code prefix) + 1 byte (stream id) + 2 bytes (packet length) +
        // 1 byte (marker bits) + 1 byte (PTS/DTS flags) + 1 byte (header data length) + 5 bytes (PTS) + 5 bytes (DTS) + payload length
        $pesPacketLength = strlen($payload) + 14; // 14 bytes for the PES header

        if ($pesPacketLength > 0xFFFF) {
            // If the packet length exceeds the maximum value, set it to 0, indicating unspecified length
            $pesHeader .= "\x00\x00";
        } else {
            $pesHeader .= chr(($pesPacketLength >> 8) & 0xFF);
            $pesHeader .= chr($pesPacketLength & 0xFF);
        }

        $pesHeader .= "\x80"; // Marker bits: '10' (no scrambling, no priority, no alignment)
        $pesHeader .= "\xC0"; // PTS and DTS present

        $pesHeader .= "\x0A"; // PES header data length: 10 bytes for PTS and DTS

        // PTS field encoding
        $pesHeader .= chr((($pts >> 29) & 0x0E) | 0x21); // '0010' + 3 bits of PTS[32..30] + marker '1'
        $pesHeader .= chr(($pts >> 22) & 0xFF); // PTS[29..22]
        $pesHeader .= chr((($pts >> 14) & 0xFE) | 0x01); // PTS[21..15] + marker '1'
        $pesHeader .= chr(($pts >> 7) & 0xFF); // PTS[14..7]
        $pesHeader .= chr((($pts << 1) & 0xFE) | 0x01); // PTS[6..0] + marker '1'

        // DTS field encoding
        $pesHeader .= chr((($dts >> 29) & 0x0E) | 0x11); // '0001' + 3 bits of DTS[32..30] + marker '1'
        $pesHeader .= chr(($dts >> 22) & 0xFF); // DTS[29..22]
        $pesHeader .= chr((($dts >> 14) & 0xFE) | 0x01); // DTS[21..15] + marker '1'
        $pesHeader .= chr(($dts >> 7) & 0xFF); // DTS[14..7]
        $pesHeader .= chr((($dts << 1) & 0xFE) | 0x01); // DTS[6..0] + marker '1'

        // Combine PES header and payload
        return $pesHeader . $payload;
    }



    /**
     * 生成 MPEG-TS CRC32 校验
     * @param $data
     * @return int
     */
    public static function crc32_mpeg($data)
    {
        $crc_table = [
            0x00000000, 0x04C11DB7, 0x09823B6E, 0x0D4326D9,
            0x130476DC, 0x17C56B6B, 0x1A864DB2, 0x1E475005,
            0x2608EDB8, 0x22C9F00F, 0x2F8AD6D6, 0x2B4BCB61,
            0x350C9B64, 0x31CD86D3, 0x3C8EA00A, 0x384FBDBD,
            0x4C11DB70, 0x48D0C6C7, 0x4593E01E, 0x4152FDA9,
            0x5F15ADAC, 0x5BD4B01B, 0x569796C2, 0x52568B75,
            0x6A1936C8, 0x6ED82B7F, 0x639B0DA6, 0x675A1011,
            0x791D4014, 0x7DDC5DA3, 0x709F7B7A, 0x745E66CD,
            0x9823B6E0, 0x9CE2AB57, 0x91A18D8E, 0x95609039,
            0x8B27C03C, 0x8FE6DD8B, 0x82A5FB52, 0x8664E6E5,
            0xBE2B5B58, 0xBAEA46EF, 0xB7A96036, 0xB3687D81,
            0xAD2F2D84, 0xA9EE3033, 0xA4AD16EA, 0xA06C0B5D,
            0xD4326D90, 0xD0F37027, 0xDDB056FE, 0xD9714B49,
            0xC7361B4C, 0xC3F706FB, 0xCEB42022, 0xCA753D95,
            0xF23A8028, 0xF6FB9D9F, 0xFBB8BB46, 0xFF79A6F1,
            0xE13EF6F4, 0xE5FFEB43, 0xE8BCCD9A, 0xEC7DD02D,
            0x34867077, 0x30476DC0, 0x3D044B19, 0x39C556AE,
            0x278206AB, 0x23431B1C, 0x2E003DC5, 0x2AC12072,
            0x128E9DCF, 0x164F8078, 0x1B0CA6A1, 0x1FCDBB16,
            0x018AEB13, 0x054BF6A4, 0x0808D07D, 0x0CC9CDCA,
            0x7897AB07, 0x7C56B6B0, 0x71159069, 0x75D48DDE,
            0x6B93DDDB, 0x6F52C06C, 0x6211E6B5, 0x66D0FB02,
            0x5E9F46BF, 0x5A5E5B08, 0x571D7DD1, 0x53DC6066,
            0x4D9B3063, 0x495A2DD4, 0x44190B0D, 0x40D816BA,
            0xACA5C697, 0xA864DB20, 0xA527FDF9, 0xA1E6E04E,
            0xBFA1B04B, 0xBB60ADFC, 0xB6238B25, 0xB2E29692,
            0x8AAD2B2F, 0x8E6C3698, 0x832F1041, 0x87EE0DF6,
            0x99A95DF3, 0x9D684044, 0x902B669D, 0x94EA7B2A,
            0xE0B41DE7, 0xE4750050, 0xE9362689, 0xEDF73B3E,
            0xF3B06B3B, 0xF771768C, 0xFA325055, 0xFEF34DE2,
            0xC6BCF05F, 0xC27DEDE8, 0xCF3ECB31, 0xCBFFD686,
            0xD5B88683, 0xD1799B34, 0xDC3ABDED, 0xD8FBA05A,
            0x690CE0EE, 0x6DCDFD59, 0x608EDB80, 0x644FC637,
            0x7A089632, 0x7EC98B85, 0x738AAD5C, 0x774BB0EB,
            0x4F040D56, 0x4BC510E1, 0x46863638, 0x42472B8F,
            0x5C007B8A, 0x58C1663D, 0x558240E4, 0x51435D53,
            0x251D3B9E, 0x21DC2629, 0x2C9F00F0, 0x285E1D47,
            0x36194D42, 0x32D850F5, 0x3F9B762C, 0x3B5A6B9B,
            0x0315D626, 0x07D4CB91, 0x0A97ED48, 0x0E56F0FF,
            0x1011A0FA, 0x14D0BD4D, 0x19939B94, 0x1D528623,
            0xF12F560E, 0xF5EE4BB9, 0xF8AD6D60, 0xFC6C70D7,
            0xE22B20D2, 0xE6EA3D65, 0xEBA91BBC, 0xEF68060B,
            0xD727BBB6, 0xD3E6A601, 0xDEA580D8, 0xDA649D6F,
            0xC423CD6A, 0xC0E2D0DD, 0xCDA1F604, 0xC960EBB3,
            0xBD3E8D7E, 0xB9FF90C9, 0xB4BCB610, 0xB07DABA7,
            0xAE3AFBA2, 0xAAFBE615, 0xA7B8C0CC, 0xA379DD7B,
            0x9B3660C6, 0x9FF77D71, 0x92B45BA8, 0x9675461F,
            0x8832161A, 0x8CF30BAD, 0x81B02D74, 0x857130C3,
            0x5D8A9099, 0x594B8D2E, 0x5408ABF7, 0x50C9B640,
            0x4E8EE645, 0x4A4FFBF2, 0x470CDD2B, 0x43CDC09C,
            0x7B827D21, 0x7F436096, 0x7200464F, 0x76C15BF8,
            0x68860BFD, 0x6C47164A, 0x61043093, 0x65C52D24,
            0x119B4BE9, 0x155A565E, 0x18197087, 0x1CD86D30,
            0x029F3D35, 0x065E2082, 0x0B1D065B, 0x0FDC1BEC,
            0x3793A651, 0x3352BBE6, 0x3E119D3F, 0x3AD08088,
            0x2497D08D, 0x2056CD3A, 0x2D15EBE3, 0x29D4F654,
            0xC5A92679, 0xC1683BCE, 0xCC2B1D17, 0xC8EA00A0,
            0xD6AD50A5, 0xD26C4D12, 0xDF2F6BCB, 0xDBEE767C,
            0xE3A1CBC1, 0xE760D676, 0xEA23F0AF, 0xEEE2ED18,
            0xF0A5BD1D, 0xF464A0AA, 0xF9278673, 0xFDE69BC4,
            0x89B8FD09, 0x8D79E0BE, 0x803AC667, 0x84FBDBD0,
            0x9ABC8BD5, 0x9E7D9662, 0x933EB0BB, 0x97FFAD0C,
            0xAFB010B1, 0xAB710D06, 0xA6322BDF, 0xA2F33668,
            0xBCB4666D, 0xB8757BDA, 0xB5365D03, 0xB1F740B4,
        ];

        $crc = 0xFFFFFFFF;
        for ($i = 0; $i < count($data); $i++) {
            $byte = ord($data[$i]);
            $crc = $crc_table[(($crc >> 24) ^ $byte) & 0xFF] ^ (($crc << 8) & 0xFFFFFFFF);
        }
        return $crc & 0xFFFFFFFF;
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