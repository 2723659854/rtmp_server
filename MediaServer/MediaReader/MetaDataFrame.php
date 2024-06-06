<?php


namespace MediaServer\MediaReader;


use MediaServer\Utils\BinaryStream;

/**
 * @purpose 元数据帧
 */
class MetaDataFrame extends BinaryStream implements MediaFrame
{
    public $FRAME_TYPE=self::META_FRAME;

    public $_buffer;
    public function __construct(string $data = "")
    {
        $this->_buffer = $data;
        parent::__construct($data);
    }

    public function __toString()
    {
        return $this->dump();
    }

}
