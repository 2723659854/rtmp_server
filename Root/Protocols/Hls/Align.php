<?php

namespace Root\Protocols\Hls;

const SYNC_MS = 2;

class Align {
    public $frameNum;
    public $frameBase;

    public function align(&$dts, $inc) {
        $aFrameDts = $dts;
        $estPts = $this->frameBase + $this->frameNum * (int)$inc;
        $dPts = 0;
        if ($estPts >= $aFrameDts) {
            $dPts = $estPts - $aFrameDts;
        } else {
            $dPts = $aFrameDts - $estPts;
        }

        if ($dPts <= SYNC_MS * 1) { // 这里假设 h264_default_hz 为 1
            $this->frameNum++;
            $dts = $estPts;
            return;
        }
        $this->frameNum = 1;
        $this->frameBase = $aFrameDts;
    }
}
?>