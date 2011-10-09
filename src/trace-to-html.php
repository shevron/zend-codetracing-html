<?php

/**
 * Convert code tracing ZMD output to an HTML file
 *
 * Usage:
 *
 * php trae-to-html.php [options]
 *
 * Options are:
 *   -t <title>  - Set title for HTML output file
 *   -i <input>  - Input file (standard input is default)
 *   -o <output> - Output file (standard output is default)
 */

$input  = 'php://stdin';
$output = 'php://stdout';

$args = getopt('t:i:o:');

if (isset($args['i'])) {
    $input = $args['i'];
}

if (isset($args['o'])) {
    $output = $args['o'];
}

if (isset($args['t'])) {
    $title = $args['t'];
} else {
    $title = "Execution Trace";
}

$convertor = new TraceConvertor($input, $output);
$convertor->setFormatter(new TraceFormatterHtml(array(
    'title' => $title
)));

$convertor->convert();

class TraceConvertor
{
    static protected $lineTypeMap = array(
        'REQUEST'      => TraceLine::REQUEST,
        'HEADER'       => TraceLine::HEADER,
        'INCLUDE'      => TraceLine::FILEINCLUDE,
        'ERROR'        => TraceLine::ERROR,
        'SEND_HEADERS' => TraceLine::SENDHEADERS,
        'EXIT'         => TraceLine::SCRIPTEXIT
    );

    protected $depth     = 0;

    protected $input     = null;

    protected $output    = null;

    protected $formatter = null;

    public function __construct($input, $output)
    {
        if (! ($this->input  = fopen($input, 'r'))) {
            throw new ErrorException("Unable to open input file $input");
        }

        if (! ($this->output = fopen($output, 'w'))) {
            throw new ErrorException("Unable to open output file $output");
        }
    }

    public function setFormatter(TraceFormatterHtml $formatter)
    {
        $this->formatter = $formatter;
        return $this;
    }

