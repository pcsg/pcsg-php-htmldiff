<?php

namespace PCSG\PhpHtmlDiff;

class MatchData implements \Countable
{
    public $StartInOld;
    public $StartInNew;
    public $Size;

    public function __construct($startInOld, $startInNew, $size)
    {
        $this->StartInOld = $startInOld;
        $this->StartInNew = $startInNew;
        $this->Size       = $size;
    }

    public function endInOld()
    {
        return $this->StartInOld + $this->Size;
    }

    public function endInNew()
    {
        return $this->StartInNew + $this->Size;
    }

    public function count()
    {
        return (int)$this->Size;
    }
}
