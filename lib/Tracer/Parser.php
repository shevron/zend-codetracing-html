<?php

namespace Tracer;

use Tracer\Exception\RuntimeException;

class Parser
{
    static protected $lineTypeMap = array(
            'REQUEST'      => Step::REQUEST,
            'HEADER'       => Step::HEADER,
            'INCLUDE'      => Step::FILEINCLUDE,
            'ERROR'        => Step::ERROR,
            'SEND_HEADERS' => Step::SENDHEADERS,
            'EXIT'         => Step::SCRIPTEXIT
    );

    protected $depth     = 0;

    protected $input     = null;

    protected $output    = null;

    protected $defaultFormatterClass = 'Tracer\Formatter\SingleHtmlFile';

    protected $formatter = null;

    public function __construct($input, $output)
    {
        if (! ($this->input  = fopen($input, 'r'))) {
            throw new RuntimeException("Unable to open input file $input");
        }

        if (! ($this->output = fopen($output, 'w'))) {
            throw new RuntimeException("Unable to open output file $output");
        }
    }

    public function setFormatter(Formatter\FormatterInterface $formatter)
    {
        $this->formatter = $formatter;
        return $this;
    }

    public function getFormatter()
    {
        if (! $this->formatter) {
            $this->formatter = new $this->defaultFormatterClass;
        }

        return $this->formatter;
    }

    public function convert()
    {
        $formatter = $this->getFormatter();
        $this->write($formatter->startOutput());

        while(($line = fgets($this->input)) != false) {
            $data = $this->parse($line);

            // Increase depth as needed
            while ($data->depth > $this->depth) {
                $this->write($formatter->increaseDepth());
                $this->depth++;
            }

            // Decrease depth as needed
            while ($data->depth < $this->depth) {
                $this->write($formatter->decreaseDepth());
                $this->depth--;
            }

            $this->write($formatter->write($data));
        }

        $this->write($formatter->endOutput());
    }

    protected function parse($line)
    {
        $traceline = new Step();

        // Figure out depth
        $traceline->depth = $this->trimLine($line);

        // Extract type
        $traceline->type = $this->readLineType($line);

        switch($traceline->type) {
            case Step::FUNCTIONCALL:
                $traceline->data = $this->parseFunctionCall($line);
                break;

            case Step::FILEINCLUDE:
                $traceline->data = $this->parseIncludeCall($line);
                break;

            case Step::HEADER:
                $traceline->data = $this->parseHeaderSet($line);
                break;

            case Step::REQUEST:
                $traceline->data = $this->parseRequest($line);
                break;

            case Step::WRITE:
                $traceline->data = $this->parseWrite($line);
                break;

            case Step::SENDHEADERS:
            case Step::SCRIPTEXIT:
                // no data
                break;

                // TODO: handle other stuff

            default:
                fprintf(STDERR, "WARN: don't know how to handle type {$traceline->type}\n");
        }

        return $traceline;
    }

    protected function parseWrite($line)
    {
        $data = array();
        $data['runtime'] = $this->parseRuntime($line);

        if (preg_match('/^\((\d+)\): "(.*)"$/', $line, $match)) {
            $data['size'] = (int) $match[1];
            $output = $match[2];

            if ($this->checkStringCut($output)) {
                $data['cut'] = true;
            }
            $data['output'] = $output;
        }

        return $data;
    }

    protected function checkStringCut(&$string)
    {
        if (substr($string, -7) == '**CUT**') {
            $string = substr($string, 0, -7);
            return true;
        }
        return false;
    }

    protected function parseRequest($line)
    {
        $data = array();
        $data['memusage'] = $this->parseMemoryUsage($line);
        $data['runtime']  = $this->parseRuntime($line);

        $parts = explode(" ", $line);
        $data['finalurl']    = $parts[0];
        $data['originalurl'] = $parts[2];
        $data['remoteip']    = $parts[4];

        return $data;
    }

    protected function parseFunctionCall($line)
    {
        $data = array();
        $data['memusage'] = $this->parseMemoryUsage($line);
        $data['runtime']  = $this->parseRuntime($line);
        $data = array_merge($data, $this->parseFunctionData($line));

        return $data;
    }

    protected function parseIncludeCall($line)
    {
        $data = array();
        $data['memusage'] = $this->parseMemoryUsage($line);
        $data['runtime']  = $this->parseRuntime($line);
        $data['file']     = $this->readToken($line);

        return $data;
    }

    protected function parseHeaderSet($line)
    {
        if (substr($line, 0, 8) == "REPLACE ") {
            $data['replace'] = true;
            $line = substr($line, 8);
        }

        $line = trim($line, '"');
        $data['header'] = $line;

        return $data;
    }

