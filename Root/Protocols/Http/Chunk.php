<?php

namespace Root\Protocols\Http;


/**
 * Class Chunk
 */
class Chunk
{
    /**
     * Chunk buffer.
     *
     * @var string
     */
    protected $_buffer = null;

    /**
     * Chunk constructor.
     * @param string $buffer
     */
    public function __construct($buffer)
    {
        $this->_buffer = $buffer;
    }

    /**
     * __toString
     *
     * @return string
     */
    public function __toString()
    {
        return \dechex(\strlen($this->_buffer))."\r\n$this->_buffer\r\n";
    }
}