# EPP Socket Connection
[![Latest Stable Version](https://img.shields.io/github/v/release/struzik-vladislav/epp-socket-connection?sort=semver&style=flat-square)](https://packagist.org/packages/struzik-vladislav/epp-socket-connection)
[![Total Downloads](https://img.shields.io/packagist/dt/struzik-vladislav/epp-socket-connection?style=flat-square)](https://packagist.org/packages/struzik-vladislav/epp-socket-connection/stats)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)
[![StandWithUkraine](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/badges/StandWithUkraine.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

Socket connection for communicating with EPP(Extensible Provisioning Protocol) servers.

Connection for [struzik-vladislav/epp-client](https://github.com/struzik-vladislav/epp-client) library.

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
