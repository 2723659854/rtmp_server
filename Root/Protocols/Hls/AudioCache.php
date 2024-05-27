<?php

namespace Root\Protocols\Hls;

const CACHE_MAX_FRAMES = 6;
const AUDIO_CACHE_LEN = 10 * 1024;

class AudioCache {
    public $soundFormat;
    public $num;
    public $offset;
    public $pts;
    public $buf;

    public function __construct() {
        $this->buf = new \SplFixedArray(AUDIO_CACHE_LEN);
    }

    public function cache($src, $pts) {
        if ($this->num == 0) {
            $this->offset = 0;
            $this->pts = $pts;
            $this->buf = [];
        }
        $this->buf = array_merge($this->buf, $src);
        $this->offset += count($src);
        $this->num++;

        return false;
    }

    public function getFrame() {
        $this->num = 0;
        return [$this->offset, $this->pts, $this->buf];
    }

    public function cacheNum() {
        return $this->num;
    }
}
?>