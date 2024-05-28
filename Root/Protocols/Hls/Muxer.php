<?php

namespace Root\Protocols\Hls;

// 定义常量
const tsDefaultDataLen = 184;
const tsPacketLen = 188;
const h264DefaultHZ = 90;
const videoPID = 0x100;
const audioPID = 0x101;
const videoSID = 0xe0;
const audioSID = 0xc0;

// 定义解码器结构体
class Muxer
{
    public $videoCc;
    public $audioCc;
    public $patCc;
    public $pmtCc;
    public $pat;
    public $pmt;
    public $tsPacket;

    // 创建解码器实例
    public function __construct()
    {
        $this->videoCc = 0;
        $this->audioCc = 0;
        $this->patCc = 0;
        $this->pmtCc = 0;
        $this->pat = str_repeat("\x00", tsPacketLen);
        $this->pmt = str_repeat("\x00", tsPacketLen);
        $this->tsPacket = str_repeat("\x00", tsPacketLen);
    }

    // 解码函数
    public function mux($p, $w)
    {
        // 默认第一片
        $first = true;
        $wBytes = 0;
        $pesIndex = 0;
        $tmpLen = 0;
        $dataLen = 0;

        $pes = new pesHeader();
        $dts = $p->getTimeStamp() * h264DefaultHZ;
        $pts = $dts;
        $pid = audioPID;
        $videoH = null;
        if ($p->isVideo()) {
            $pid = videoPID;
            $videoH = $p->getHeader();
            $pts = $dts + $videoH->getCompositionTime() * h264DefaultHZ;
        }
        $err = $pes->packet($p, $pts, $dts);
        if ($err != null) {
            return $err;
        }
        $pesHeaderLen = strlen($pes->data);
        $packetBytesLen = strlen($p->getData()) + $pesHeaderLen;

        while ($packetBytesLen > 0) {
            if ($p->isVideo()) {
                $this->videoCc++;
                if ($this->videoCc > 0xf) {
                    $this->videoCc = 0;
                }
            } else {
                $this->audioCc++;
                if ($this->audioCc > 0xf) {
                    $this->audioCc = 0;
                }
            }

            $i = 0;

            // sync byte
            $this->tsPacket[$i] = 0x47;
            $i++;

            // error indicator, unit start indicator, ts priority, pid
            $this->tsPacket[$i] = ($pid >> 8) & 0xff; // pid high 5 bits
            if ($first) {
                $this->tsPacket[$i] = $this->tsPacket[$i] | 0x40; // unit start indicator
            }
            $i++;

            // pid low 8 bits
            $this->tsPacket[$i] = $pid & 0xff;
            $i++;

            // scram control, adaptation control, counter
            if ($p->isVideo()) {
                $this->tsPacket[$i] = 0x10 | ($this->videoCc & 0x0f);
            } else {
                $this->tsPacket[$i] = 0x10 | ($this->audioCc & 0x0f);
            }
            $i++;

            // 关键帧需要加 pcr
            if ($first && $p->isVideo() && $videoH->isKeyFrame()) {
                $this->tsPacket[3] |= 0x20;
                $this->tsPacket[$i] = 7;
                $i++;
                $this->tsPacket[$i] = 0x50;
                $i++;
                $this->writePcr($this->tsPacket, $i, $dts);
                $i += 6;
            }

            // frame data
            if ($packetBytesLen >= tsDefaultDataLen) {
                $dataLen = tsDefaultDataLen;
                if ($first) {
                    $dataLen -= ($i - 4);
                }
            } else {
                $this->tsPacket[3] |= 0x20; // have adaptation
                $remainBytes = 0;
                $dataLen = $packetBytesLen;
                if ($first) {
                    $remainBytes = tsDefaultDataLen - $dataLen - ($i - 4);
                } else {
                    $remainBytes = tsDefaultDataLen - $dataLen;
                }
                $this->adaptationBufInit($this->tsPacket, $remainBytes);
                $i += $remainBytes;
            }
            if ($first && $i < tsPacketLen && $pesHeaderLen > 0) {
                $tmpLen = tsPacketLen - $i;
                if ($pesHeaderLen <= $tmpLen) {
                    $tmpLen = $pesHeaderLen;
                }
                $this->copyData($this->tsPacket, $i, $pes->data, $pesIndex, $tmpLen);
                $i += $tmpLen;
                $packetBytesLen -= $tmpLen;
                $dataLen -= $tmpLen;
                $pesHeaderLen -= $tmpLen;
                $pesIndex += $tmpLen;
            }

            if ($i < tsPacketLen) {
                $tmpLen = tsPacketLen - $i;
                if ($tmpLen <= $dataLen) {
                    $dataLen = $tmpLen;
                }
                $this->copyData($this->tsPacket, $i, $p->getData(), $wBytes, $dataLen);
                $wBytes += $dataLen;
                $packetBytesLen -= $dataLen;
            }
            if ($w != null) {
                if (!$w->write($this->tsPacket)) {
                    return new \Exception("写入错误");
                }
            }
            $first = false;
        }

        return null;
    }

