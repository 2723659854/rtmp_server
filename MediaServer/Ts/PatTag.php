<?php

namespace MediaServer\Ts;

/**
 * Class PatTag
 * @package MediaServer\Ts
 *
 * @purpose PAT数据包结构
 */
class PatTag
{
    /**
     * Table ID：8位，固定为 0x00 表示是 PAT 表。
     * @var int
     */
    public $tableId = 0x00;

    /**
     * Section Syntax Indicator：1位，固定为 1，表示后续数据的结构符合私有表的语法。
     * @var int
     */
    public $sectionSyntaxIndicator = 1;

    /**
     * Zero：1位，固定为 0。
     * @var int
     */
    public $zero = 0;

    /**
     * Reserved：2位，保留位，固定为 0x03。
     * @var int
     */
    public $reserved = 0x03;

    /**
     * Section Length：12位，表示整个 PAT 包的长度，包括这个字节后面的所有数据，最大值为 1021（0x3FD）字节。
     * 实际长度为字节值减去9。如果多个字节，表示值连续
     * @var int
     */
    public $sectionLength = 0;

    /**
     * Transport Stream ID：16位，标识当前传输流的ID。
     * @var int
     */
    public $transportStreamId = 0;

    /**
     * Version Number：5位，版本号，标识 PAT 表的版本。
     * @var int
     */
    public $versionNumber = 0;

    /**
     * Current Next Indicator：1位，指示 PAT 表是否是当前可用的表。
     * @var int
     */
    public $currentNextIndicator = 1;

    /**
     * Section Number：8位，表示当前 PAT 包的编号。
     * @var int
     */
    public $sectionNumber = 0;

    /**
     * Last Section Number：8位，表示 PAT 表的最后一个包的编号。
     * @var int
     */
    public $lastSectionNumber = 0;

    /**
     * Programs数组，存储PAT表中的节目信息。
     * 每个节目由program_number和program_map_PID组成。
     * @var array
     */
    public $programs = [];

    /**
     * 添加一个节目到PAT表中。
     * @param int $programNumber 节目号。与关联PMT中的表ID扩展相关。为NIT分组标识符保留值0。
     * @param int $programMapPid 节目对应的PMT PID。包含关联PMT的数据包标识符。
     */
    public function addProgram($programNumber, $programMapPid)
    {
        $this->programs[] = [
            'programNumber' => $programNumber,
            'programMapPid' => $programMapPid
        ];
    }

    /**
     * 获取整个PAT表的字节数组。
     * @return array
     */
    public function getBytes()
    {
        $bytes = [];

        // PAT 包头部分
        $bytes[] = $this->tableId;
        $bytes[] = ($this->sectionSyntaxIndicator << 7) | ($this->zero << 6) | ($this->reserved << 4) | (($this->sectionLength >> 8) & 0x0F);
        $bytes[] = $this->sectionLength & 0xFF;
        $bytes[] = ($this->transportStreamId >> 8) & 0xFF;
        $bytes[] = $this->transportStreamId & 0xFF;
        $bytes[] = ($this->reserved << 6) | (($this->versionNumber & 0x1F) << 1) | ($this->currentNextIndicator);
        $bytes[] = $this->sectionNumber;
        $bytes[] = $this->lastSectionNumber;

        // PAT 表中的节目信息
        foreach ($this->programs as $program) {
            $bytes[] = ($program['programNumber'] >> 8) & 0xFF;
            $bytes[] = $program['programNumber'] & 0xFF;
            $bytes[] = 0xE0 | (($program['programMapPid'] >> 8) & 0x1F);
            $bytes[] = $program['programMapPid'] & 0xFF;
        }

        return $bytes;
    }

    /**
     * 计算并设置 PAT 包的长度字段。
     */
    public function calculateSectionLength()
    {
        // PAT 包头部分固定占用的字节数
        $headerLength = 9;

        // 计算节目信息部分占用的字节数
        $programInfoLength = count($this->programs) * 4;

        // 计算整个 PAT 包的长度
        $this->sectionLength = $headerLength + $programInfoLength - 3; // 减去 3 是因为 section_length 本身不算在内
    }
}
