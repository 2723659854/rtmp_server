<?php

namespace Root;

use MediaServer\Flv\Flv;
use MediaServer\Flv\FlvTag;
use MediaServer\MediaReader\AACPacket;
use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\AVCPacket;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\MediaReader\VideoFrame;
use MediaServer\MediaServer;
use Root\Cache;

class Mpegts
{

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
     * 创建sdt内容
     * @param $fileHandle
     * @return void
     * @comment 描述
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
        //fwrite(self::$tsFilename, pack('C*', ...$bt));
        file_put_contents(self::$tsFilename,pack('C*', ...$bt),FILE_APPEND);
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

        //fwrite($fileHandle, pack('C*', ...$bt));
        file_put_contents(self::$tsFilename,pack('C*', ...$bt),FILE_APPEND);

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

        //fwrite($fileHandle, pack('C*', ...$bt));
        file_put_contents(self::$tsFilename,pack('C*', ...$bt),FILE_APPEND);
    }

    /**
     * 计算pts
     * @param $dpvalue
     * @return array
     * @comment 播放时间戳
     */
    public static function hexPts($dpvalue)
    {
        // 创建一个长度为5的数组，初始化所有元素为0
        $dphex = array_fill(0, 5, 0);

        // 计算第一个字节
        $dphex[0] = 0x31 | (($dpvalue >> 29) & 0xFF);

        // 计算 hp 和 he
        $hp = ((($dpvalue >> 15) & 0x7FFF) * 2) + 1;
        $he = ((($dpvalue & 0x7FFF) * 2) + 1);

        // 将 hp 和 he 的高8位和低8位分开
        $dphex[1] = ($hp >> 8) & 0xFF;
        $dphex[2] = $hp & 0xFF;
        $dphex[3] = ($he >> 8) & 0xFF;
        $dphex[4] = $he & 0xFF;

        return $dphex;
    }


    /**
     * 计算dts
     * @param $dpvalue
     * @return array
     * @comment 解码时间戳
     */
    public static function hexDts($dpvalue)
    {
        // 创建一个长度为5的数组，初始化所有元素为0
        $dphex = array_fill(0, 5, 0);

        // 计算第一个字节
        $dphex[0] = 0x11 | (($dpvalue >> 29) & 0xFF);

        // 计算 hp 和 he
        $hp = ((($dpvalue >> 15) & 0x7FFF) * 2) + 1;
        $he = ((($dpvalue & 0x7FFF) * 2) + 1);

        // 将 hp 和 he 的高8位和低8位分开
        $dphex[1] = ($hp >> 8) & 0xFF;
        $dphex[2] = $hp & 0xFF;
        $dphex[3] = ($he >> 8) & 0xFF;
        $dphex[4] = $he & 0xFF;

        return $dphex;
    }

    /**
     * 计算pcr
     * @param $dts
     * @return array
     * @comment 时钟用于同步音频和视频
     */
    public static function hexPcr($dts)
    {
        // 创建一个长度为7的数组，初始化所有元素为0
        $adapt = array_fill(0, 7, 0);

        // 计算每个字节
        $adapt[0] = 0x50;
        $adapt[1] = ($dts >> 25) & 0xFF;
        $adapt[2] = ($dts >> 17) & 0xFF;
        $adapt[3] = ($dts >> 9) & 0xFF;
        $adapt[4] = ($dts >> 1) & 0xFF;
        $adapt[5] = (($dts & 0x1) << 7) | 0x7E;

        return $adapt;
    }

    /**
     * 生成pes表头
     * @param $mtype
     * @param $pts
     * @param $dts
     * @return array
     */
    public static function PES($mtype, $pts, $dts)
    {
        // 初始化一个长度为9的数组
        $header = array_fill(0, 9, 0);

        // 复制 {0, 0, 1} 到 header[0:3]
        $header[0] = 0;
        $header[1] = 0;
        $header[2] = 1;
        $header[3] = $mtype;
        $header[6] = 0x80;

        if ($pts > 0) {
            if ($dts > 0) {
                $header[7] = 0xc0;
                $header[8] = 0x0a;
                $header = self::push($header, self::hexPts($pts));
                $header = self::push($header, self::hexDts($dts));
            } else {
                $header[7] = 0x80;
                $header[8] = 0x05;
                $header = self::push($header, self::hexPts($pts));
            }
        }

        return $header;
    }

    /** 视频流ID */
    public static $VideoMark = 0xe0;

    /** 音频流ID */
    public static $AudioMark = 0xc0;

    /** 切片间隔时间 */
    public static $duration = 3000;

    /** ts操作句柄 */
    public static $fileHandle;

    /** 播放索引列表 */
    public static $index = [];
    /** 上一次切片时间 */
    public static $lastCutTime = null;

    public static $tsFilename = null;

    /**
     * 协议入口
     * @param MediaFrame $frame
     * @param string $playStreamPath
     */
    public static function make(MediaFrame $frame, string $playStreamPath)
    {
        /** hls不需要Meta数据包 */
        if ($frame->FRAME_TYPE == MediaFrame::META_FRAME) {
            return;
        }
        /** ts存放路径 */
        $outputDir = app_path($playStreamPath);
        /** 当前时间 */
        $nowTime = $frame->timestamp;
        /** 获取最近一次切片时间 */
        if (!self::$lastCutTime) {
            self::$lastCutTime = $nowTime;
        }
        if (!self::$tsFilename || (($nowTime - self::$lastCutTime) >= self::$duration)){
            /** 获取所有ts目录 */
            $tsFiles = self::$index;
            /** 生成ts名称 */
            $tsFile = 'segment' . count($tsFiles) . '.ts';
            /** ts存放路径 更新ts文件名称 */
            self::$tsFilename = $outputDir . '/' . $tsFile;
            /** 更新上一次操作时间 */
            self::$lastCutTime = $nowTime;
            /** 写入sdt */
            self::SDT(self::$fileHandle);
            /** 写入pat */
            self::PAT(self::$fileHandle);
            /** 写入pmt */
            self::PMT(self::$fileHandle);
            /** 写入视频解码帧 */
            if (self::$avcSeqFrame){
                /** 处理解码帧 */
                self::handleVideo(self::$avcSeqFrame);
            }
            /** 将ts文件追加到目录 */
            $tsFiles[] = $tsFile;
            /** 生成索引文件 */
            self::generateM3U8($tsFiles, $outputDir);
            /** 将目录重新存入到缓存 */
            self::$index[] = $tsFile;
        }
        /** 音频 */
        if ($frame->FRAME_TYPE == MediaFrame::AUDIO_FRAME) {
            self::handleAudio($frame);
        }
        /** 视频 */
        if ($frame->FRAME_TYPE == MediaFrame::VIDEO_FRAME) {
            self::handleVideo($frame);
        }
    }

    /** avc解码帧 */
    public static $avcSeqFrame = null;

    /**
     * 处理视频数据包
     * @param VideoFrame $frame
     */
    public static function handleVideo(VideoFrame $frame)
    {
        /** 原始数据 使用字符串的方式读取 */
        $tag = new FlvTag();
        $tag->type = Flv::VIDEO_TAG;
        $tag->timestamp = $frame->timestamp;
        $tag->data = (string)$frame;
        $tag->dataSize = strlen($tag->data);
        $buffer = Flv::createFlvTag($tag);
        //$buffer = str_split($chunks);

        /** avc数据包 */
        $avc = $frame->getAVCPacket();
        /** avc数据包类型 */
        $avcPacketType = $avc->avcPacketType;
        /** 校正时间戳 */
        $compositionTime = $avc->compositionTime;
        /** 初始化视频帧nalu数据 */
        $nalu = '';
        /** avc配置头 */
        if ($avcPacketType == AVCPacket::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
            /** 先保存解码帧 */

            // spsLen := int(binary.BigEndian.Uint16(tagData[11:13]))
            // 计算 sps 的长度
            $set = MediaServer::$spsInfo;
            $sps = '';
            foreach ($set['sps'] as $value){
                $sps .= $value['content'];
            }

            $spsnalu = "\x00\x00\x00\x01".$sps;
            //nalu = append(nalu, spsnalu...)
            $nalu .= $spsnalu;

            $pps = '';
            foreach ($set['pps'] as $value){
                $pps .=$value['content'];
            }
            /* 组装pps */
            //ppsnalu := append([]byte{0, 0, 0, 1}, pps...)
            $ppsnalu = "\x00\x00\x00\x01".$pps;
            /* 将pps追加到nalu */
            //nalu = append(nalu, ppsnalu...)
            $nalu .= $ppsnalu;

        } elseif ($avcPacketType == AVCPacket::AVC_PACKET_TYPE_NALU) {
            /** 视频原始数据 */
            $readed = 5;
            //for len(tagData) > (readed + 5)
            while (strlen($buffer) > ($readed + 5)) {
                //readleng := int(binary.BigEndian.Uint32(tagData[readed : readed+4]))
                $readleng = unpack("N", substr($buffer,$readed,4))[1];
                $readed += 4;
                /** 追加头*/
                //nalu = append(nalu, []byte{0, 0, 0, 1}...)
                $nalu .= "\x00\x00\x00\x01";
                //	nalu = append(nalu, tagData[readed:readed+readleng]...)
                $nalu .=  substr($buffer,$readed,$readleng);
                $readed += $readleng;
            }
        }

        $dts = $frame->timestamp * 90;
        $pts = $dts + $compositionTime * 90;
        // pes := PES(VideoMark, pts, dts)
        $pes = self::PES(self::$VideoMark, $pts, $dts);
        //t.toPack(VideoMark, append(pes, nalu...))
        $content = implode('',$pes).$nalu;
        self::toPack(self::$VideoMark, $content, $dts);
    }

    /** 处理音频数据 */
    public static function handleAudio(AudioFrame $frame)
    {
        /** 原始数据 使用字符串的方式读取 */
        $tag = new FlvTag();
        $tag->type = Flv::AUDIO_TAG;
        $tag->timestamp = $frame->timestamp;
        $tag->data = (string)$frame;
        $tag->dataSize = strlen($tag->data);
        $buffer = Flv::createFlvTag($tag);
        if ($frame->soundFormat != AudioFrame::SOUND_FORMAT_AAC) {
            var_dump("不是aac编码");
        } else {

            $first = ord($buffer[1]);
            /** 原始数据 */
            if ($first == 1) {
                //tagData = tagData[2:]
                $tagData = substr($buffer,2);
                //adtsHeader := []byte{0xff, 0xf1, 0x4c, 0x80, 0x00, 0x00, 0xfc}
                $adtsHeader = "\xff\xf1\x4c\x80\x00\x00\xfc";
                //adtsLen := uint16(((len(tagData) + 7) << 5) | 0x1f)
                $tagDataLength = strlen($tagData);
                $adtsLen = (($tagDataLength + 7) << 5) | 0x1f;
                //binary.BigEndian.PutUint16(adtsHeader[4:6], adtsLen)
                $adtsHeader[4] = ($adtsLen >> 8) & 0xFF; // 高位
                $adtsHeader[5] = $adtsLen & 0xFF;        // 低位
                //adts := append(adtsHeader, tagData...)
                $adts = $adtsHeader.$tagData;

                $dts = $frame->dts;
                $pts = $dts * 90;
                //pes := PES(AudioMark, pts, 0)
                $pes = self::PES(self::$AudioMark, $pts, 0);
                //t.toPack(AudioMark, append(pes, adts...))
                $content = implode('',$pes).$adts;
                self::toPack(self::$AudioMark, $content, $dts);
            }
        }
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

    /** 生成mpeg包 */
    public static function toPack($mtype, string $pes, $dts)
    {
        /** 是否需要适配 当 pes 的长度小于 184 时），则 adapta 会被设置为 false */
        $adapta = true;
        /** 是否需要混合 当 pes 的长度小于 184 时），则 mixed 会被设置为 true*/
        $mixed = false;

        /** 如果pes还有内容 */
        while ($pes) {
            /** 计算pes长度 */
            $pesLen = strlen($pes);
            /** 长度为0，没有值，退出 */
            if ($pesLen <= 0) {
                break;
            }
            /** pes载荷小于184 ，那么需要混合 */
            if ($pesLen < 184) {
                $mixed = true;
            }
            /** 初始化一个ts包长度188 使用0xf 填充 */
            $cPack = array_fill(0, 188, 0xff);
            //copy(cPack[0:4], t.toHead(adapta, mixed, mtype))
            /* 前4位是header */
            $toHead = self::toHead($adapta, $mixed, $mtype);
            $cPack[0] = $toHead[0];# 0x47
            $cPack[1] = $toHead[1];# 0x01
            $cPack[2] = $toHead[2];# 0x01 或者 0x00
            $cPack[3] = $toHead[3];# 音视频的计数器

            /** 小数据包，不用分割 */
            if ($mixed) {
                /** 需要填充的长度 */
                $fillLen = 183 - $pesLen;

                $cPack[4] = $fillLen;

                /* 如果需要填充字节数大于0 */
                if ($fillLen > 0) {
                    /* 第5位变更为0 */
                    $cPack[5] = 0;
                }
                //copy(cPack[fillLen+5:188], pes[:pesLen])
                /* 将pes所有内容，复制到cpack包的非填充位 */
                for ($i = $fillLen + 5; $i < 188; $i++) {
                    if (isset($pes[$i - ($fillLen + 5)])) {
                        $cPack[$i] = $pes[$i - ($fillLen + 5)];
                    }
                }
                /* 清空切片 */
                $pes = '';
            } elseif ($adapta) {
                /* 长度大于了184，需要分割成多个ts数据包 */
                // 获取pcr 第4位变更为 7
                $cPack[4] = 7;
                //copy(cPack[5:12], hexPcr(t.DTS*uint32(defaultH264HZ)))
                /* 将计算后的pcr写入到包的5-12位 */
                $pcr = self::hexPcr($dts * 90);
                for ($i = 5; $i < 12; $i++) {
                    if (isset($pcr[$i - 5])){
                        $cPack[$i] = $pcr[$i - 5];
                    }
                }
                //copy(cPack[12:188], pes[0:176])
                /* 将pes的前176个字节复制给cpack */
                for ($i = 12; $i < 188; $i++) {
                    if(isset($pes[$i - 12])){
                        $cPack[$i] = $pes[$i - 12];
                    }
                }

                /* 更新pes包内容，删除已被写入的数据 */
                $pes  = substr($pes,176);
            } else {
                //copy(cPack[4:188], pes[0:184])
                /* 分包，将pes的前184位写入到cpack 第4-188位 */
                for ($i = 4; $i < 188; $i++) {
                    if (isset($pes[$i - 4])){
                        $cPack[$i] = $pes[$i - 4];
                    }
                }
                /* 更新pes包内容 */
                $pes = substr($pes,184);
            }
            /* 写入到ts文件中 */
            $adapta = false;
            file_put_contents(self::$tsFilename,pack('C*', ...$cPack),FILE_APPEND);
        }
    }

    /** 缓存 */
    public static $queue = [];

    /** 视频帧计数器 */
    public static $VideoContinuty = 0;
    /** 音频帧计数器 */
    public static $AudioContinuty = 0;

    /**
     * 生成mpeg头
     * @param bool $adapta 是否需要适配
     * @param bool $mixed 是否需要混合
     * @param mixed $mtype 音视频数据类型
     * @return array
     */
    public static function toHead(bool $adapta, bool $mixed, $mtype)
    {
        // 创建一个长度为4的数组，初始化所有元素为0
        $tsHead = array_fill(0, 4, 0);
        // 第一位是固定值 0x47
        $tsHead[0] = 0x47;

        // 设置适配标志位
        if ($adapta) {
            $tsHead[1] |= 0x40;
        }

        // 根据帧类型设置视频或音频标志位
        if ($mtype == self::$VideoMark) {
            $tsHead[1] |= 0x01; // 设置为视频帧
            $tsHead[2] |= 0x00; // 视频帧特定位设置
            $tsHead[3] |= self::$VideoContinuty; // 视频帧计数器
            self::$VideoContinuty = (self::$VideoContinuty + 1) % 16; // 更新视频帧计数器
        } elseif ($mtype == self::$AudioMark) {
            $tsHead[1] |= 0x01; // 设置为音频帧
            $tsHead[2] |= 0x01; // 音频帧特定位设置
            $tsHead[3] |= self::$AudioContinuty; // 音频帧计数器
            self::$AudioContinuty = (self::$AudioContinuty + 1) % 16; // 更新音频帧计数器
        }

        // 设置适配或混合标志位
        if ($adapta || $mixed) {
            $tsHead[3] |= 0x30;
        } else {
            $tsHead[3] |= 0x10;
        }

        return $tsHead;
    }

    /** 数组追加 */
    public static function push($array1, $array2)
    {
        foreach ($array2 as $value) {
            array_push($array1, $value);
        }
        return $array1;
    }
}