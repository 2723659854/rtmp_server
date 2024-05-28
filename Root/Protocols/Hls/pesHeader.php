<?php

namespace Root\Protocols\Hls;

// PES 头结构体
class pesHeader {
    public $len;
    public $data;

    // 创建 PES 头实例
    public function __construct() {
        $this->len = 0;
        $this->data = str_repeat("\x00", tsPacketLen);
    }

    // PES 包处理函数
    public function packet($p, $pts, $dts) {
        // PES 头
        $i = 0;
        $this->data[$i] = 0x00;
        $i++;
        $this->data[$i] = 0x00;
        $i++;
        $this->data[$i] = 0x01;
        $i++;

        $sid = audioSID;
        if ($p->isVideo()) {
            $sid = videoSID;
        }
        $this->data[$i] = $sid;
        $i++;

        $flag = 0x80;
        $ptslen = 5;
        $dtslen = $ptslen;
        $headerSize = $ptslen;
        if ($p->isVideo() && $pts!= $dts) {
            $flag |= 0x40;
            $headerSize += 5; // add dts
        }
        $size = strlen($p->getData()) + $headerSize + 3;
        if ($size > 0xffff) {
            $size = 0;
        }
        $this->data[$i] = $size >> 8;
        $i++;
        $this->data[$i] = $size & 0xff;
        $i++;

        $this->data[$i] = 0x80;
        $i++;
        $this->data[$i] = $flag;
        $i++;
        $this->data[$i] = $headerSize;
        $i++;

        $this->writeTs($this->data, $i, $flag >> 6, $pts);
        $i += $ptslen;
        if ($p->isVideo() && $pts!= $dts) {
            $this->writeTs($this->data, $i, 1, $dts);
            $i += $dtslen;
        }

        $this->len = $i;

        return null;
    }

    // 写入 TS
    public function writeTs(&$src, $i, $fb, $ts) {
        $val = 0;
        if ($ts > 0x1ffffffff) {
            $ts -= 0x1ffffffff;
        }
        $val = ($fb << 4) | (($ts >> 30) & 0x07) << 1 | 1;
        $src[$i] = $val & 0xff;
        $i++;

        $val = (($ts >> 15) & 0x7fff) << 1 | 1;
        $src[$i] = $val >> 8;
        $i++;
        $src[$i] = $val & 0xff;
        $i++;

        $val = ($ts & 0x7fff) << 1 | 1;
        $src[$i] = $val >> 8;
        $i++;
        $src[$i] = $val & 0xff;
    }
}