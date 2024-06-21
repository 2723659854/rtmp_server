<?php
namespace MediaServer\mpegts;

use MediaServer\MediaReader\MediaFrame;

class Ts
{

    public function Adapterts($topic, $ch) {
        global $cache;


        $filename = 'runtime/' . rawurlencode($topic) . '.ts';

        $t = new TsPack();
        $t->NewTs($filename);

        $tslen = 0; // single tsfile sum(dts)

        foreach ($ch as $pk) {
            // gen new ts file (dts 5*second)
            if ($tslen > 5000) {
                $extinf = new ExtInf($tslen,$filename);

                // file add the hls cache
                if (isset($cache[$topic])) {
                    $cache[$topic][] = $extinf;
                } else {
                    $cache[$topic] = [$extinf];
                }

                $filename = 'runtime/' . rawurlencode($topic) . $t->DTS . '.ts';
                $t->NewTs($filename);
                $tslen = 0;
            }

            $t->FlvTag($pk->MessageTypeID, $pk->Timestamp, $pk->ExtendTimestamp, $pk->PayLoad);

            $tslen += $pk->Timestamp;
        }
    }


}