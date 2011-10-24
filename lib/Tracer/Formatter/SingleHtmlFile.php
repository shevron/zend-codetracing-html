<?php

namespace Tracer\Formatter;

use Tracer\Step;

class SingleHtmlFile implements FormatterInterface
{
    protected $traceId = 0;

    protected $title = "Execution Trace";

    protected $openItem = false;

    protected $funcnames = array();

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
    <script type="text/javascript">
        $(document).ready(function() {
            // Fix trace window dimentions
            $(window).resize(function() {
                setViewportSize();
            });
            setViewportSize();

            // Replace function name references
            $("#trace label.fn").each(function(i) {
                var name = funcnametbl[parseInt($(this).text(), 10)];
                if (name) { $(this).text(name); }
            });

            // Attach collapse / expand function
            $("#trace li:has(ul) > div label").click(function() {
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

            $("#trace li > div div.step-tooltip").prev("label").tooltip({
                delay: 100,
                showURL: false,
                fade: 50,
                bodyHandler: function() {
                    return $(this).next("div.step-tooltip").html();
                }
            });
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

        {$this->getExternalJavaScript()}
    </script>
    <style type="text/css">
        body { font-family: Tahoma, sans-serif; font-size: 9pt; padding: 0; margin: 0 }
        h2 { margin: 0; }
        #trace { padding: 1em; position: fixed; left: 0; width: 100%; top: 114px; overflow: auto; }
        #trace ul { margin: 0; padding: 0; list-style-type: none; }
        #trace li ul { padding-left: 1em; }
        #trace ul li.folded { background-color: #e0e0e0; color: #a0a0a0; }
        #trace .include > div { background-color: #9edede; }
        #trace .functioncall > div { background-color: #ffffff; }
        #trace .header > div { background-color: #bcbcff; }
        #trace .throws > div { background-color: #ffbcbc !important; }
        #trace .write > div,
        #trace .exit > div,
        #trace .sendheaders > div { background-color: #fcfc20; }
        #trace label { font-family: monospace; font-weight: bold }
        #trace li div .line-controls { display: none; margin: 0 1em; }
        #trace li > div:hover .line-controls { display: inline-block; }
        #trace li .trace-id { display: inline-block; float: right; }
        #trace li > div:hover { background-color: #f0f000; }
        #heading { padding: 1em; border-bottom: 1px solid #a0a0a0; position: fixed; top: 0px; left: 0px; width: 100%; box-shadow: 5px 5px 10px #a0a0a0; height: 90px; background-color: #ffffff; z-index: 10 }
        .step-tooltip { display: none; }
        #tooltip { background: #333; color:#fff; padding: 5px; border-radius: 3px; box-shadow: 3px 3px 5px #aaa; position: absolute; z-index: 3000; opacity: 0.85; }
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
                $html = $this->getFunctioncallHtml($line, $class);
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
            $output .= '<span class="trace-id">' . $this->traceId . '</span>';
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
        $output .= "</ul></div>";

        // write function name table
        $output .= '<script type="text/javascript">var funcnametbl = ' .
            json_encode(array_keys($this->funcnames)) . ';</script>';

        $output .= "</body></html>";

        return $output;
    }

    protected function getFuncNameRef($fname)
    {
        if (! isset($this->funcnames[$fname])) {
            $this->funcnames[$fname] = count($this->funcnames);
        }

        return $this->funcnames[$fname];
    }

    protected function getFunctioncallHtml(Step $step, &$class)
    {
        if (! $class) {
            $class = "functioncall";
        }

        $html = '<label class="fn">';

        if (isset($step->data['classname'])) {
            $class .= " method";
            if (! isset($step->data['this'])) $class .= " static";
            $funcname = $step->data['classname'] . (isset($step->data['this']) ? '->' : '::') . $step->data['funcname'];
        } else {
            $funcname = $step->data['funcname'];
        }

        $html .= $this->getFuncNameRef($funcname) . '</label>';

        // Create tooltip
        $html .= '<div class="step-tooltip">';

        if (isset($step->data['this'])) {
            $html .= '<div>Instance: ' . htmlspecialchars($step->data['this']) . '</div>';
        }

        if (isset($step->data['arguments'])) {
            $html .= '<div>Arguments: ' . htmlspecialchars($step->data['arguments']) . '</div>';
        }

        $html .= '<div>';
        if (isset($step->data['retvalue'])) {
            $html .= 'Returned: ' . htmlspecialchars($step->data['retvalue']);
        } elseif (isset($step->data['throws'])) {
            $class .= " throws";
            $html .= 'Exception Thrown: ' . htmlspecialchars($step->data['throws']);
        } elseif ($step->data['funcname'] == '__construct') {
            $class .= " constructor";
        } else {
            $html .= 'Returned: null';
        }

        $html .= '</div></div>';

        return $html;
    }

    /**
     * This function returns some 3rd party JavaScript code to be included in
     * the output HTML
     *
     * It uses Open-source JavaScript code with accordance to relevant licenses.
     *
     * Code is base64-encoded to ensure safety
     *
     * @return string
     */

    protected function getExternalJavascript()
    {
        return base64_decode(<<<'ENDOFJS'
LyoKICogalF1ZXJ5IFRvb2x0aXAgcGx1Z2luIDEuMwogKgogKiBodHRwOi8vYmFz
c2lzdGFuY2UuZGUvanF1ZXJ5LXBsdWdpbnMvanF1ZXJ5LXBsdWdpbi10b29sdGlw
LwogKiBodHRwOi8vZG9jcy5qcXVlcnkuY29tL1BsdWdpbnMvVG9vbHRpcAogKgog
KiBDb3B5cmlnaHQgKGMpIDIwMDYgLSAyMDA4IErDtnJuIFphZWZmZXJlcgogKgog
KiAkSWQ6IGpxdWVyeS50b29sdGlwLmpzIDU3NDEgMjAwOC0wNi0yMSAxNToyMjox
Nlogam9lcm4uemFlZmZlcmVyICQKICogCiAqIER1YWwgbGljZW5zZWQgdW5kZXIg
dGhlIE1JVCBhbmQgR1BMIGxpY2Vuc2VzOgogKiAgIGh0dHA6Ly93d3cub3BlbnNv
dXJjZS5vcmcvbGljZW5zZXMvbWl0LWxpY2Vuc2UucGhwCiAqICAgaHR0cDovL3d3
dy5nbnUub3JnL2xpY2Vuc2VzL2dwbC5odG1sCiAqLwpldmFsKGZ1bmN0aW9uKHAs
YSxjLGssZSxyKXtlPWZ1bmN0aW9uKGMpe3JldHVybihjPGE/Jyc6ZShwYXJzZUlu
dChjL2EpKSkrKChjPWMlYSk+MzU/U3RyaW5nLmZyb21DaGFyQ29kZShjKzI5KTpj
LnRvU3RyaW5nKDM2KSl9O2lmKCEnJy5yZXBsYWNlKC9eLyxTdHJpbmcpKXt3aGls
ZShjLS0pcltlKGMpXT1rW2NdfHxlKGMpO2s9W2Z1bmN0aW9uKGUpe3JldHVybiBy
W2VdfV07ZT1mdW5jdGlvbigpe3JldHVybidcXHcrJ307Yz0xfTt3aGlsZShjLS0p
aWYoa1tjXSlwPXAucmVwbGFjZShuZXcgUmVnRXhwKCdcXGInK2UoYykrJ1xcYics
J2cnKSxrW2NdKTtyZXR1cm4gcH0oJzsoOCgkKXtqIGU9e30sOSxtLEIsQT0kLjJ1
LjJnJiYvMjlcXHMoNVxcLjV8NlxcLikvLjFNKDFILjJ0KSxNPTEyOyQuaz17dzox
MiwxaDp7WjoyNSxyOjEyLDFkOjE5LFg6IiIsRzoxNSxFOjE1LDE2OiJrIn0sMnM6
OCgpeyQuay53PSEkLmsud319OyQuTi4xdih7azo4KGEpe2E9JC4xdih7fSwkLmsu
MWgsYSk7MXEoYSk7ZyAyLkYoOCgpeyQuMWooMiwiayIsYSk7Mi4xMT1lLjMubigi
MWciKTsyLjEzPTIubTskKDIpLjI0KCJtIik7Mi4yMj0iIn0pLjIxKDFlKS4xVShx
KS4xUyhxKX0sSDpBPzgoKXtnIDIuRig4KCl7aiBiPSQoMikubihcJ1lcJyk7NChi
LjFKKC9eb1xcKFsiXCddPyguKlxcLjFJKVsiXCddP1xcKSQvaSkpe2I9MUYuJDE7
JCgyKS5uKHtcJ1lcJzpcJzFEXCcsXCcxQlwnOiIycjoycS4ybS4ybCgyaj0xOSwg
Mmk9MmgsIDFwPVwnIitiKyJcJykifSkuRig4KCl7aiBhPSQoMikubihcJzFvXCcp
OzQoYSE9XCcyZlwnJiZhIT1cJzF1XCcpJCgyKS5uKFwnMW9cJyxcJzF1XCcpfSl9
fSl9OjgoKXtnIDJ9LDFsOkE/OCgpe2cgMi5GKDgoKXskKDIpLm4oe1wnMUJcJzpc
J1wnLFk6XCdcJ30pfSl9OjgoKXtnIDJ9LDF4OjgoKXtnIDIuRig4KCl7JCgyKVsk
KDIpLkQoKT8ibCI6InEiXSgpfSl9LG86OCgpe2cgMi4xayhcJzI4XCcpfHwyLjFr
KFwnMXBcJyl9fSk7OCAxcShhKXs0KGUuMylnO2UuMz0kKFwnPHQgMTY9IlwnK2Eu
MTYrXCciPjwxMD48LzEwPjx0IDFpPSJmIj48L3Q+PHQgMWk9Im8iPjwvdD48L3Q+
XCcpLjI3KEsuZikucSgpOzQoJC5OLkwpZS4zLkwoKTtlLm09JChcJzEwXCcsZS4z
KTtlLmY9JChcJ3QuZlwnLGUuMyk7ZS5vPSQoXCd0Lm9cJyxlLjMpfTggNyhhKXtn
ICQuMWooYSwiayIpfTggMWYoYSl7NCg3KDIpLlopQj0yNihsLDcoMikuWik7cCBs
KCk7TT0hITcoMikuTTskKEsuZikuMjMoXCdXXCcsdSk7dShhKX04IDFlKCl7NCgk
Lmsud3x8Mj09OXx8KCEyLjEzJiYhNygyKS5VKSlnOzk9MjttPTIuMTM7NCg3KDIp
LlUpe2UubS5xKCk7aiBhPTcoMikuVS4xWigyKTs0KGEuMVl8fGEuMVYpe2UuZi4x
YygpLlQoYSl9cHtlLmYuRChhKX1lLmYubCgpfXAgNCg3KDIpLjE4KXtqIGI9bS4x
VCg3KDIpLjE4KTtlLm0uRChiLjFSKCkpLmwoKTtlLmYuMWMoKTsxUShqIGk9MCxS
OyhSPWJbaV0pO2krKyl7NChpPjApZS5mLlQoIjwxUC8+Iik7ZS5mLlQoUil9ZS5m
LjF4KCl9cHtlLm0uRChtKS5sKCk7ZS5mLnEoKX00KDcoMikuMWQmJiQoMikubygp
KWUuby5EKCQoMikubygpLjFPKFwnMU46Ly9cJyxcJ1wnKSkubCgpO3AgZS5vLnEo
KTtlLjMuUCg3KDIpLlgpOzQoNygyKS5IKWUuMy5IKCk7MWYuMUwoMiwxSyl9OCBs
KCl7Qj1TOzQoKCFBfHwhJC5OLkwpJiY3KDkpLnIpezQoZS4zLkkoIjoxNyIpKWUu
My5RKCkubCgpLk8oNyg5KS5yLDkuMTEpO3AgZS4zLkkoXCc6MWFcJyk/ZS4zLk8o
Nyg5KS5yLDkuMTEpOmUuMy4xRyg3KDkpLnIpfXB7ZS4zLmwoKX11KCl9OCB1KGMp
ezQoJC5rLncpZzs0KGMmJmMuMVcuMVg9PSIxRSIpe2d9NCghTSYmZS4zLkkoIjox
YSIpKXskKEsuZikuMWIoXCdXXCcsdSl9NCg5PT1TKXskKEsuZikuMWIoXCdXXCcs
dSk7Z31lLjMuVigiei0xNCIpLlYoInotMUEiKTtqIGI9ZS4zWzBdLjF6O2ogYT1l
LjNbMF0uMXk7NChjKXtiPWMuMm8rNyg5KS5FO2E9Yy4ybis3KDkpLkc7aiBkPVwn
MXdcJzs0KDcoOSkuMmspe2Q9JChDKS4xcigpLWI7Yj1cJzF3XCd9ZS4zLm4oe0U6
YiwxNDpkLEc6YX0pfWogdj16KCksaD1lLjNbMF07NCh2Lngrdi4xczxoLjF6K2gu
MW4pe2ItPWguMW4rMjArNyg5KS5FO2UuMy5uKHtFOmIrXCcxQ1wnfSkuUCgiei0x
NCIpfTQodi55K3YuMXQ8aC4xeStoLjFtKXthLT1oLjFtKzIwKzcoOSkuRztlLjMu
bih7RzphK1wnMUNcJ30pLlAoInotMUEiKX19OCB6KCl7Z3t4OiQoQykuMmUoKSx5
OiQoQykuMmQoKSwxczokKEMpLjFyKCksMXQ6JChDKS4ycCgpfX04IHEoYSl7NCgk
LmsudylnOzQoQikyYyhCKTs5PVM7aiBiPTcoMik7OCBKKCl7ZS4zLlYoYi5YKS5x
KCkubigiMWciLCIiKX00KCghQXx8ISQuTi5MKSYmYi5yKXs0KGUuMy5JKFwnOjE3
XCcpKWUuMy5RKCkuTyhiLnIsMCxKKTtwIGUuMy5RKCkuMmIoYi5yLEopfXAgSigp
OzQoNygyKS5IKWUuMy4xbCgpfX0pKDJhKTsnLDYyLDE1NSwnfHx0aGlzfHBhcmVu
dHxpZnx8fHNldHRpbmdzfGZ1bmN0aW9ufGN1cnJlbnR8fHx8fHxib2R5fHJldHVy
bnx8fHZhcnx0b29sdGlwfHNob3d8dGl0bGV8Y3NzfHVybHxlbHNlfGhpZGV8ZmFk
ZXx8ZGl2fHVwZGF0ZXx8YmxvY2tlZHx8fHZpZXdwb3J0fElFfHRJRHx3aW5kb3d8
aHRtbHxsZWZ0fGVhY2h8dG9wfGZpeFBOR3xpc3xjb21wbGV0ZXxkb2N1bWVudHxi
Z2lmcmFtZXx0cmFja3xmbnxmYWRlVG98YWRkQ2xhc3N8c3RvcHxwYXJ0fG51bGx8
YXBwZW5kfGJvZHlIYW5kbGVyfHJlbW92ZUNsYXNzfG1vdXNlbW92ZXxleHRyYUNs
YXNzfGJhY2tncm91bmRJbWFnZXxkZWxheXxoM3x0T3BhY2l0eXxmYWxzZXx0b29s
dGlwVGV4dHxyaWdodHx8aWR8YW5pbWF0ZWR8c2hvd0JvZHl8dHJ1ZXx2aXNpYmxl
fHVuYmluZHxlbXB0eXxzaG93VVJMfHNhdmV8aGFuZGxlfG9wYWNpdHl8ZGVmYXVs
dHN8Y2xhc3N8ZGF0YXxhdHRyfHVuZml4UE5HfG9mZnNldEhlaWdodHxvZmZzZXRX
aWR0aHxwb3NpdGlvbnxzcmN8Y3JlYXRlSGVscGVyfHdpZHRofGN4fGN5fHJlbGF0
aXZlfGV4dGVuZHxhdXRvfGhpZGVXaGVuRW1wdHl8b2Zmc2V0VG9wfG9mZnNldExl
ZnR8Ym90dG9tfGZpbHRlcnxweHxub25lfE9QVElPTnxSZWdFeHB8ZmFkZUlufG5h
dmlnYXRvcnxwbmd8bWF0Y2h8YXJndW1lbnRzfGFwcGx5fHRlc3R8aHR0cHxyZXBs
YWNlfGJyfGZvcnxzaGlmdHxjbGlja3xzcGxpdHxtb3VzZW91dHxqcXVlcnl8dGFy
Z2V0fHRhZ05hbWV8bm9kZVR5cGV8Y2FsbHx8bW91c2VvdmVyfGFsdHxiaW5kfHJl
bW92ZUF0dHJ8MjAwfHNldFRpbWVvdXR8YXBwZW5kVG98aHJlZnxNU0lFfGpRdWVy
eXxmYWRlT3V0fGNsZWFyVGltZW91dHxzY3JvbGxUb3B8c2Nyb2xsTGVmdHxhYnNv
bHV0ZXxtc2llfGNyb3B8c2l6aW5nTWV0aG9kfGVuYWJsZWR8cG9zaXRpb25MZWZ0
fEFscGhhSW1hZ2VMb2FkZXJ8TWljcm9zb2Z0fHBhZ2VZfHBhZ2VYfGhlaWdodHxE
WEltYWdlVHJhbnNmb3JtfHByb2dpZHxibG9ja3x1c2VyQWdlbnR8YnJvd3Nlcicu
c3BsaXQoJ3wnKSwwLHt9KSk=
ENDOFJS
                );
    }
}