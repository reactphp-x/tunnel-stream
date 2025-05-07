# Tunnel Stream

一个基于 ReactPHP 的流式隧道通信库，支持在不同进程间传输数据流和执行闭包函数。

## 特性

- 🚀 支持进程间双向数据流传输
- 🔄 支持序列化闭包函数在不同进程间执行
- 📦 基于 MessagePack 的高效二进制协议
- 💓 内置心跳检测机制
- ⚠️ 完整的错误处理和事件通知
- ⏱️ 支持异步 Promise 操作
- 🔒 支持闭包函数序列化安全控制

## 安装

```bash
composer require reactphp-x/tunnel-stream
```

## 快速开始

### 1. 创建隧道流

```php
use ReactphpX\TunnelStream\TunnelStream;
use React\Stream\ThroughStream;

// 创建读写流
$read = new ThroughStream();
$write = new ThroughStream();

// 初始化隧道流
$tunnelStream = new TunnelStream($read, $write);
```

### 2. 执行远程闭包

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

### 3. 心跳检测

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

## 进阶用法

### 子进程通信

在父子进程间建立隧道流通信是一个常见的使用场景。以下是一个完整的示例：

#### 主进程 (process.php)

```php
use ReactphpX\TunnelStream\TunnelStream;
use React\ChildProcess\Process;
use React\EventLoop\Loop;

// 创建子进程
$process = new Process(sprintf(
    'exec php %s/child_process_init.php',
    __DIR__
));

$process->start();

// 创建隧道流
$tunnelStream = new TunnelStream($process->stderr, $process->stdin);

// 监听子进程输出
$process->stdout->on('data', function ($data) {
    echo "STDOUT: " . $data . PHP_EOL;
});

$process->stderr->on('data', function ($data) {
    echo "STDERR: " . $data . PHP_EOL;
});

$process->on('exit', function ($exitCode) {
    echo "Process exited with code $exitCode\n";
});

// 执行文件读取操作
$fileStream = $tunnelStream->run(function () {
    return file_get_contents(__DIR__ . '/composer.json');
});

$fileStream->on('data', function ($data) {
    echo "File Stream: " . $data . PHP_EOL;
});

$fileStream->on('error', function ($error) {
    echo "File Stream Error: " . $error->getMessage() . PHP_EOL;
});

// 执行异步延迟操作
$promiseStream = $tunnelStream->run(function () {
    return \React\Promise\Timer\sleep(2)->then(function () {
        return 'Hello World';
    });
});

$promiseStream->on('data', function ($data) {
    echo "Promise Stream: " . $data . PHP_EOL;
});

// 持续数据流示例
$alwayStream = $tunnelStream->run(function ($stream) {
    $i = 0;
    $timer = Loop::addPeriodicTimer(1, function () use ($stream, &$i) {
        $stream->write('Hello World' . $i . PHP_EOL);
        $i++;
    });
    
    $stream->on('close', function () use ($timer) {
        Loop::cancelTimer($timer);
        echo "Always Stream Close\n";
    });
    
    return $stream;
});

$alwayStream->on('data', function ($data) {
    echo "Always Stream: " . $data . PHP_EOL;
});

// 5秒后关闭持续数据流
Loop::addTimer(5, function () use ($alwayStream) {
    $alwayStream->close();
});
```

#### 子进程 (child_process_init.php)

```php
<?php

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
} else {
    require __DIR__ . '/../../../autoload.php';
}

if (getenv('BOOT_FILE')) {
    require_once getenv('BOOT_FILE');
}

use ReactphpX\TunnelStream\TunnelStream;
use React\Stream\ReadableResourceStream;
use React\Stream\WritableResourceStream;

$tunnelStream = new TunnelStream(
    new ReadableResourceStream(STDIN), 
    new WritableResourceStream(STDERR), 
    true
);
```

## 最佳实践

### 1. 错误处理

- 始终监听 error 事件
- 在关键操作处添加错误处理逻辑
- 使用 try-catch 包装可能抛出异常的代码
- 实现优雅的错误恢复机制

### 2. 资源管理

- 及时关闭不再使用的流
- 使用 close 事件清理相关资源
- 避免内存泄漏
- 实现超时机制

### 3. 性能优化

- 合理使用缓冲区大小
- 避免过大的数据包
- 适时使用心跳检测保持连接
- 使用异步操作处理耗时任务

### 4. 安全考虑

- 控制闭包函数的执行权限
- 验证数据来源
- 限制资源使用
- 实现访问控制

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

参数说明：
- `$readStream`: 可读流接口
- `$writStream`: 可写流接口
- `$canCallback`: 是否允许执行回调函数

#### 方法

##### run

```php
public function run(callable $closure): Stream\DuplexStreamInterface
```

执行远程闭包函数，返回一个双向流。

##### ping

```php
public function ping(int $timeout = 3): PromiseInterface
```

发送心跳包并等待响应。

参数：
- `$timeout`: 超时时间（秒）

##### close

```php
public function close(): void
```

关闭所有流。

## 测试

运行测试套件：

```bash
./vendor/bin/phpunit tests
```

## 依赖

- react/stream: ^1.4
- laravel/serializable-closure: ^2.0
- ramsey/uuid: ^4.7
- rybakit/msgpack: ^0.9.1
- react/promise: ^3.2
- react/promise-timer: ^1.11

## 贡献

欢迎提交 Pull Request 和 Issue。

## 许可证

MIT License