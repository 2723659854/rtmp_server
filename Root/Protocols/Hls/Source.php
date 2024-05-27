<?php

namespace Root\Protocols\Hls;


use Aws\Av\Container\Flv\Demuxer;
use Aws\Av\Container\Flv\Entity\AudioTagHeader;
use Aws\Av\Container\Flv\Entity\FlvHeader;
use Aws\Av\Container\Flv\Entity\VideoTagHeader;
use Aws\Av\Container\Ts\Entity\EntityFactory;
use Aws\Av\Container\Ts\Entity\EntityHeader;
use Aws\Av\Container\Ts\Entity\EntityPayload;
use Aws\Av\Container\Ts\Entity\PAT;
use Aws\Av\Container\Ts\Entity\PMT;
use Aws\Av\Container\Ts\Entity\TS;
use Aws\Av\Container\Ts\Muxer;
use Aws\Av\Info;
use Aws\Av\Packet;
use Aws\Av\Parser\CodecParser;
use Aws\Log\Entity\LogHeader;
use Aws\Log\Entity\LogMessage;
use Aws\Log\Log;
use Aws\Rwbaser;
use Hls\Configure;
use const Hls\AAC_SEQHDR;
use const Hls\SOUND_AAC;
use const Hls\VIDEO_H264;

class Source {
    protected $rwbaser;
    protected $seq;
    protected $info;
    protected $bwriter;
    protected $btswriter;
    protected $demuxer;
    protected $muxer;
    protected $pts;
    protected $dts;
    protected $stat;
    protected $align;
    protected $cache;
    protected $tsCache;
    protected $tsparser;
    protected $closed;
    protected $packetQueue;

    public function __construct(Info $info) {
        $this->info = $info;
        $this->align = new Align();
        $this->stat = new Status();
        $this->rwbaser = new Rwbaser(10);
        $this->cache = new AudioCache();
        $this->demuxer = new Demuxer();
        $this->muxer = new Muxer();
        $this->tsCache = new TSCacheItem($info->Key);
        $this->tsparser = new CodecParser();
        $this->bwriter = new \SplFixedArray(100 * 1024);
        $this->packetQueue = new \SplQueue();
    }

    public function getCacheInc() {
        return $this->tsCache;
    }

    public function dropPacket($pktQue, Info $info) {
        Log::warning(sprintf('[%v] packet queue max!!!', $info));
        for ($i = 0; $i < 512 - 84; $i++) {
            $tmpPkt = $pktQue->dequeue();
            // try to don't drop audio
            if ($tmpPkt->isAudio() && $pktQue->count() > 512 - 2) {
                $pktQue->enqueue($tmpPkt);
            }

            if ($tmpPkt->isVideo() && $pktQue->count() > 512 - 10) {
                $pktQue->enqueue($tmpPkt);
            }
        }
        Log::debug(sprintf('packet queue len: %d', $pktQue->count()));
    }

    public function write(Packet $p) {
        $err = null;
        if ($this->closed) {
            $err = new \Exception('hls source closed');
            return $err;
        }
        $this->setPreTime();
        if ($p->isMetadata()) {
            return;
        }
        $this->demuxer->demux($p);
        $compositionTime = $p->getTimeStamp();
        $isSeq = $p->isVideo() && $p->getHeader() instanceof VideoTagHeader && $p->getHeader()->isKeyFrame() && $p->getHeader()->isSeq();
        if ($isSeq) {
            $this->parse($p);
        }
        $this->btswriter = new \SplFixedArray();
        $this->stat->update($p->isVideo(), $p->getTimeStamp());
        $this->calcPtsDts($p->isVideo(), $p->getTimeStamp(), $compositionTime);
        $this->tsMux($p);
        return $err;
    }

