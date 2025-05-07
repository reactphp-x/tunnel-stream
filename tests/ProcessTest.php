<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use React\ChildProcess\Process;
use ReactphpX\TunnelStream\TunnelStream;
use function React\Async\await;
use React\EventLoop\Loop;

class ProcessTest extends TestCase
{
    private Process $process;
    private TunnelStream $tunnelStream;

    protected function setUp(): void
    {
        $this->process = new Process(sprintf(
            'exec php %s/../examples/child_process_init.php',
            __DIR__
        ));

        $this->process->start();

        // Wait for process to start
        await(\React\Promise\Timer\sleep(0.5));

        if (!$this->process->isRunning()) {
            throw new \RuntimeException('Process failed to start');
        }

        $this->tunnelStream = new TunnelStream($this->process->stderr, $this->process->stdin);

        // Wait for tunnel to be ready
        await($this->tunnelStream->ping(1));
    }

    protected function tearDown(): void
    {
        if ($this->process->isRunning()) {
            $this->process->terminate();
        }
        $this->tunnelStream->close();
    }

    public function testFileStream(): void
    {
        $promise = new \React\Promise\Deferred();
        
        $fileStream = $this->tunnelStream->run(function () {
            return file_get_contents(__DIR__.'/../composer.json');
        });

        $data = '';
        $fileStream->on('data', function ($chunk) use (&$data) {
            $data .= $chunk;
        });

        $fileStream->on('end', function () use ($promise, &$data) {
            $promise->resolve($data);
        });

        $fileStream->on('error', function ($error) use ($promise) {
            $promise->reject($error);
        });

        $result = await(\React\Promise\Timer\timeout($promise->promise(), 2));
        
        $this->assertNotEmpty($result);
        $this->assertJson($result);
    }

    public function testPromiseStream(): void
    {
        $promise = new \React\Promise\Deferred();
        
        $promiseStream = $this->tunnelStream->run(function () {
            return \React\Promise\Timer\sleep(0.1)->then(function () {
                return 'Hello World';
            });
        });

        $data = '';
        $promiseStream->on('data', function ($chunk) use (&$data) {
            $data .= $chunk;
        });

        $promiseStream->on('end', function () use ($promise, &$data) {
            $promise->resolve($data);
        });

        $promiseStream->on('error', function ($error) use ($promise) {
            $promise->reject($error);
        });

        $result = await(\React\Promise\Timer\timeout($promise->promise(), 2));
        
        $this->assertEquals('Hello World', $result);
    }

    public function testPing(): void
    {
        $result = await($this->tunnelStream->ping(1));
        $this->assertTrue($result);
    }

    public function testAlwaysStream(): void
    {
        $promise = new \React\Promise\Deferred();
        $messages = [];
        
        $alwaysStream = $this->tunnelStream->run(function ($stream) {
            $i = 0;
            $timer = Loop::addPeriodicTimer(0.1, function () use ($stream, &$i) {
                $stream->write('Hello World' . $i . PHP_EOL);
                $i++;
            });
            $stream->on('close', function () use ($timer) {
                Loop::cancelTimer($timer);
            });
            return $stream;
        });

        $alwaysStream->on('data', function ($data) use (&$messages, $alwaysStream) {
            $messages[] = $data;
            if (count($messages) >= 2) {
                $alwaysStream->close();
            }
        });

        $alwaysStream->on('close', function () use ($promise, &$messages) {
            $promise->resolve($messages);
        });

        $result = await(\React\Promise\Timer\timeout($promise->promise(), 2));
        
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('Hello World', $result[0]);
    }

    public function testErrorHandling(): void
    {
        $promise = new \React\Promise\Deferred();
        $errorMessage = '';
        
        $errorStream = $this->tunnelStream->run(function () {
            throw new \RuntimeException('Test error message');
        });

        $errorStream->on('error', function ($error) use ($promise, &$errorMessage) {
            $errorMessage = $error->getMessage();
            $promise->resolve(true);
        });

        await(\React\Promise\Timer\timeout($promise->promise(), 2));
        
        $this->assertEquals('Test error message', $errorMessage);
    }

    public function testPromiseErrorHandling(): void
    {
        $promise = new \React\Promise\Deferred();
        $errorMessage = '';
        
        $errorStream = $this->tunnelStream->run(function () {
            return \React\Promise\Timer\sleep(0.1)->then(function () {
                throw new \RuntimeException('Test promise error message');
            });
        });

        $errorStream->on('error', function ($error) use ($promise, &$errorMessage) {
            $errorMessage = $error->getMessage();
            $promise->resolve(true);
        });

        await(\React\Promise\Timer\timeout($promise->promise(), 2));
        
        $this->assertEquals('Test promise error message', $errorMessage);
    }
} 