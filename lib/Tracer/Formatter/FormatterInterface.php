<?php

namespace Tracer\Formatter;

use Tracer\Step;

interface FormatterInterface
{
    public function startOutput();

    public function write(Step $step);

    public function increaseDepth();

    public function decreaseDepth();

    public function endOutput();
}