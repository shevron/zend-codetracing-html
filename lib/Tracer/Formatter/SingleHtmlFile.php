<?php

namespace Tracer\Formatter;

use Tracer\Step;

class SingleHtmlFile implements FormatterInterface
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
    <script src="http://cdn.jquerytools.org/1.2.6/jquery.tools.min.js" type="text/javascript"></script>
    <script src="http://ajax.googleapis.com/ajax/libs/jqueryui/1.8.16/jquery-ui.min.js" type="text/javascript"></script>
    <script type="text/javascript">
        $(document).ready(function() {
            // Fix trace window dimentions
            $(window).resize(function() {
                setViewportSize();
            });
            setViewportSize();

            // Attach collapse / expand function
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

        function setViewportSize() {
            $("#trace").height($(document).height() - 114);
            $("#trace").width($(window).width() - 24);
        }

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
        #trace { padding: 1em; position: fixed; left: 0; width: 100%; top: 114px; overflow: scroll; z-index: 0; }
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
        #heading { padding: 1em; border-bottom: 1px solid #a0a0a0; position: fixed; top: 0px; left: 0px; width: 100%; box-shadow: 5px 5px 10px #a0a0a0; height: 90px; background-color: #ffffff; z-index: 10; }
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

    public function write(Step $line)
    {
        if ($this->openItem) {
            $output = "</li>";
        } else {
            $output = "";
        }

        switch($line->type) {
            case Step::FILEINCLUDE:
                $class = "include";
                $html = "include <label>" . htmlspecialchars($line->data['file']) . "</label>";
                break;

            case Step::FUNCTIONCALL:
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

            case Step::HEADER:
                $class = "header";
                $html = "header: <label>" . htmlspecialchars($line->data['header']) . '</label>';
                if (isset($line->data['replace'])) {
                    $html .= " (replace)";
                }
                break;

            case Step::REQUEST:
                $class = 'request';
                $html = "<h2>Request for {$line->data['finalurl']} from {$line->data['remoteip']}</h2>";
                break;

            case Step::WRITE:
                $class = "write";
                $html = "<strong>--- write {$line->data['size']} bytes to output ---</strong>";
                break;

            case Step::SENDHEADERS:
                $class = 'sendheaders';
                $html = "<strong>--- SEND HEADERS ---</strong>";
                break;

            case Step::SCRIPTEXIT:
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