    public function sendPacket() {
        Log::debug(sprintf('[%v] hls sender start', $this->info));
        while (!$this->closed) {
            $p = $this->packetQueue->dequeue();
            if (!$p) {
                break;
            }
            if ($p->isMetadata()) {
                continue;
            }
            $err = $this->demuxer->demux($p);
            if ($err instanceof \Exception) {
                Log::warning($err);
                continue;
            }
            $compositionTime = $p->getTimeStamp();
            $isSeq = $p->isVideo() && $p->getHeader() instanceof VideoTagHeader && $p->getHeader()->isKeyFrame() && $p->getHeader()->isSeq();
            if (!$isSeq) {
                continue;
            }
            $err = $this->parse($p);
            if ($err instanceof \Exception) {
                Log::warning($err);
            }
            $this->btswriter = new \SplFixedArray();
            $this->stat->update($p->isVideo(), $p->getTimeStamp());
            $this->calcPtsDts($p->isVideo(), $p->getTimeStamp(), $compositionTime);
            $this->tsMux($p);
        }
        Log::debug(sprintf('[%v] hls sender stop', $this->info));
    }

    public function info() {
        return $this->info;
    }

    public function cleanup() {
        $this->packetQueue = null;
        $this->bwriter = null;
        $this->btswriter = null;
        $this->cache = null;
        $this->tsCache = null;
    }

    public function close($err) {
        Log::debug(sprintf('hls source closed: %v', $this->info));
        if (!$this->closed &&!Configure::$config['hls_keep_after_end']) {
            $this->cleanup();
        }
        $this->closed = true;
    }

    public function cut() {
        $newf = true;
        if (!$this->btswriter) {
            $this->btswriter = new \SplFixedArray();
        } else if ($this->btswriter && $this->stat->durationMs() >= 3000) {
            $this->flushAudio();

            $this->seq++;
            $filename = sprintf('/%s/%d.ts', $this->info->Key, time()->getTimestamp());
            $item = new TSItem($filename, $this->stat->durationMs(), $this->seq, $this->btswriter->getBytes());
            $this->tsCache->setItem($filename, $item);

            $this->btswriter = new \SplFixedArray();
            $this->stat->resetAndNew();
        } else {
            $newf = false;
        }
        if ($newf) {
            $this->btswriter->setSize(0);
            $this->btswriter->write($this->muxer->pat());
            $this->btswriter->write($this->muxer->pmt(SOUND_AAC, true));
        }
    }

    public function parse(Packet $p) {
        $compositionTime = 0;
        $vh = null;
        $ah = null;
        if ($p->isVideo()) {
            $vh = $p->getHeader();
            if ($vh->getCodecID()!= VIDEO_H264) {
                throw new \Exception('errNoSupportVideoCodec');
            }
            $compositionTime = $vh->getCompositionTime();
            if ($vh->isKeyFrame() && $vh->isSeq()) {
                return $compositionTime;
            }
        } else {
            $ah = $p->getHeader();
            if ($ah->getSoundFormat()!= SOUND_AAC) {
                throw new \Exception('errNoSupportAudioCodec');
            }
            if ($ah->getAACPacketType() == AAC_SEQHDR) {
                return $compositionTime;
            }
        }
        $this->bwriter->setSize(0);
        if ($p->isVideo() && $vh->isKeyFrame()) {
            $this->cut();
        }
        return $compositionTime;
    }

    public function calcPtsDts($isVideo, $ts, $compositionTs) {
        $this->dts = $ts * 90;
        if ($isVideo) {
            $this->pts = $this->dts + $compositionTs * 90;
        } else {
            $sampleRate = $this->tsparser->getSampleRate();
            $this->align->align($this->dts, $sampleRate);
            $this->pts = $this->dts;
        }
    }

    public function flushAudio() {
        return $this->muxAudio(1);
    }

    public function muxAudio($limit) {
        if ($this->cache->getNum() < $limit) {
            return;
        }
        $p = new Packet();
        list($offset, $pts, $buf) = $this->cache->getFrame();
        $p->setData($buf);
        $p->setTimeStamp($pts / 90);
        return $this->muxer->mux($p, $this->btswriter);
    }

    public function tsMux(Packet $p) {
        if ($p->isVideo()) {
            return $this->muxer->mux($p, $this->btswriter);
        } else {
            $this->cache->cache($p->getData(), $this->pts);
            return $this->muxAudio(6);
        }
    }
}
?>