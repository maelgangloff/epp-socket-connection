<?php

namespace Struzik\EPPClient\SocketConnection;

class StreamSocketConfig
{
    /**
     * EPP server URI.
     */
    public string $uri = '';

    /**
     * Number of seconds until the connect() system call should timeout.
     */
    public int $timeout = 30;

    /**
     * Value of $options parameter in stream_context_create(). See: https://secure.php.net/manual/en/function.stream-context-create.php.
     */
    public array $context = [];
}