    // 返回 PAT 数据
    public function PAT()
    {
        $i = 0;
        $remainByte = 0;
        $tsHeader = [0x47, 0x40, 0x00, 0x10, 0x00];
        $patHeader = [0x00, 0xb0, 0x0d, 0x00, 0x01, 0xc1, 0x00, 0x00, 0x00, 0x01, 0xf0, 0x01];

        if ($this->patCc > 0xf) {
            $this->patCc = 0;
        }
        $tsHeader[3] |= $this->patCc & 0x0f;
        $this->patCc++;

        $this->copyData($this->pat, $i, $tsHeader);
        $i += count($tsHeader);

        $this->copyData($this->pat, $i, $patHeader);
        $i += count($patHeader);

        $crc32Value = $this->genCrc32($patHeader);
        $this->pat[$i] = $crc32Value >> 24;
        $i++;
        $this->pat[$i] = $crc32Value >> 16;
        $i++;
        $this->pat[$i] = $crc32Value >> 8;
        $i++;
        $this->pat[$i] = $crc32Value;
        $i++;

        $remainByte = tsPacketLen - $i;
        for ($j = 0; $j < $remainByte; $j++) {
            $this->pat[$i + $j] = 0xff;
        }

        return $this->pat;
    }

    // 返回 PMT 数据
    public function PMT($soundFormat, $hasVideo)
    {
        $i = 0;
        $j = 0;
        $progInfo = [];
        $remainBytes = 0;
        $tsHeader = [0x47, 0x50, 0x01, 0x10, 0x00];
        $pmtHeader = [0x02, 0xb0, 0xff, 0x00, 0x01, 0xc1, 0x00, 0x00, 0xe1, 0x00, 0xf0, 0x00];
        if (!$hasVideo) {
            $pmtHeader[9] = 0x01;
            $progInfo = [0x0f, 0xe1, 0x01, 0xf0, 0x00];
        } else {
            $progInfo = [0x1b, 0xe1, 0x00, 0xf0, 0x00, // h264 or h265*
                0x0f, 0xe1, 0x01, 0xf0, 0x00, // mp3 or aac
            ];
        }
        $pmtHeader[2] = strlen($progInfo) + 9 + 4;

        if ($this->pmtCc > 0xf) {
            $this->pmtCc = 0;
        }
        $tsHeader[3] |= $this->pmtCc & 0x0f;
        $this->pmtCc++;

        if ($soundFormat == 2 ||
            $soundFormat == 14) {
            if ($hasVideo) {
                $progInfo[5] = 0x4;
            } else {
                $progInfo[0] = 0x4;
            }
        }

        $this->copyData($this->pmt, $i, $tsHeader);
        $i += count($tsHeader);

        $this->copyData($this->pmt, $i, $pmtHeader);
        $i += count($pmtHeader);

        $this->copyData($this->pmt, $i, $progInfo);
        $i += count($progInfo);

        $crc32Value = $this->genCrc32($this->pmt, 5, 5 + count($pmtHeader) + count($progInfo));
        $this->pmt[$i] = $crc32Value >> 24;
        $i++;
        $this->pmt[$i] = $crc32Value >> 16;
        $i++;
        $this->pmt[$i] = $crc32Value >> 8;
        $i++;
        $this->pmt[$i] = $crc32Value;
        $i++;

        $remainBytes = tsPacketLen - $i;
        for ($j = 0; $j < $remainBytes; $j++) {
            $this->pmt[$i + $j] = 0xff;
        }

        return $this->pmt;
    }

    // 初始化适应缓冲区
    public function adaptationBufInit(&$src, $remainBytes)
    {
        $src[0] = $remainBytes - 1;
        if ($remainBytes == 1) {
        } else {
            $src[1] = 0x00;
            for ($i = 2; $i < count($src); $i++) {
                $src[$i] = 0xff;
            }
        }
        return;
    }

    // 写入 PCR
    public function writePcr(&$b, $i, $pcr)
    {
        $b[$i] = $pcr >> 25;
        $i++;
        $b[$i] = ($pcr >> 17) & 0xff;
        $i++;
        $b[$i] = ($pcr >> 9) & 0xff;
        $i++;
        $b[$i] = ($pcr >> 1) & 0xff;
        $i++;
        $b[$i] = (($pcr & 0x1) << 7) | 0x7e;
        $i++;
        $b[$i] = 0x00;
        return;
    }

