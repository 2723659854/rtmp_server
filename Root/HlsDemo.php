<?php


namespace Root;

use MediaServer\MediaReader\MediaFrame;

/**
 * Class HlsDemo
 * @package Root
 * @comment hls 协议，现在可以将音频数据写入到ts文件，但是无法被解码。h264写入后无法识别。
 */
class HlsDemo
{
    /** 切片时间 3秒 */
    public static $duration = 3;


    /**
     * 创建pat包
     * @param $pmtPid
     * @return string
     * @comment 用于指定pmt的pid
     */
    public static function createPatPacket($pmtPid)
    {
        $pat = "\x00\xB0\x0D\x00\x01\xC1\x00\x00\x00\x01" . chr($pmtPid >> 8) . chr($pmtPid & 0xFF) . "\x00\x00";
        $pat .= pack('N', self::crc32_mpeg($pat));

        return str_pad($pat, 188, chr(0x00)); // 修改填充为0x00，保持标准
    }

    /**
     * 创建pmt包
     * @param int $pcr_pid
     * @param int $video_pid
     * @param int $audio_pid
     * @return string
     * @comment 指定音视频的pid
     */
    public static function createPmtPacket(int $pcr_pid, int $video_pid, int $audio_pid)
    {
        $pmt = "\x02\xB0\x17\x00\x01\xC1\x00\x00";
        $pmt .= chr($pcr_pid >> 8) . chr($pcr_pid & 0xFF) . "\xF0\x00";
        $pmt .= "\x1B" . chr($video_pid >> 8) . chr($video_pid & 0xFF) . "\xE0\x00"; // 修改视频流ID为0xE0，符合标准
        $pmt .= "\x0F" . chr($audio_pid >> 8) . chr($audio_pid & 0xFF) . "\xC0\x00"; // 修改音频流ID为0xC0，符合标准
        $pmt .= pack('N', self::crc32_mpeg($pmt));
        return str_pad($pmt, 188, chr(0x00)); // 修改填充为0x00，保持标准
    }

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
     * 创建nit包
     * @return string
     * @comment 创建符合MPEG标准的网络信息表，NIT用于描述和指导整个传输系统的网络信息
     */
    public static function createNitPacket()
    {
        $nit = "\x40\xF0\x11\x00\x01\xC1\x00\x00\x00\x01\xC1\x00\x00\x00\x01\xC1\x00\x00";
        $nit .= pack('N', self::crc32_mpeg($nit));
        return str_pad($nit, 188, chr(0xFF));
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
     * 协议入口
     * @param MediaFrame $frame
     * @param string $playStreamPath
     */
    public static function make(MediaFrame $frame, string $playStreamPath)
    {
        // 修正音频流的标识符
        $streamId = 0xC1; // AAC 音频流ID

        /** todo 以下几个参数需要从音频帧中获取，不能写死，否则无法解码 */
        // 修改音频流参数（假设 AAC 编码，48kHz 采样率，立体声声道）
        $audioCodec = 0x0F; // MPEG-4 AAC
        $audioSampleRate = 0x03; // 48kHz
        $audioChannelConfig = 0x02; // 立体声

        /** 每一种包的计数器是独立的，每一种都是连续的，需要独立计数 */
        $pat_continuity_counter = 0;
        $pmt_continuity_counter = 0;
        $video_continuity_counter = 0;
        $audio_continuity_counter = 0;

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
        //$continuity_counter = 0;
        /** 获取所有ts目录 */
        $tsFiles = Cache::flush($tsFilesKey);
        /** 生成ts名称 */
        $tsFile = 'segment' . count($tsFiles) . '.ts';
        /** ts存放路径 */
        $tsFileName = $outputDir . '/' . $tsFile;
        /** 打开ts文件 */
        $fileHandle = @fopen($tsFileName, 'wb');
        //todo 下面写入的pid可能有问题，pid = [0,17,4096]分别出现了两次，是不合理的。目前尚不知道问题出在哪里
        /** 写入pat包，指定pmt的pid */
        $patPacket = self::createPatPacket(4096); // PMT 的 PID 设置为 4096
        self::writeTsPacket(0, $patPacket, $fileHandle, $pat_continuity_counter, 1);
        /** 写入pmt包，指定视频和音频的pid pcr同步ID应该和视频一样 */
        $pmtPacket = self::createPmtPacket(256, 256, 257);
        self::writeTsPacket(4096, $pmtPacket, $fileHandle, $pmt_continuity_counter, 1);
        /** 写入nit包，描述网络信息 */
        $nitPacket = self::createNitPacket();
        self::writeTsPacket(17, $nitPacket, $fileHandle, $pmt_continuity_counter, 1);
        /** 遍历所有媒体数据 */
        foreach ($mediaData as $frame) {
            /** 判断帧类型 */
            if ($frame->isAudio()) {
                $pid = 257;
                $stream_id = $streamId; // 音频流ID
                // 创建 AAC PES 包
                $pesPayload = self::createAacPesPayload($frame->getPayload(), $frame->pts, $frame->dts, $audioCodec, $audioSampleRate, $audioChannelConfig);
                // 创建 PES 包
                $pesPacket = self::createPes($stream_id, $pesPayload, $frame->pts, $frame->dts);
                /** 写入ts文件 */
                self::writeTsPacket($pid, $pesPacket, $fileHandle, $audio_continuity_counter, 1);
            } else {
                $pid = 256;
                $stream_id = 0xE0; // 视频流ID
                // 创建 H.264 PES 包
                $pesPayload = self::createH264PesPayload($frame->getPayload(), $frame->pts, $frame->dts);
                // 创建 PES 包
                $pesPacket = self::createPes($stream_id, $pesPayload, $frame->pts, $frame->dts);
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

    /**
     * 创建 AAC PES 包
     */
    private static function createAacPesPayload(string $payload, int $pts, int $dts, int $audioCodec, int $audioSampleRate, int $audioChannelConfig): string
    {
        // AAC 视频流描述字节
        $adtsHeader = pack('C', 0xFF) . // syncword: 12 bits
            pack('C', 0xF1) . // syncword extension: 3 bits, ID: 1 bit, layer: 2 bits, protection absent: 1 bit
            pack('C', 0x40 | (($audioCodec - 1) << 2) | (($audioSampleRate >> 1) & 0x03)) . // profile: 2 bits, sampling frequency index: 4 bits, private bit: 1 bit, channel configuration: 3 bits
            pack('C', (($audioSampleRate << 7) & 0x80) | (($audioChannelConfig & 0x0F) << 3)) . // channel configuration (cont'd): 4 bits, original: 1 bit, home: 1 bit
            pack('n', strlen($payload) + 7); // size of the packet (including the header)

        // PTS 和 DTS 字节
        $ptsDtsBytes = pack('CC', 0x80 | (($pts >> 29) & 0x07), (($pts >> 22) & 0xFF)) . // PTS
            pack('CC', (($pts >> 14) & 0xFF) | 0x01, (($pts >> 7) & 0xFF)) . // PTS
            pack('CC', (($pts << 1) & 0xFE) | 0x01, 0x80) . // PTS
            pack('CC', 0x80 | (($dts >> 29) & 0x07), (($dts >> 22) & 0xFF)) . // DTS
            pack('CC', (($dts >> 14) & 0xFF) | 0x01, (($dts >> 7) & 0xFF)) . // DTS
            pack('CC', (($dts << 1) & 0xFE) | 0x01, 0x80); // DTS

        // AAC 数据
        $aacPayload = $adtsHeader . $ptsDtsBytes . $payload;

        return $aacPayload;
    }


    /**
     * 创建 H.264 PES 包
     * @comment 这个函数可能有问题，缺少了视频帧处理的控制参数，可能是宽，高，解码器，码率，导致生成的ts文件无法识别视频数据
     */
    private static function createH264PesPayload(string $payload, int $pts, int $dts): string
    {
        // PTS 和 DTS 字节
        $ptsDtsBytes = pack('CC', 0x80 | (($pts >> 29) & 0x07), (($pts >> 22) & 0xFF)) . // PTS
            pack('CC', (($pts >> 14) & 0xFF) | 0x01, (($pts >> 7) & 0xFF)) . // PTS
            pack('CC', (($pts << 1) & 0xFE) | 0x01, 0x80) . // PTS
            pack('CC', 0x80 | (($dts >> 29) & 0x07), (($dts >> 22) & 0xFF)) . // DTS
            pack('CC', (($dts >> 14) & 0xFF) | 0x01, (($dts >> 7) & 0xFF)) . // DTS
            pack('CC', (($dts << 1) & 0xFE) | 0x01, 0x80); // DTS

        // H.264 数据
        $h264Payload = $ptsDtsBytes . $payload;

        return $h264Payload;
    }


    /**
     * 将音频数据包打包
     * @param int $stream_id
     * @param string $payload
     * @param int $pts
     * @param int $dts
     * @return string
     * @comment 这个方法是对的，但是打包的数据无法解码
     */
    private static function createPes(int $stream_id, string $payload, int $pts, int $dts): string
    {
        $pesHeader = "\x00\x00\x01" . chr($stream_id);
        $pesPacketLength = strlen($payload) + 8; // PES header length (9 bytes) - 1
        $pesHeader .= chr(($pesPacketLength >> 8) & 0xFF);
        $pesHeader .= chr($pesPacketLength & 0xFF);
        $pesHeader .= "\x80\x80\x05"; // 0x80: no scrambling, no priority, no alignment, PES header present
        // 0x80: PTS only, no DTS
        // 0x05: PES header data length
        $pesHeader .= chr((($pts >> 29) & 0x0E) | 0x21); // PTS[32..30] and marker bits
        $pesHeader .= chr(($pts >> 22) & 0xFF);         // PTS[29..22]
        $pesHeader .= chr((($pts >> 14) & 0xFE) | 0x01); // PTS[21..15] and marker bits
        $pesHeader .= chr(($pts >> 7) & 0xFF);          // PTS[14..7]
        $pesHeader .= chr((($pts << 1) & 0xFE) | 0x01); // PTS[6..0] and marker bits

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
        for ($i = 0; $i < strlen($data); $i++) {
            $byte = ord($data[$i]);
            $crc = $crc_table[(($crc >> 24) ^ $byte) & 0xFF] ^ (($crc << 8) & 0xFFFFFFFF);
        }
        return ~$crc & 0xFFFFFFFF;
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