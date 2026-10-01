<?php

declare(strict_types=1);

// Zero-dependency pure PHP test runner
spl_autoload_register(function ($class) {
    $prefix = 'EidCloud\\ServerPlane\\';
    $baseDir = __DIR__ . '/../src/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

require_once __DIR__ . '/ServerPlaneTest.php';

use EidCloud\ServerPlane\Tests\ServerPlaneTest;

$test = new ServerPlaneTest();
$success = $test->runAll();

exit($success ? 0 : 1);
