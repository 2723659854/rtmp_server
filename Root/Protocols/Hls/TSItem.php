<?php

namespace Root\Protocols\Hls;


class TSItem {
    public $Name;
    public $SeqNum;
    public $Duration;
    public $Data;

    public function __construct($Name, $Duration, $SeqNum, $Data) {
        $this->Name = $Name;
        $this->SeqNum = $SeqNum;
        $this->Duration = $Duration;
        $this->Data = $Data;
    }
}

function NewTSItem($Name, $Duration, $SeqNum, $Data) {
    return new TSItem($Name, $Duration, $SeqNum, $Data);
}
?>