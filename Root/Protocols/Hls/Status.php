<?php

namespace Root\Protocols\Hls;


class Status {
    public $hasVideo;
    public $seqId;
    public $createdAt;
    public $segBeginAt;
    public $hasSetFirstTs;
    public $firstTimestamp;
    public $lastTimestamp;

    public function __construct() {
        $this->seqId = 0;
        $this->hasSetFirstTs = false;
        $this->segBeginAt = time();
    }

    public function update($isVideo, $timestamp) {
        if ($isVideo) {
            $this->hasVideo = true;
        }
        if (!$this->hasSetFirstTs) {
            $this->hasSetFirstTs = true;
            $this->firstTimestamp = (int)$timestamp;
        }
        $this->lastTimestamp = (int)$timestamp;
    }

    public function resetAndNew() {
        $this->seqId++;
        $this->hasVideo = false;
        $this->createdAt = time();
        $this->hasSetFirstTs = false;
    }

    public function durationMs() {
        return $this->lastTimestamp - $this->firstTimestamp;
    }
}
?>