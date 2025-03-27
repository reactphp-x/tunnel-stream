# Tunnel Stream

一个基于 ReactPHP 的流式隧道通信库，支持在不同进程间传输数据流和执行闭包函数。

## 特性

- 支持进程间双向数据流传输
- 支持序列化闭包函数在不同进程间执行
- 基于 MessagePack 的高效二进制协议
- 内置心跳检测机制
- 完整的错误处理和事件通知
- 支持异步 Promise 操作

## 安装

```bash
composer require reactphp-x/tunnel-stream -vvv
```

## 基本用法

### 创建隧道流

```php
use ReactphpX\TunnelStream\TunnelStream;
use React\Stream\ThroughStream;

// 创建读写流
$read = new ThroughStream();
$write = new ThroughStream();

// 初始化隧道流
$tunnelStream = new TunnelStream($read, $write);
```

### 执行远程闭包

```php
// 在远程进程执行闭包函数
$stream = $tunnelStream->run(function () {
    return file_get_contents('example.txt');
});

// 处理返回的数据流
$stream->on('data', function ($data) {
    echo $data;
});

$stream->on('end', function () {
    echo "Stream ended\n";
});

$stream->on('error', function (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
});
```

### 心跳检测

```php
$tunnelStream->ping(3)->then(
    function () {
        echo "Ping successful\n";
    },
    function (\Exception $e) {
        echo "Ping failed: " . $e->getMessage() . "\n";
    }
);
```

## 进阶示例

### 子进程通信

在父子进程间建立隧道流通信是一个常见的使用场景。以下是一个完整的示例：

#### 主进程 (process.php)

```php
use ReactphpX\TunnelStream\TunnelStream;
use React\ChildProcess\Process;
use React\Stream\ThroughStream;

// 创建读写流
$read = new ThroughStream();
$write = new ThroughStream();

// 初始化隧道流
$tunnelStream = new TunnelStream($read, $write);

// 创建子进程
$process = new Process(sprintf(
    'exec php %s/child_process_init.php',
    __DIR__
));

$process->start();

// 处理子进程输出
$process->stdout->on('data', function ($data) use($read) {
    $read->write($data);
});

$process->stderr->on('data', function ($data) {
    echo "Error: " . $data;
});

// 向子进程发送数据
$write->on('data', function ($data) use($process) {
    $process->stdin->write($data);
});

// 执行远程文件操作
$fileStream = $tunnelStream->run(function () {
    return file_get_contents('example.txt');
});

$fileStream->on('data', function ($data) {
    echo "Received file content: " . $data;
});

// 执行延迟操作
$promiseStream = $tunnelStream->run(function () {
    return \React\Promise\Timer\sleep(2)->then(function () {
        return 'Delayed response';
    });
});

$promiseStream->on('data', function ($data) {
    echo "Received delayed data: " . $data;
});
```

#### 子进程 (child_process_init.php)

```php
use React\EventLoop\Loop;
use ReactphpX\TunnelStream\TunnelStream;
use React\Stream\ThroughStream;

// 创建读写流
$read = new ThroughStream();
$write = new ThroughStream();

// 初始化子进程隧道流
$tunnelStream = new TunnelStream($read, $write, true);

// 处理输出
$write->on('data', function ($buffer) {
    fwrite(STDOUT, $buffer);
});

// 处理输入
Loop::addReadStream(STDIN, function ($stream) use ($read) {
    while($buffer = fread($stream, 8192)) {
        $read->write($buffer);
    }
});
```

这个示例展示了：

- 如何在父子进程间建立双向通信
- 如何在子进程中执行文件操作
- 如何处理异步 Promise 操作
- 如何处理错误和异常

完整的示例代码可以在 `examples` 目录下找到：
- `process.php`: 主进程示例
- `child_process_init.php`: 子进程示例

## API 文档

### TunnelStream 类

#### 构造函数

```php
public function __construct(
    Stream\ReadableStreamInterface $readStream,
    Stream\WritableStreamInterface $writStream,
    bool $canCallback = false
)
```

#### 方法

- `run(callable $closure): Stream\DuplexStreamInterface`
  执行远程闭包函数

- `ping(int $timeout = 3): PromiseInterface`
  发送心跳包并等待响应

- `close(): void`
  关闭所有流

## 依赖

- react/stream: ^1.4
- laravel/serializable-closure: ^2.0
- ramsey/uuid: ^4.7
- rybakit/msgpack: ^0.9.1
- react/promise: ^3.2
- react/promise-timer: ^1.11

## 许可证

MIT License