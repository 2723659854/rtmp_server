<?php


class HlsDemo
{
    private static $timebase = 90000; // MPEG-TS time base is 90 kHz

    public function generateTsFile(string $filename, array $videoFrames)
    {
        $file = fopen($filename, 'wb');

        $currentTimestamp = 0;
        $frameDuration = self::$timebase / 30; // Assuming 30 fps

        foreach ($videoFrames as $frame) {
            $pts = $dts = $currentTimestamp;
            $pcr = $currentTimestamp * self::$timebase;

            $pesPacket = $this->createPes(0xE0, $frame, $pts, $dts);
            $tsPacket = $this->createTsPacket(0x100, $pesPacket, $pcr);

            fwrite($file, $tsPacket);

            $currentTimestamp += $frameDuration;
        }

        fclose($file);
    }

    private function createPes(int $stream_id, string $payload, int $pts, int $dts): string
    {
        $pesHeader = "\x00\x00\x01" . chr($stream_id);
        $pesPacketLength = strlen($payload) + 13; // PES header length (14 bytes) - 1
        $pesHeader .= chr(($pesPacketLength >> 8) & 0xFF);
        $pesHeader .= chr($pesPacketLength & 0xFF);
        $pesHeader .= "\x80\x80\x05"; // 0x80: no scrambling, no priority, no alignment, PES header present
        $pesHeader .= chr((($pts >> 29) & 0x0E) | 0x21); // PTS[32..30] and marker bits
        $pesHeader .= chr(($pts >> 22) & 0xFF);         // PTS[29..22]
        $pesHeader .= chr((($pts >> 14) & 0xFE) | 0x01); // PTS[21..15] and marker bits
        $pesHeader .= chr(($pts >> 7) & 0xFF);          // PTS[14..7]
        $pesHeader .= chr((($pts << 1) & 0xFE) | 0x01); // PTS[6..0] and marker bits
        $pesHeader .= chr((($dts >> 29) & 0x0E) | 0x11); // DTS[32..30] and marker bits
        $pesHeader .= chr(($dts >> 22) & 0xFF);         // DTS[29..22]
        $pesHeader .= chr((($dts >> 14) & 0xFE) | 0x01); // DTS[21..15] and marker bits
        $pesHeader .= chr(($dts >> 7) & 0xFF);          // DTS[14..7]
        $pesHeader .= chr((($dts << 1) & 0xFE) | 0x01); // DTS[6..0] and marker bits

        return $pesHeader . $payload;
    }

    private function createTsPacket(int $pid, string $payload, int $pcr = null): string
    {
        $packet = "\x47"; // Sync byte
        $packet .= chr(($pid >> 8) & 0x1F); // PID high bits and other flags
        $packet .= chr($pid & 0xFF); // PID low bits
        $packet .= "\x10"; // No adaptation field, payload only

        if ($pcr !== null) {
            $adaptationFieldLength = 7;
            $adaptationFieldHeader = "\x50"; // Adaptation field control
            $adaptationFieldHeader .= chr($adaptationFieldLength); // Adaptation field length
            $adaptationFieldHeader .= chr(($pcr >> 25) & 0xFF); // PCR[32..25]
            $adaptationFieldHeader .= chr(($pcr >> 17) & 0xFF); // PCR[24..17]
            $adaptationFieldHeader .= chr(($pcr >> 9) & 0xFF);  // PCR[16..9]
            $adaptationFieldHeader .= chr(($pcr >> 1) & 0xFF);  // PCR[8..1]
            $adaptationFieldHeader .= chr((($pcr << 7) & 0x80) | 0x7E); // PCR[0] and reserved bits
            $packet .= $adaptationFieldHeader;
        }

        $packet .= $payload;
        $packet = str_pad($packet, 188, "\xFF"); // Pad the packet to 188 bytes

        return $packet;
    }
}

// 示例使用
$videoFrames = [
    // 这里应该是你的H.264帧数据
    file_get_contents('frame1.h264'),
    file_get_contents('frame2.h264'),
    // 依次类推
];

$hlsDemo = new HlsDemo();
$hlsDemo->generateTsFile('output.ts', $videoFrames);
