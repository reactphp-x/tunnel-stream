<?php

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
} else {
    require __DIR__ . '/../../../autoload.php';
}

if (getenv('BOOT_FILE')) {
    require_once getenv('BOOT_FILE');
}

use React\EventLoop\Loop;
use ReactphpX\TunnelStream\TunnelStream;
use React\Stream\ThroughStream;

$read = new ThroughStream();
$write = new ThroughStream();

$tunnelStream = new TunnelStream($read, $write, true);
$write->on('data', function ($buffer) {
    fwrite(STDOUT, $buffer);
});


Loop::addReadStream(STDIN, function ($stream) use ($read) {
    while($buffer = fread($stream, 8192)) {
        $read->write($buffer);
    }
});