    public function genCrc32($data) {
        $crc32Table = [
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
            0x018A6B13, 0x054B76A4, 0x0808507D, 0x0CC94DCA,
            0x7897AB07, 0x7C56B6B0, 0x71159069, 0x75D48DDA,
            0x6B93DDDC, 0x6F52C06B, 0x6211E6B2, 0x66D0FB05,
            0x5E9F46BF, 0x5A5E5B08, 0x571D7DD1, 0x53DC6066,
            0x4D9B3063, 0x495A2DD4, 0x44190B0D, 0x40D816BA,
            0xaca5c697, 0xa864db20, 0xa527fdf9, 0xa1e6e04e,
            0xbfa1b04b, 0xbb60adfc, 0xb6238b25, 0xb2e29692,
            0x8aad2b2f, 0x8e6c3698, 0x832f1041, 0x87ee0df6,
            0x99a95df3, 0x9d684044, 0x902b669d, 0x94ea7b2a,
            0xe0423697, 0xe4032b20, 0xe9c00dff, 0xed811048,
            0xf346404b, 0xf7075dfc, 0xfa847b25, 0xfec56692,
            0xc62ae695, 0xc2ebee22, 0xcfabc07b, 0xcb6addc4,
            0xd52daadc, 0xd1ece07b, 0xdc6fe6a5, 0xd82cdee2,
            0x690cec5f, 0x6dcdf1e8, 0x608ed731, 0x644fcba6,
            0x7a089ba3, 0x7ec98614, 0x738aad4d, 0x774bb0fa,
            0x4f040d56, 0x4bc510e1, 0x46863638, 0x42472b8f,
            0x5c007b8a, 0x58c1663d, 0x558240e4, 0x51435d53,
            0x251d3b9e, 0x21dc2629, 0x2c9f00f0, 0x285e1d47,
            0x36194d42, 0x32d850f5, 0x3f9b762c, 0x3b5a6b9b,
            0x0315d626, 0x07d4cb91, 0x0a97ed48, 0x0e56f0ff,
            0x1011a0fa, 0x14d0bd4d, 0x19939b94, 0x1d528623,
            0xf12f560e, 0xf5ee4bb9, 0xf8ad6d60, 0xfc6c70d7,
            0xe22b20d2, 0xe6ea3d65, 0xeba91bbc, 0xef68060b,
            0xd727bbb6, 0xd3e6a601, 0xdea580d8, 0xda649d6f,
            0xc423cd6a, 0xc0e2d0dd, 0xcda1f604, 0xcb60ebb3,
            0xbd3e8d7e, 0xb9ff90c9, 0xb4bcb610, 0xb07daba7,
            0xae3afba2, 0xaafbe615, 0xa7b8c0cc, 0xa379dd7b,
            0x9b3660c6, 0x9ff77d71, 0x92b45ba8, 0x9675461f,
            0x8832161a, 0x8cf30bad, 0x81b02d74, 0x857130c3,
            0x5d8a9099, 0x594b8d2e, 0x5408abf7, 0x50c9b640,
            0x4e8ee645, 0x4a4ffbd2, 0x470cdd0b, 0x43cdc0ba,
            0x7b827dfe, 0x7f436049, 0x72004690, 0x76c15b27,
            0x68860b22, 0x6c471695, 0x6104304c, 0x65c52dfb,
            0x119b4be6, 0x155a5651, 0x18197088, 0x1cd86d3f,
            0x029f3d3a, 0x065e208d, 0x0b1d0654, 0x0fdc1bec,
            0x3793a65b, 0x3352bbe4, 0x3e119d3d, 0x3ad0808a,
            0x2497d08f, 0x2056cd38, 0x2d15ebe1, 0x29d4f656,
            0xc5a9267f, 0xc1683bce, 0xcc2b1d17, 0xc8ea00a0,
            0xd6ad50a5, 0xd26c4d12, 0xdf2f6bcb, 0xdbee767c,
            0xe3a1cbc1, 0xe760d676, 0xea23f0af, 0xeee2ed18,
            0xf0a5bd1d, 0xf464a0aa, 0xf9278673, 0xfde69bc4,
            0x89b0365e, 0x8d712bE9, 0x80320d30, 0x84f31087,
            0x9abc4082, 0x9e7d5d35, 0x933e7bec, 0x97ff665b,
            0xafb81656, 0xab790be1, 0xa63a2d38, 0xa2f9308f,
            0xbcbb608a, 0xb87a7d3d, 0xb5395ba4, 0xb1fb4613
        ];
        $crc = 0xffffffff;
        for ($i = 0; $i < strlen($data); $i++) {
            $crc = ($crc << 8) ^ $crc32Table[($crc >> 24) ^ ord($data[$i])];
        }
        return $crc ^ 0xffffffff;
    }

    public function copyData(&$dst, $dstIndex, $src, $srcIndex, $length) {
        for ($i = 0; $i < $length; $i++) {
            $dst[$dstIndex + $i] = $src[$srcIndex + $i];
        }
    }
}
