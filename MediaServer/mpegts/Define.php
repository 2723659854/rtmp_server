<?php

namespace MediaServer\mpegts;

trait  Define
{

    // 定义全局变量
    public $VideoMark = 0xe0;
    public $AudioMark = 0xc0;

    public function hexPts($dpvalue)
    {
        $dphex = [];
        $dphex[0] = 0x31 | ($dpvalue >> 29);
        $hp = (($dpvalue >> 15) & 0x7fff) * 2 + 1;
        $dphex[1] = $hp >> 8;
        $dphex[2] = $hp & 0xff;
        $he = ($dpvalue & 0x7fff) * 2 + 1;
        $dphex[3] = $he >> 8;
        $dphex[4] = $he & 0xff;
        return $dphex;
    }

    public function hexDts($dpvalue)
    {
        $dphex = [];
        $dphex[0] = 0x11 | ($dpvalue >> 29);
        $hp = (($dpvalue >> 15) & 0x7fff) * 2 + 1;
        $dphex[1] = $hp >> 8;
        $dphex[2] = $hp & 0xff;
        $he = ($dpvalue & 0x7fff) * 2 + 1;
        $dphex[3] = $he >> 8;
        $dphex[4] = $he & 0xff;
        return $dphex;
    }

    public function hexPcr($dts)
    {
        $adapt = [];
        $adapt[0] = 0x50;
        $adapt[1] = $dts >> 25;
        $adapt[2] = ($dts >> 17) & 0xff;
        $adapt[3] = ($dts >> 9) & 0xff;
        $adapt[4] = ($dts >> 1) & 0xff;
        $adapt[5] = (($dts & 0x1) << 7) | 0x7e;
        return $adapt;
    }

    public function SDT()
    {
        $bt = array_fill(0, 188, 0xff);
        $data = [
            0x47, 0x40, 0x11, 0x10,
            0x00, 0x42, 0xF0, 0x25, 0x00, 0x01, 0xC1, 0x00, 0x00, 0xFF,
            0x01, 0xFF, 0x00, 0x01, 0xFC, 0x80, 0x14, 0x48, 0x12, 0x01,
            0x06, 0x46, 0x46, 0x6D, 0x70, 0x65, 0x67, 0x09, 0x53, 0x65,
            0x72, 0x76, 0x69, 0x63, 0x65, 0x30, 0x31, 0x77, 0x7C, 0x43,
            0xCA
        ];
        array_splice($bt, 0, count($data), $data);
        return $bt;
    }

    public function PAT()
    {
        $bt = array_fill(0, 188, 0xff);
        $data = [
            0x47, 0x40, 0x00, 0x10,
            0x00,
            0x00, 0xB0, 0x0D, 0x00, 0x01, 0xC1, 0x00, 0x00, 0x00, 0x01,
            0xF0, 0x00, 0x2A, 0xB1, 0x04, 0xB2
        ];
        array_splice($bt, 0, count($data), $data);
        return $bt;
    }

    public function PMT()
    {
        $bt = array_fill(0, 188, 0xff);
        $data = [
            0x47, 0x50, 0x00, 0x10,
            0x00,
            0x02, 0xB0, 0x17, 0x00, 0x01, 0xC1, 0x00, 0x00, 0xE1, 0x00,
            0xF0, 0x00, 0x1B, 0xE1, 0x00, 0xF0, 0x00, 0x0F, 0xE1, 0x01,
            0xF0, 0x00, 0x2F, 0x44, 0xB9, 0x9B
        ];
        array_splice($bt, 0, count($data), $data);
        return $bt;
    }

    public function PES($mtype, $pts, $dts)
    {
        $header = array_fill(0, 9, 0);
        $header[0] = 0;
        $header[1] = 0;
        $header[2] = 1;
        $header[3] = $mtype;
        $header[6] = 0x80;
        if ($pts > 0) {
            if ($dts > 0) {
                $header[7] = 0xc0;
                $header[8] = 0x0a;
                $header = array_merge($header, $this->hexPts($pts));
                $header = array_merge($header, $this->hexDts($dts));
            } else {
                $header[7] = 0x80;
                $header[8] = 0x05;
                $header = array_merge($header, $this->hexPts($pts));
            }
        }
        return $header;
    }


}