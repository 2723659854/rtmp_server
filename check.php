<?php
/**
 * 说明：本文件用来测试生成的ts切片文件是否符合规范，会显示生成的ts文件个数。
 * 正确的ts文件，只有一个pat包，一个pmt包，否则生成的ts文件是错误的。
 * 运行命令：php check.php
 *
 * 这个文件只负责检测pid是否正确，如果需要检测ts文件是否符合mpeg-2标准，请使用ffmpeg检测
 * ffmpeg -i input.ts -c copy output.mp4
 *
 */
require_once __DIR__.'/vendor/autoload.php';
/** 这个是ffmpeg生成ts文件，用来对比 */
//$file = __DIR__.'/a/b1.ts';
/** 这是本项目生成的ts文件 */
/** ts文件请自己使用命令生成，就不上传了，请根据实际情况设置切片文件 */
$file = __DIR__.'/a/b/segment0.ts';
/** 打开ts文件 */
$inFileHandle = fopen($file, 'rb');
if ($inFileHandle === false) {
    throw new Exception();
}

/** 解析ts文件 */
$parser = new \PhpBg\MpegTs\Parser();
$parser->passthroughAllPids = true;
/** 初始化结果集 */
$tsStats = [];
$tsCounter = 0;
/** 绑定错误回调函数 */
$parser->on('error', function ($e) {
    echo "TS parser error: {$e->getMessage()}\n";
});
/** 设置检测到ts回调函数 */
$parser->on('ts', function ($pid, $data) use (&$tsStats, &$tsCounter) {
    // Count TS packets
    $tsStats[$pid] = isset($tsStats[$pid]) ? $tsStats[$pid] + 1 : 1;
    $tsCounter++;
});

/** 初始化打包器 */
$packetizer = new \PhpBg\MpegTs\Packetizer();
/** 设置错误回调 */
$packetizer->on('error', function ($e) {
    echo "TS packetizer error: {$e->getMessage()}\n";
});
/** 设置接受数据回调 */
$packetizer->on('data', function ($data) use ($parser) {
    /** 调用解析器，注入到解析器 */
    $parser->write($data);
});

/** 读取ts文件 ，写入到打包器中 */
while (!feof($inFileHandle)) {
    /** 读取数据 ts的长度是188，这里一次读10个包的长度 */
    $data = fread($inFileHandle, 1880);
    if (false === $data) {
        throw new Exception("Unable to read");
    }
    /** 将数据写入到打包器 */
    $packetizer->write($data);
}
/** 关闭ts文件 */
fclose($inFileHandle);

/** 输出结果 */
echo "Done\r\n";
echo "{$tsCounter} MPEG TS packets read\r\n";
ksort($tsStats);
foreach ($tsStats as $pid => $count) {
    echo sprintf("PID: %04d (0x%04x)\t%d packets\r\n", $pid, $pid, $count);
}