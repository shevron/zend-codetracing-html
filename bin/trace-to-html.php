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

/**
 * Parse command line arguments
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

/**
 * Set up the environment
 */

if (getenv("TRACER_LIBDIR")) {
    $libDir = realpath(getenv("TRACER_LIBDIR"));
} else {
    $libDir = realpath(__DIR__ . '/../lib');
}

if (! ($libDir && file_exists($libDir . '/Tracer/Parser.php'))) {
    fprintf(STDERR, "ERROR: unable to locate Tracer library files\n");
    exit(1);
}

// Define autoloader
spl_autoload_register(function($class) use ($libDir) {
     $filename = rtrim($libDir, DIRECTORY_SEPARATOR) . '/' .
                 strtr($class, '\\', '/') . '.php';

     if (file_exists($filename)) {
         require $filename;
         return true;
     }

     return false;
});

// Run!

$convertor = new Tracer\Parser($input, $output);
$convertor->setFormatter(new Tracer\Formatter\SingleHtmlFile(array(
    'title' => $title
)));

$convertor->convert();
