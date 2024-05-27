<?php

namespace MediaServer;

// 假设这个类用于封装ES、PES和TS包
class Packetizer {
    public static function wrapES($data) {
        // 直接封装为ES包
        return $data;
    }

    public static function wrapPES($data, $timestamp) {
        // 添加PES头部信息
        $pesHeader = pack('C2n2', 0x00, 0x00, 0x01, strlen($data) + 8);
        $pesHeader .= pack('C2nC', 0xbd, 0x80, 0x80, 0x05);
        $pesHeader .= pack('N', $timestamp);

        return $pesHeader . $data;
    }

    public static function wrapTS($data, $pid) {
        // 添加TS头部信息
        $tsHeader = pack('N', 0x47 << 24 | ($pid & 0x1fff) << 8 | 0x10);
        $tsPacket = $tsHeader . $data;

        return $tsPacket;
    }

    // 假设这个函数用于从AudioFrame对象中提取AAC数据
    public static function extractAACData($audioFrame) {
        //var_dump($audioFrame);
        return $audioFrame->_data;
    }

    // 假设这个函数用于获取时间戳
    public static function getTimestamp($audioFrame) {
        return $audioFrame->timestamp;
    }

    // 假设这个函数用于获取PID
    public static function getPID() {
        return 256; // 假设PID为256
    }

}