    protected function parseFunctionData(&$line)
    {
        $data = array();

        // Extract function name
        $cutpos = strpos($line, '(');
        $funcname = substr($line, 0, $cutpos);
        $line = substr($line, $cutpos);

        // Is it a method call? split into class and methof
        $isClassMethod = false;
        if (($cutpos = strpos($funcname, '::')) !== false) {
            $data['classname'] = substr($funcname, 0, $cutpos);
            $funcname = substr($funcname, $cutpos + 2);
            $isClassMethod = true;
        }
        $data['funcname'] = $funcname;

        // Extract return value
        if (preg_match('/\) -> (.+)$/', $line, $match, PREG_OFFSET_CAPTURE)) {
            $data['retvalue'] = $match[1][0];
            $line = substr($line, 1, $match[0][1] - 1);
        } elseif (preg_match('/\) THROWS (.+)$/', $line, $match, PREG_OFFSET_CAPTURE)) {
            $data['throws'] = $match[1][0];
            $line = substr($line, 1, $match[0][1] - 1);
        } else {
            $line = substr($line, 1, -1);
        }

        // We should be left with only arguments and 'this'
        if (preg_match('/^(?:this=(.+?)\|)?(.*)$/', $line, $match)) {
            if ($match[1]) {
                $data['this'] = $match[1];
            }
            if ($match[2]) {
                // TODO: split arguments?
                $data['arguments'] = $match[2];
            }
        }

        return $data;
    }

    protected function readToken(&$line)
    {
        if (substr($line, 0, 1) == '"') {
            $quote = strpos($line, '"', 1);
            $token = substr($line, 1, $quote);
            $line = substr($line, $quote + 1);
        } else {
            $space = strpos($line, " ");
            if ($space !== false) {
                $token = substr($line, 0, $space);
                $line = substr($line, $space + 1);
            } else {
                $token = $line;
                $line = '';
            }
        }

        return $token;
    }

    /**
     * Parse memory usage information.
     *
     * Memory usage is at the end of the line and has the following format:
     *
     * mem:12345->23456
     *
     * Where the first number is memory usage in Kb before entering this step,
     * and the second number is memory usage in Kb after leaving this step.
     *
     * Returns an integer (can be positive, negative or 0)
     *
     * @param  string $line
     * @return integer
     */
    protected function parseMemoryUsage(&$line)
    {
        $mempos = strrpos($line, "mem:");
        if ($mempos) {
            $meminfo = substr($line, $mempos + 4);
            $line = substr($line, 0, $mempos - 1);
            list($start, $end) = explode("->", $meminfo);
            return ((int) $start - (int) $end);
        } else {
            return 0;
        }
    }

    /**
     * Extract the run time
     *
     * This assumes memory usage has been extracted and that runtime is the
     * last element in the line, with the following format:
     *
     * [555 us]
     *
     * Where the number is runtime in micro-seconds
     *
     * Will return the runtime in milliseconds (!)
     *
     * @param  string $line
     * @return float
     */
    protected function parseRuntime(&$line)
    {
        if (preg_match('/\s*\[(-?\d+) us\]\s*$/', $line, $match, PREG_OFFSET_CAPTURE)) {
            $time = $match[1][0] / 1000;
            $line = substr($line, 0, $match[0][1]);
        } else {
            $time = 0.0;
        }

        return $time;
    }

    /**
     * Trim the line and return the indent level
     *
     * This modifies $line by trimming any leading or trailing space
     *
     * @param  string $line
     * @return integer
     * @throws \ErrorException
     */
    protected function trimLine(&$line)
    {
        $line = rtrim($line);
        // Figure out indent
        $linelen = strlen($line);
        $line = ltrim($line);
        $indent = $linelen - strlen($line);
        if ($indent % 4 != 0) {
            throw new \ErrorException("Unexpected indent in line: $line");
        }
        return $indent / 4;
    }

    protected function readLineType(&$line)
    {
        $firstSpace = strpos($line, " ");
        $type = substr($line, 0, $firstSpace);

        if (isset(self::$lineTypeMap[$type])) {
            $line = substr($line, $firstSpace + 1);
            return self::$lineTypeMap[$type];
        } elseif (substr($type, 0, 6) == "WRITE(") {
            $line = substr($line, 5);
            return Step::WRITE;
        } else {
            return Step::FUNCTIONCALL;
        }
    }

    protected function write($output)
    {
        fwrite($this->output, $output);
    }

    public function __destruct()
    {
        fclose($this->input);
        fclose($this->output);
    }
}