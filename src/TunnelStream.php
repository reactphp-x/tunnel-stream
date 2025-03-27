<?php

namespace ReactphpX\TunnelStream;

use Evenement\EventEmitterInterface;
use Evenement\EventEmitterTrait;
use MessagePack\MessagePack;
use MessagePack\BufferUnpacker;
use React\Stream;
use Ramsey\Uuid\Uuid;
use React\EventLoop\Loop;

class TunnelStream implements EventEmitterInterface
{
    use EventEmitterTrait;

    public static $secretKey;

    protected BufferUnpacker $bufferUpacker;
    protected \SplObjectStorage $streams;

    protected $uuidToStream = [];

    protected $uuidToDeferred = [];



    public function __construct(
        protected Stream\ReadableStreamInterface $readStream,
        protected Stream\WritableStreamInterface $writStream,
        private $canCallback = false
    ) {

        $this->readStream->on('data', function ($data) {
            $this->handleRead($data);
        });

        $this->readStream->on('close', function () {
            $this->close();
        });

        $this->writStream->on('close', function () {
            $this->close();
        });

        $this->bufferUpacker = new BufferUnpacker();
        $this->streams = new \SplObjectStorage;
    }

    public function run(callable $closure): Stream\DuplexStreamInterface
    {
        $read = new Stream\ThroughStream;
        $write = new Stream\ThroughStream;
        $stream = new Stream\CompositeStream($read, $write);

        if (!$this->readStream->isReadable() || !$this->writStream->isWritable()) {
            Loop::futureTick(function () use ($stream) {
                $stream->emit('error', [new \RuntimeException('Stream is not writable or readable')]);
                $stream->close();
            });
            return $stream;
        }

        try {
            $serialized = is_string($closure) ? $closure : SerializableClosure::serialize($closure->bindTo(null, null));
            $uuid = Uuid::uuid4()->toString();
            $this->write([
                'cmd' => 'callback',
                'uuid' => $uuid,
                'data' => [
                    'serialized' => $serialized
                ]
            ]);

            $write->on('data', function ($data) use ($uuid) {
                $this->write(
                    [
                        'cmd' => 'data',
                        'uuid' => $uuid,
                        'data' => $data
                    ]
                );
            });

            $stream->on('error', function ($e) use ($stream, $uuid) {
                if ($this->existStream($stream)) {
                    $this->write([
                        'cmd' => 'error',
                        'uuid' => $uuid,
                        'data' => [
                            'message' => $e->getMessage(),
                            'code' => $e->getCode(),
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'trace' => $e->getTraceAsString(),
                        ]
                    ]);
                    $this->removeStream($stream);
                }
            });

            $write->on('end', function () use ($stream, $uuid) {
                if ($this->existStream($stream)) {
                    $this->write([
                        'cmd' => 'end',
                        'uuid' => $uuid,
                    ]);
                    $this->removeStream($stream);
                }
            });

            $stream->on('close', function () use ($stream, $uuid) {
                if ($this->existStream($stream)) {
                    $this->write([
                        'cmd' => 'close',
                        'uuid' => $uuid,
                    ]);
                    $this->removeStream($stream);
                }
            });

            $this->addStream($stream, $uuid);
        } catch (\Throwable $e) {
            Loop::futureTick(function () use ($stream, $e) {
                $stream->emit('error', [$e]);
                $stream->close();
            });
        }

        return $stream;
    }

    protected function addStream($stream, $uuid)
    {
        if ($this->streams->contains($stream)) {
            return;
        }
        $this->streams->attach($stream, [
            'uuid' => $uuid,
        ]);
        $this->uuidToStream[$uuid] = $stream;
    }

    protected function removeStream($stream)
    {
        if ($this->streams->contains($stream)) {
            $this->streams->detach($stream);
        }

        $index = array_search($stream, $this->uuidToStream, true);
        if ($index !== false) {
            unset($this->uuidToStream[$index]);
        }
    }

    public function existStream($stream)
    {
        return $this->streams->contains($stream);
    }

