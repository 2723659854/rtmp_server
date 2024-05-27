<?php

namespace Root\Protocols\Hls;

const MAX_TS_CACHE_NUM = 3;

class TSCacheItem {
    public $id;
    public $num;
    public $ll;
    public $lm;

    public function __construct($id) {
        $this->id = $id;
        $this->ll = new \SplDoublyLinkedList();
        $this->num = MAX_TS_CACHE_NUM;
        $this->lm = [];
    }

    public function id() {
        return $this->id;
    }

    // 这里只是模拟，实际可能需要更复杂的处理来模拟数据竞争修复
    public function genM3U8Playlist() {
        $seq = 0;
        $getSeq = false;
        $maxDuration = 0;
        $m3u8body = '';
        foreach ($this->ll as $e) {
            $key = $e;
            if (isset($this->lm[$key])) {
                $v = $this->lm[$key];
                if ($v['duration'] > $maxDuration) {
                    $maxDuration = $v['duration'];
                }
                if (!$getSeq) {
                    $getSeq = true;
                    $seq = $v['seqNum'];
                }
                $m3u8body.= "#EXTINF:". ($v['duration'] / 1000). ",\n". $v['name']. "\n";
            }
        }
        $w = '';
        $w.= "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-ALLOW-CACHE:NO\n#EXT-X-TARGETDURATION:". ($maxDuration / 1000 + 1). "\n#EXT-X-MEDIA-SEQUENCE:$seq\n\n";
        $w.= $m3u8body;
        return $w;
    }

    public function setItem($key, $item) {
        if ($this->ll->count() == $this->num) {
            $e = $this->ll->shift();
            unset($this->lm[$e]);
        }
        $this->lm[$key] = $item;
        $this->ll->push($key);
    }

    public function getItem($key) {
        if (isset($this->lm[$key])) {
            return $this->lm[$key];
        }
        return false;
    }
}
?>