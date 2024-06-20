<?php

namespace MediaServer\Ts;

/**
 * Class PmtTag
 * @package MediaServer\Ts
 *
 * @purpose PMT数据包结构
 */
class PmtTag
{
    /**
     * Table ID：8位，固定为 0x02 表示是 PMT 表。
     * @var int
     */
    public $tableId = 0x02;

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
     * Section Length：12位，表示整个 PMT 包的长度，包括这个字节后面的所有数据。
     * @var int
     */
    public $sectionLength = 0;

    /**
     * Program Number：16位，标识当前节目的编号。
     * @var int
     */
    public $programNumber = 0;

    /**
     * Version Number：5位，版本号，标识 PMT 表的版本。
     * @var int
     */
    public $versionNumber = 0;

    /**
     * Current Next Indicator：1位，指示 PMT 表是否是当前可用的表。
     * @var int
     */
    public $currentNextIndicator = 1;

    /**
     * Section Number：8位，表示当前 PMT 包的编号。
     * @var int
     */
    public $sectionNumber = 0;

    /**
     * Last Section Number：8位，表示 PMT 表的最后一个包的编号。
     * @var int
     */
    public $lastSectionNumber = 0;

    /**
     * PCR PID：16位，包含有关节目时钟参考（PCR）的信息。
     * @var int
     */
    public $pcrPid = 0;

    /**
     * Stream信息数组，存储PMT表中的流信息。
     * 每个流包含streamType、elementaryPid和可选的esDescriptors。
     * @var array
     */
    public $streams = [];

    /**
     * 添加一个流到PMT表中。
     * @param int $streamType 流类型。
     * @param int $elementaryPid 流的PID。
     * @param array $esDescriptors 可选的ES描述符。
     */
    public function addStream($streamType, $elementaryPid, $esDescriptors = [])
    {
        $this->streams[] = [
            'streamType' => $streamType,
            'elementaryPid' => $elementaryPid,
            'esDescriptors' => $esDescriptors
        ];
    }

    /**
     * 获取整个PMT表的字节数组。
     * @return array
     */
    public function getBytes()
    {
        $bytes = [];

        // PMT 包头部分
        $bytes[] = $this->tableId;
        $bytes[] = ($this->sectionSyntaxIndicator << 7) | ($this->zero << 6) | ($this->reserved << 4) | (($this->sectionLength >> 8) & 0x0F);
        $bytes[] = $this->sectionLength & 0xFF;
        $bytes[] = ($this->programNumber >> 8) & 0xFF;
        $bytes[] = $this->programNumber & 0xFF;
        $bytes[] = ($this->reserved << 6) | (($this->versionNumber & 0x1F) << 1) | ($this->currentNextIndicator);
        $bytes[] = $this->sectionNumber;
        $bytes[] = $this->lastSectionNumber;
        $bytes[] = ($this->pcrPid >> 8) & 0xFF;
        $bytes[] = $this->pcrPid & 0xFF;
        $bytes[] = 0xF0 | (($this->sectionLength >> 4) & 0x0F);
        $bytes[] = (($this->sectionLength & 0x0F) << 4) | 0x0F;

        // PMT 表中的流信息
        foreach ($this->streams as $stream) {
            $bytes[] = $stream['streamType'];
            $bytes[] = 0xE0 | (($stream['elementaryPid'] >> 8) & 0x1F);
            $bytes[] = $stream['elementaryPid'] & 0xFF;

            // ES描述符（如果有）
            if (!empty($stream['esDescriptors'])) {
                foreach ($stream['esDescriptors'] as $descriptor) {
                    $bytes[] = $descriptor['tag'];
                    $bytes[] = $descriptor['length'];
                    $bytes = array_merge($bytes, $descriptor['data']);
                }
            }

            $bytes[] = 0x00; // ES信息长度为0，表示无ES信息
        }

        return $bytes;
    }

    /**
     * 计算并设置 PMT 包的长度字段。
     */
    public function calculateSectionLength()
    {
        // PMT 包头部分固定占用的字节数
        $headerLength = 12;

        // 计算流信息部分占用的字节数
        $streamInfoLength = 0;
        foreach ($this->streams as $stream) {
            $streamInfoLength += 5; // 流类型、保留位和PID
            if (!empty($stream['esDescriptors'])) {
                foreach ($stream['esDescriptors'] as $descriptor) {
                    $streamInfoLength += 2 + count($descriptor['data']); // 描述符标识和长度 + 描述符数据
                }
            }
            $streamInfoLength += 1; // ES信息长度
        }

        // 计算整个 PMT 包的长度
        $this->sectionLength = $headerLength + $streamInfoLength - 3; // 减去 3 是因为 section_length 本身不算在内
    }
}
