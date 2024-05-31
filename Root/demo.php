<?php
/*
 * 一个ts文件，分成三层，ts层，pes层，es层，而且ts包又分为pat,pmt,nit，音频数据包，视频数据包，控制元素，而且是根据设置将单位时间内的流媒体数据封装到ts中，你写的什么东西
 * */
// 生成 PAT 表
function generatePAT() {
    // 0x0000: Program Association Table (PAT)
    $pat = pack('C*',
        0x47, 0x40, 0x00, 0x10, 0x00, 0x00, 0xB0, 0x0D, 0x00, 0x01, 0xC1, 0x00, 0x00, 0x00, 0x01, 0xE0,
        0x00, 0x00, 0xF0, 0x01, 0x00, 0x00, 0xF0, 0x01, 0x4F
    );
    return $pat;
}

// 生成 PMT 表
function generatePMT() {
    // 0x0100: Program Map Table (PMT)
    $pmt = pack('C*',
        0x47, 0x41, 0x00, 0x10, 0x00, 0x02, 0xB0, 0x10, 0x00, 0x01, 0xC1, 0x00, 0x00, 0xE0, 0x00, 0xF0,
        0x10, 0x00, 0x02, 0xE1, 0x00, 0xF0, 0x10, 0x00, 0x02, 0xC0, 0x00, 0xF0, 0x40
    );
    return $pmt;
}

// 生成 NIT 表
function generateNIT() {
    // 0x0010: Network Information Table (NIT)
    $nit = pack('C*',
        0x47, 0x42, 0x00, 0x10, 0x00, 0x03, 0xB0, 0x16, 0x00, 0x01, 0xC1, 0x00, 0x00, 0xF0, 0x01, 0x00,
        0x00, 0x01, 0xF0, 0x02, 0x00, 0x00, 0x01, 0x0B, 0x00, 0x02, 0x00, 0x02, 0x42
    );
    return $nit;
}

// 生成音频数据包
function generateAudioPacket($data) {
    // 这里只是一个示例，实际需要根据音频数据的格式进行封装
    return pack('C*', ...$data);
}

// 生成视频数据包
function generateVideoPacket($data) {
    // 这里只是一个示例，实际需要根据视频数据的格式进行封装
    return pack('C*', ...$data);
}

// 生成 TS 文件
function generateTSFile() {
    // 打开 TS 文件
    $file = fopen('output.ts', 'wb');
    if (!$file) {
        echo "Error: Failed to open output file.";
        return;
    }

    // 写入 PAT 表
    $pat = generatePAT();
    fwrite($file, $pat);

    // 写入 PMT 表
    $pmt = generatePMT();
    fwrite($file, $pmt);

    // 写入 NIT 表
    $nit = generateNIT();
    fwrite($file, $nit);

    // 写入音频数据包（示例）
    $audioData = [0x01, 0x02, 0x03]; // 示例音频数据
    $audioPacket = generateAudioPacket($audioData);
    fwrite($file, $audioPacket);

    // 写入视频数据包（示例）
    $videoData = [0x04, 0x05, 0x06]; // 示例视频数据
    $videoPacket = generateVideoPacket($videoData);
    fwrite($file, $videoPacket);

    // 关闭文件
    fclose($file);

    echo "TS file generated successfully.";
}

// 生成 TS 文件
generateTSFile();

?>