    protected function handleRead($data)
    {
        $messages = $this->decode($data);
        if ($messages) {
            foreach ($messages as $message) {
                if (!isset($message['cmd'])) {
                    continue;
                }
                $uuid = $message['uuid'];
                $cmd = $message['cmd'];

                if (in_array($cmd, ['data', 'end', 'close', 'error'])) {
                    if (isset($this->uuidToStream[$uuid])) {
                        $stream = $this->uuidToStream[$uuid];
                        if ($cmd === 'data') {
                            $stream->emit('data', [$message['data']]);
                        } elseif ($cmd === 'end') {
                            $this->removeStream($stream);
                            $stream->emit('end');
                            $stream->end();
                        } elseif ($cmd === 'close') {
                            $this->removeStream($stream);
                            $stream->emit('close');
                            $stream->end();
                        } elseif ($cmd === 'error') {
                            $this->removeStream($stream);
                            $stream->emit('error', [
                                new \Exception(
                                    $message['data']['message'],
                                    $message['data']['code'],
                                    null,
                                )
                            ]);
                            $stream->end();
                        }
                    }
                } else if ($cmd == 'ping') {
                    $this->write([
                        'cmd' => 'pong',
                        'uuid' => $uuid,
                    ]);
                } else if ($cmd == 'pong') {
                    if (isset($this->uuidToDeferred[$uuid])) {
                        $this->uuidToDeferred[$uuid]->resolve(true);
                    }
                } else if ($cmd === 'callback') {
                    if (!$this->canCallback) {
                        $this->write([
                            'cmd' => 'error',
                            'uuid' => $uuid,
                            'data' => [
                                'message' => 'callback not allowed',
                                'code' => 0,
                            ]
                        ]);
                        continue;
                    }
                    $read = new Stream\ThroughStream;
                    $write = new Stream\ThroughStream;
                    $stream = new Stream\CompositeStream($read, $write);
                    $this->addStream($stream, $uuid);
                    $write->on('data', function ($data) use ($uuid) {
                        $this->write(
                            [
                                'cmd' => 'data',
                                'uuid' => $uuid,
                                'data' => $data
                            ]
                        );
                    });

                    $write->on('end', function () use ($stream, $uuid) {
                        if ($this->existStream($stream)) {
                            $this->write([
                                'cmd' => 'end',
                                'uuid' => $uuid,
                            ]);
                            $this->removeStream($stream);
                        }
                    });

                    $stream->on('error', function ($e) use ($stream, $uuid) {
                        if ($this->existStream($stream)) {
                            $this->removeStream($stream);
                            $this->write([
                                'cmd' => 'error',
                                'uuid' => $uuid,
                                'data' => [
                                    'message' => $e->getMessage(),
                                    'code' => $e->getCode(),
                                    'file' => $e->getFile(),
                                    'line' => $e->getLine(),
                                    'trace' => $e->getTraceAsString(),
                                ]
                            ]);
                        }
                    });
                    $stream->on('close', function () use ($stream, $uuid) {
                        if ($this->existStream($stream)) {
                            $this->write([
                                'cmd' => 'close',
                                'uuid' => $uuid,
                            ]);
                            $this->removeStream($stream);
                        }
                    });

                    try {
                        $event = $message['data']['event'] ?? '';
                        if ($event) {
                            $this->emit($event, [$stream]);
                        }
                        $serialized = $message['data']['serialized'];
                        $closure = SerializableClosure::unserialize($serialized, static::$secretKey);
                        $r = $closure($stream);
                        if ($r instanceof \React\Promise\PromiseInterface) {
                            $r->then(function ($value) use ($stream) {
                                $stream->end($value);
                            }, function ($e) use ($stream) {
                                $stream->emit('error', [$e]);
                            });
                        } elseif ($r !== $stream) {
                            $stream->end($r);
                        }
                    } catch (\Throwable $e) {
                        $stream->emit('error', [$e]);
                    }
                }
            }
        }
    }

    protected function write($data)
    {
        $this->writStream->write($this->encode($data));
    }

    protected function encode($data)
    {
        return MessagePack::pack($data);
    }

    protected function decode($buffer)
    {
        $this->bufferUpacker->append($buffer);
        if ($messages = $this->bufferUpacker->tryUnpack()) {
            $this->bufferUpacker->release();
            return $messages;
        }
        return null;
    }


    public function ping($timeout = 3)
    {

        $uuid = Uuid::uuid4()->toString();
        $deferred = new \React\Promise\Deferred();
        $this->uuidToDeferred[$uuid] = $deferred;
        $this->write([
            'cmd' => 'ping',
            'uuid' => $uuid,
        ]);
        return \React\Promise\Timer\timeout($deferred->promise(), $timeout)
            ->then(function () use ($uuid) {
                return true;
            }, function ($error) use ($uuid) {
                unset($this->uuidToDeferred[$uuid]);
                throw $error;
            });
    }


    public function close()
    {
        foreach ($this->streams as $stream) {
            $stream->close();
        }

        $this->streams = new \SplObjectStorage;
        $this->uuidToStream = [];
    }
}
