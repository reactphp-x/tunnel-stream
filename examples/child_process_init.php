<?php

use React\Stream\WritableResourceStream;

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
use React\Stream\ReadableResourceStream;

$read = new ThroughStream();
$write = new ThroughStream();

$tunnelStream = new TunnelStream($read, $write, true);

$STDOUT = new WritableResourceStream(STDOUT);
$write->on('data', function ($buffer) use ($STDOUT) {
    $STDOUT->write($buffer);
});
$write->on('close', function () use ($tunnelStream) {
    $tunnelStream->close();
});

$STDIN = new ReadableResourceStream(STDIN);
$STDIN->on('data', function ($chunk) use ($read) {
    $read->write($chunk);
});
$STDIN->on('close', function () use ($tunnelStream) {
    $tunnelStream->close();
});