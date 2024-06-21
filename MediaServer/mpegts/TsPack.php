<?php

namespace MediaServer\mpegts;

class TsPack {
    use Define;
    private $VideoContinuty = 0;
    private $AudioContinuty = 0;

    public $DTS = 0;
    private $IDR = [];
    private $w;

    public int $DEFAULT_H264_HZ = 90;

    public function newTs($filename) {
        $this->w = fopen($filename, 'wb');
        if (!$this->w) {
            throw new \Exception("Failed to open file: $filename");
        }
        $this->write($this->SDT());
        $this->write($this->PAT());
        $this->write($this->PMT());
        if (!empty($this->IDR)) {
            $this->videoTag($this->IDR);
        }
    }

    private function write($data) {
        fwrite($this->w, pack('C*', ...$data));
    }

    public function toHead($adapta, $mixed, $mtype) {
        $tsHead = [0x47, 0x00, 0x00, 0x00];
        if ($adapta) {
            $tsHead[1] |= 0x40;
        }
        if ($mtype === 0xe0) { // VideoMark
            $tsHead[1] |= 1;
            $tsHead[3] |= $this->VideoContinuty;
            $this->VideoContinuty = ($this->VideoContinuty + 1) % 16;
        } else if ($mtype === 0xc0) { // AudioMark
            $tsHead[1] |= 1;
            $tsHead[2] |= 1;
            $tsHead[3] |= $this->AudioContinuty;
            $this->AudioContinuty = ($this->AudioContinuty + 1) % 16;
        }
        if ($adapta || $mixed) {
            $tsHead[3] |= 0x30;
        } else {
            $tsHead[3] |= 0x10;
        }
        return $tsHead;
    }

    public function toPack($mtype, $pes) {
        $adapta = true;
        $mixed = false;
        while (strlen($pes) > 0) {
            $pesLen = strlen($pes);
            if ($pesLen < 184) {
                $mixed = true;
            }
            $cPack = array_fill(0, 188, 0xff);
            array_splice($cPack, 0, 4, $this->toHead($adapta, $mixed, $mtype));
            if ($mixed) {
                $fillLen = 183 - $pesLen;
                $cPack[4] = $fillLen;
                if ($fillLen > 0) {
                    $cPack[5] = 0;
                }
                array_splice($cPack, $fillLen + 5, $pesLen, array_values(unpack('C*', $pes)));
                $pes = '';
            } else if ($adapta) {
                $cPack[4] = 7;
                array_splice($cPack, 5, 7, $this->hexPcr($this->DTS * $this->DEFAULT_H264_HZ));
                array_splice($cPack, 12, 176, array_values(unpack('C*', substr($pes, 0, 176))));
                $pes = substr($pes, 176);
            } else {
                array_splice($cPack, 4, 184, array_values(unpack('C*', substr($pes, 0, 184))));
                $pes = substr($pes, 184);
            }
            $adapta = false;
            $this->write($cPack);
        }
    }

    public function videoTag($tagData) {
        $codecID = $tagData[0] & 0x0f;
        if ($codecID != 7) {
            throw new \Exception("Encountered non-H264 video data: $codecID");
        }
        $compositionTime = unpack('N', "\0" . substr($tagData, 2, 3))[1];
        $nalu = [];
        if ($tagData[1] == 0) { // avc IDR frame | flv sps pps
            $this->IDR = $tagData;
            $spsLen = unpack('n', substr($tagData, 11, 2))[1];
            $sps = substr($tagData, 13, $spsLen);
            $spsnalu = array_merge([0, 0, 0, 1], array_values(unpack('C*', $sps)));
            $nalu = array_merge($nalu, $spsnalu);
            $ppsLen = unpack('n', substr($tagData, 14 + $spsLen, 2))[1];
            $pps = substr($tagData, 16 + $spsLen, $ppsLen);
            $ppsnalu = array_merge([0, 0, 0, 1], array_values(unpack('C*', $pps)));
            $nalu = array_merge($nalu, $ppsnalu);
        } else if ($tagData[1] == 1) { // avc nalu
            $readed = 5;
            while (strlen($tagData) > ($readed + 5)) {
                $readleng = unpack('N', substr($tagData, $readed, 4))[1];
                $readed += 4;
                $nalu = array_merge($nalu, [0, 0, 0, 1], array_values(unpack('C*', substr($tagData, $readed, $readleng))));
                $readed += $readleng;
            }
        }
        $dts = $this->DTS * $this->DEFAULT_H264_HZ;
        $pts = $dts + $compositionTime * $this->DEFAULT_H264_HZ;
        $pes = implode('',$this->PES(0xe0, $pts, $dts)); // VideoMark
        $this->toPack(0xe0, $pes . implode('', array_map('chr', $nalu)));
    }

    public function audioTag($tagData) {
        $soundFormat = ($tagData[0] & 0xf0) >> 4;
        if ($soundFormat != 10) {
            throw new \Exception("Encountered non-AAC audio data");
        }
        if ($tagData[1] == 1) {
            $tagData = substr($tagData, 2);
            $adtsHeader = [0xff, 0xf1, 0x4c, 0x80, 0x00, 0x00, 0xfc];
            $adtsLen = ((strlen($tagData) + 7) << 5) | 0x1f;
            $adtsHeader[5] = $adtsLen & 0xff;
            $adtsHeader[4] = ($adtsLen >> 8) & 0xff;
            $adts = array_merge($adtsHeader, array_values(unpack('C*', $tagData)));
            $pts = $this->DTS * $this->DEFAULT_H264_HZ;
            $pes = implode('',$this->PES(0xc0, $pts, 0)); // AudioMark
            $this->toPack(0xc0, $pes . implode('', array_map('chr', $adts)));
        }
    }

    public function FlvTag($tagType, $timeStreamp, $timeStreampExtended, $tagData) {
        $dts = $timeStreampExtended * 16777216 + $timeStreamp;
        $this->DTS += $dts;

        if ($tagType == 9) {
            $this->videoTag($tagData);
        } else if ($tagType == 8) {
            $this->audioTag($tagData);
        }
    }


}

