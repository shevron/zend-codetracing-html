zend-codetracing-html: Convert Zend Server Code Tracing dump files to HTML
===============================================================================

About
-----
This small utility can be used to convert 
[Zend Server Code Tracing](http://www.zend.com/en/products/server/zend-server-code-tracing)
dump files into HTML, with some JavaScript, which can be displayed in any browser.

I have started this project because I found it hard to work with the default 
Flash-based viewer included in Zend Server and Zend Studio; while I find 
immense value in Code Tracing, the Flash viewer made it very unusable for me,
and so I started hacking this converter to scratch my own itch. 

It is by no means supported or endorsed by Zend in any way.


Usage
-----
To use, you must first produce a Code Tracing dump file for the Web page you
want to inspect. Doing this is not covered here, but in short, make sure you 
have a licensed copy of Zend Server installed, and hit the Webpage, adding 
'?dump\_data=1' to the URL. The trace file ID will now be listed in the Zend
Server GUI

Once you have the trace file (usually under /usr/local/zend/var/codetracing),
run the `zmd` utility provided in the Zend Server bin directory to convert
the trace file into plain-text format: 

    /usr/local/zend/bin/zmd -o ~/my-trace.txt \
      /usr/local/zend/var/codetracing/dump.0.21333.1

Note: do not use any .amf files as input to `zmd`. 

Next, convert the text file to HTML using the trace-to-html.php tool:

    php trace-to-html.php -i <input file> -o <output file> -t <page title>

The following arguments are supported:

    -i <file>  - input file, standard input is used if not provided
    -o <file>  - output file, standard output is used if not provided
    -t <title> - HTML page title. This is optional, but recommended

Finally, open the generated HTML file in a browser and enjoy!


License
-------
The zend-codetracing-html tool is distributed under the terms of the New BSD
License; See LICENSE for details. 




TODO
----

*Formatter*:

- Add ability to re-show filtered out elements
- Provide more information in tooltips: arguments, $this, etc.
- Provide ability to trace object instance 
- Improve HTML layout so no parts hide under the title part
- Provide additional formatters (e.g. xml) ?

*Converter*: 

- Handle location and origin information provided by zmd extra flags
- Use binary format as input, dropping requirement for zmd use

*Packaging*:

- Split single file into multiple class files and provide a PEAR package

