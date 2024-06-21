<?php

namespace MediaServer\mpegts;

class ExtInf
{
    public int $Inf;

    public string $File;

    public function __construct($inf, $file) {
        $this->Inf = $inf;
        $this->File = $file;
    }
}