ts 文件为传输流文件，视频编码主要格式为 H264/MPEG4，音频为 AAC/MP3。
ts 文件分为三层：
ts 层：Transport Stream，是在 pes 层的基础上加入数据流的识别和传输必须的信息。
pes 层： Packet Elemental Stream，是在音视频数据上加了时间戳等对数据帧的说明信息。
es 层：Elementary Stream，即音视频数据。
PAT（Program Association Table）节目关联表：主要的作用就是指明了 PMT 表的 PID 值。
PMT（Program Map Table）节目映射表：主要的作用就是指明了音视频流的 PID 值。
刚开始的TS包是PAT（Program Association Table）：节目关联表。
再跟的TS包是PMT（Program Map Table）：节目映射表。
然后再跟视频、音频的TS包。
ts 包大小固定为 188 字节，ts 层分为三个部分：ts header、adaptation field、payload。
ts header ：固定 4 个字节。
adaptation field ： 可能存在也可能不存在，主要作用是给不足 188 字节的数据做填充。
payload ： pes 数据。
ts 层的内容是通过 PID 值来标识的，主要内容包括：PAT 表、PMT 表、音频流、视频流。
解析 ts 流要先找到 PAT 表，只要找到 PAT 就可以找到 PMT，然后就可以找到音视频流了。
PAT 表的和 PMT 表需要定期插入 ts 流，因为用户随时可能加入 ts 流，这个间隔比较小，
通常每隔几个视频帧就要加入 PAT和 PMT。
PAT 和 PMT 表是必须的，还可以加入其它表如 SDT（业务描述表）等，不过 hls 流只要有
PAT 和 PMT 就可以播放了。
自适应区的长度要包含传输错误指示符标识的一个字节。pcr 是节目时钟参考，pcr、dts、pts 都是对同
一个系统时钟的采样值，pcr 是递增的，因此可以将其设置为 dts 值，音频数据不需要 pcr。如果没有字
段，ipad 是可以播放的，但 vlc 无法播放。打包 ts 流时 PAT 和 PMT 表是没有 adaptation field 的，
不够的长度直接补 0xff 即可。视频流和音频流都需要加 adaptation field，通常加在一个帧的第一个 ts
包和最后一个 ts 包中，中间的 ts 包不加。

adaptation field 详解：flag 标志位：0x10就表示有PCR，下面视频流截图也是这个情况，
0x50是random_access_indicator标志位和PCR_flag标志位都有。
1、视频帧：
I帧：第一个TS包和最后一个TS包有adaptation field，根据ts header 最后一个字节判断。
P帧：最后一个TS包有adaptation field，根据ts header 最后一个字节判断。
2、音频帧：
最后一个TS包有adaptation field，根据ts header 最后一个字节判断。
PAT（Program Association Table）节目关联表：主要的作用就是指明了 PMT 表的 PID 值。

PMT（Program Map Table）节目映射表：主要的作用就是指明了音视频流的 PID 值。
pes (Packet Elemental Stream)层是在每一个视频/音频帧上加上了时间戳等信息，pes 包内容项很多。
根据0x41字节中获取payload_unit_start_indicator 为1，表示这个TS包是有pes包头的（00 00 01 E0 01 FB 80 80 05 21 00 7F E9 B9 ）
然后后面才是实际的h264数据，由于后面还有两个payload_unit_start_indicator 为0的，表示这3个TS包合起来才是完整的一帧h264数据。
而且payload_unit_start_indicator 为0的TS包，是没有pes包头了，除去4个字节的ts包头就是h264数据了。
es(Elementary Stream) 层就是音视频数据。

hls协议是流媒体协议。数据是源源不断的输入的。不是将一个音频数据包打包成ts文件，也不是将一个视频数据包打包成ts文件。而是每隔三秒将接收到的音视频
数据包打包成ts文件。一个ts文件，分成三层，ts层，pes层，es层，而且ts包又分为pat,pmt,nit，音频数据包，视频数据包，控制元素，而且是根据设置将单位时间内的流媒体数据封装到ts中。

那么，请编写一个hls类，提供一个make($data)方法，这个$data就是一个AudioFrame数据包或者VideoFrame数据包。
其他程序会持续调用这个make方法，每次传入一个音频数据包或者一个视频数据包。hls类每隔3秒将接收到的所有音视频数据包封装到ts文件，并生成索引文件m3u8。
请写出完整的所有代码。完善所有的代码，不要存在未实现逻辑的方法。以下是AudioFrame和VideoFrame类:
