# EPP Socket Connection
Socket connection for communicating with EPP(Extensible Provisioning Protocol) servers.

## Usage

```php
<?php

use Struzik\EPPClient\Connection\StreamSocketConnection;
use Psr\Log\NullLogger;

require_once __DIR__.'/vendor/autoload.php';

$connection = new StreamSocketConnection(
    [
        'uri' => 'tls://epp.example.com:700',
        'timeout' => 30,
        'context' => [
            'ssl' => [
                'local_cert' => __DIR__.'/certificate.pem',
            ],
        ],
    ],
    new NullLogger()
);

$connection->open();
echo $connection->read();
$connection->close();
```