    public function getFormatter()
    {
        if (! $this->formatter) {
            $this->formatter = new TraceFormatterHtml();
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
        $traceline = new TraceLine();

        // Figure out depth
        $traceline->depth = $this->trimLine($line);

        // Extract type
        $traceline->type = $this->readLineType($line);

        switch($traceline->type) {
            case TraceLine::FUNCTIONCALL:
                $traceline->data = $this->parseFunctionCall($line);
                break;

            case TraceLine::FILEINCLUDE:
                $traceline->data = $this->parseIncludeCall($line);
                break;

            case TraceLine::HEADER:
                $traceline->data = $this->parseHeaderSet($line);
                break;

            case TraceLine::REQUEST:
                $traceline->data = $this->parseRequest($line);
                break;

            case TraceLine::WRITE:
                $traceline->data = $this->parseWrite($line);
                break;

            case TraceLine::SENDHEADERS:
            case TraceLine::SCRIPTEXIT:
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
            $line = substr($line, 1, $match[0][1]);
        } else {
            $line = substr($line, 1, -1);
        }

        // We should be left with only arguments and 'this'
        if (preg_match('/^(?:this=(.+?)|)?(.*)$/', $line, $match)) {
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
        if (preg_match('/\s*\[(\d+) us\]\s*$/', $line, $match, PREG_OFFSET_CAPTURE)) {
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
            return TraceLine::WRITE;
        } else {
            return TraceLine::FUNCTIONCALL;
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

class TraceLine
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

class TraceFormatterHtml
{
    protected $traceId = 0;

    protected $title = "Execution Trace";

    protected $openItem = false;

    public function __construct($config = array())
    {
        if (isset($config['title'])) {
            $this->title = $config['title'];
        }
    }

    public function startOutput()
    {
        $title = htmlspecialchars($this->title);

        $html = <<<EOHTML
<!DOCTYPE html>
<html>
<head>
    <title>$title</title>
    <script src="http://ajax.googleapis.com/ajax/libs/jquery/1.6.4/jquery.min.js" type="text/javascript"></script>
    <script src="http://ajax.googleapis.com/ajax/libs/jqueryui/1.8.16/jquery-ui.min.js" type="text/javascript"></script>
    <script src="http://cdn.jquerytools.org/1.2.6/jquery.tools.min.js" type="text/javascript"></script>
    <script type="text/javascript">
        $(document).ready(function() {
            $("#trace li:has(ul) div label").click(function() {
                var parentItem = $(this).closest("li");
                $(parentItem).children("ul").first().toggle();
                $(parentItem).toggleClass("folded");
                if ($(parentItem).hasClass("folded")) {
                    var hiddenLines = $("li", parentItem).length;
                    $(" > div", parentItem).append('<span class="folded-info">[' + hiddenLines + ' lines hidden]</span>');
                } else {
                    $('span.folded-info', parentItem).remove();
                }
            });

            $("#trace li label[title]").tooltip();
        });

        function toParent(a) {
            var parentId = $(a).closest("ul").closest("li").attr('id');
            if (parentId) {
                window.location = '#' + parentId;
                $('#' + parentId + ' > div label').effect("highlight", {}, 3000);
            }
        }

        function filterOut(label) {
            var matches = $("#trace label:contains(" + label + ")").closest("li");
            matches.hide();
            alert("Filtered out " + matches.length + " lines");
        }
    </script>
    <style type="text/css">
        body { font-family: Tahoma, sans-serif; font-size: 9pt; padding: 0; margin: 0 }
        h2 { margin: 0; }
        #trace { padding: 1em; margin-top: 110px; }
        #trace ul { margin: 0; padding: 0; list-style-type: none; }
        /*#trace li.highlight { background-color: #ff7f20; }*/
        #trace li ul { padding-left: 1em; }
        #trace ul li.folded { background-color: #e0e0e0; color: #a0a0a0; }
        #trace .include div { background-color: #9edede; }
        #trace .functioncall div { background-color: #ffffff; }
        #trace .header div { background-color: #bcbcff; }
        #trace .write, #trace .exit, #trace .sendheaders div { background-color: #fcfc20; }
        #trace label { font-family: monospace; font-weight: bold }
        #trace li div .line-controls { display: none; margin-left: 2em; }
        #trace li div:hover .line-controls { display: inline-block; }
        #trace li div:hover { background-color: #f0f000; }
        #heading { padding: 1em; border-bottom: 1px solid #a0a0a0; position: fixed; top: 0px; left: 0px; width: 100%; box-shadow: 5px 5px 10px #a0a0a0; height: 90px; background-color: #ffffff; }
        .tooltip { display:none; background: #333; color:#fff; padding: 5px; border-radius: 3px; box-shadow: 3px 3px 5px #aaa; }
    </style>
</head>
<body>
    <div id="heading">
        <h1>$title</h1>
        <div id="controls">
            <div class="filter-control">
                <label>Filter out calls to: </label><input id="filter-out-input" type="text" />
                <button onclick="filterOut($('#filter-out-input')[0].value);">filter out</button>
            </div>
        </div>
    </div>
    <div id="trace">
        <ul>
EOHTML;

        $this->openItem = false;

        return $html;
    }

    public function increaseDepth()
    {
        if ($this->openItem) {
            $this->openItem = false;
            return "<ul>\n";
        } else {
            return "<li><ul>\n";
        }
    }

    public function write(TraceLine $line)
    {
        if ($this->openItem) {
            $output = "</li>";
        } else {
            $output = "";
        }

        switch($line->type) {
            case TraceLine::FILEINCLUDE:
                $class = "include";
                $html = "include <label>" . htmlspecialchars($line->data['file']) . "</label>";
                break;

            case TraceLine::FUNCTIONCALL:
                $class = "functioncall";
                if (isset($line->data['classname'])) {
                    $class .= " method";
                    if (! isset($line->data['this'])) $class .= " static";
                    if ($line->data['funcname'] == '__construct') $class .= " constructor";
                    $html = '<label title="' .
                        "returned: " . htmlspecialchars(isset($line->data['retvalue']) ? $line->data['retvalue'] : 'void') .
                        '">' . htmlspecialchars($line->data['classname'] .
                        (isset($line->data['this']) ? '->' : '::') . $line->data['funcname']) . '</label>';
                } else {
                    $html = '<label>' . htmlspecialchars($line->data['funcname']) . '</label>';
                }
                break;

            case TraceLine::HEADER:
                $class = "header";
                $html = "header: <label>" . htmlspecialchars($line->data['header']) . '</label>';
                if (isset($line->data['replace'])) {
                    $html .= " (replace)";
                }
                break;

            case TraceLine::REQUEST:
                $class = 'request';
                $html = "<h2>Request for {$line->data['finalurl']} from {$line->data['remoteip']}</h2>";
                break;

            case TraceLine::WRITE:
                $class = "write";
                $html = "<strong>--- write {$line->data['size']} bytes to output ---</strong>";
                break;

            case TraceLine::SENDHEADERS:
                $class = 'sendheaders';
                $html = "<strong>--- SEND HEADERS ---</strong>";
                break;

            case TraceLine::SCRIPTEXIT:
                $class = 'exit';
                $html = "<strong>--- END SCRIPT ---</strong>";
                break;
        }

        $this->traceId++;


        if (isset($html)) {
            $output .= "<li class=\"$class\" id=\"traceline-{$this->traceId}\"><div>$html\n";
            if ($this->traceId > 1) {
                $output .= '<span class="line-controls"><a href="#" onclick="toParent(this); return false;">to parent</a></span>';
            }
            $output .= '</div>';

        } else {
            $output = '<li>&nbsp;';
            fprintf(STDERR, "WARNING: Unhandled type by renderer: $line->type\n");
        }

        $this->openItem = true;
        return $output;
    }

    public function decreaseDepth()
    {
        if ($this->openItem) {
            $this->openItem = false;
            return "</li></ul>\n";
        } else {
            return "</ul>";
        }
    }

    public function endOutput()
    {
        if ($this->openItem) {
            $this->openItem = false;
            $output = "\n</li>";
        } else {
            $output = "\n";
        }

        return "$output</ul></div></body></html>";
    }
}