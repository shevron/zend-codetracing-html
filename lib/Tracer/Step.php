<?php

namespace Tracer;

class Step
{
    const REQUEST      = 1;
    const FUNCTIONCALL = 2;
    const HEADER       = 3;
    const FILEINCLUDE  = 4;
    const WRITE        = 5;
    const ERROR        = 6;
    const SENDHEADERS  = 7;
    const SCRIPTEXIT   = 8;

    public $depth;

    public $type = self::FUNCTIONCALL;

    public $data = array();
}