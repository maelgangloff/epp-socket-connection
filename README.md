# EPP Socket Connection
Socket connection for communicating with EPP(Extensible Provisioning Protocol) servers.

## Usage

```php
<?php

use Psr\Log\NullLogger;
use Struzik\EPPClient\SocketConnection\StreamSocketConfig;
use Struzik\EPPClient\SocketConnection\StreamSocketConnection;

$connectionConfig = new StreamSocketConfig();
$connectionConfig->uri = 'tls://epp.example.com:700';
$connectionConfig->timeout = 30;
$connectionConfig->context = [
    'ssl' => [
        'local_cert' => __DIR__.'/certificate.pem',
    ],
];
$connection = new StreamSocketConnection($connectionConfig, new NullLogger());

$connection->open();
echo $connection->read();
$connection->close();
```
