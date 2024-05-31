<?php
require_once __DIR__.'/vendor/autoload.php';

//$file = __DIR__.'/a/b1.ts';
$file = __DIR__.'/a/b/segment0.ts';
$inFileHandle = fopen($file, 'rb');
if ($inFileHandle === false) {
    throw new Exception();
}

// Prepare parser
$parser = new \PhpBg\MpegTs\Parser();
$parser->passthroughAllPids = true;

$tsStats = [];
$tsCounter = 0;
$parser->on('error', function ($e) {
    echo "TS parser error: {$e->getMessage()}\n";
});
$parser->on('ts', function ($pid, $data) use (&$tsStats, &$tsCounter) {
    // Count TS packets
    $tsStats[$pid] = isset($tsStats[$pid]) ? $tsStats[$pid] + 1 : 1;
    $tsCounter++;
});

// Prepare packetizer
$packetizer = new \PhpBg\MpegTs\Packetizer();
$packetizer->on('error', function ($e) {
    echo "TS packetizer error: {$e->getMessage()}\n";
});
$packetizer->on('data', function ($data) use ($parser) {
    $parser->write($data);
});

// Read file and write packets
while (!feof($inFileHandle)) {
    $data = fread($inFileHandle, 1880);
    if (false === $data) {
        throw new Exception("Unable to read");
    }
    $packetizer->write($data);
}

fclose($inFileHandle);

echo "Done\r\n";
echo "{$tsCounter} MPEG TS packets read\r\n";
ksort($tsStats);
foreach ($tsStats as $pid => $count) {
    echo sprintf("PID: %04d (0x%04x)\t%d packets\r\n", $pid, $pid, $count);
}