<?php

namespace Struzik\EPPClient\Connection;

use Struzik\EPPClient\Exception\ConnectionException;
use Struzik\ErrorHandler\ErrorHandler;
use Struzik\ErrorHandler\Processor\IntoExceptionProcessor;
use Struzik\ErrorHandler\Exception\ErrorException;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface;

/**
 * Connection to EPP server based on stream_socket_client.
 */
class StreamSocketConnection implements ConnectionInterface
{
    /**
     * Server connection settings.
     *
     * @var array
     */
    private $config;

    /**
     * Resource of connection to the server.
     *
     * @var resource
     */
    private $connection;

    /**
     * Buffer for stream reading.
     *
     * @var string
     */
    private $buffer;

    /**
     * Creating connection object to the EPP server.
     *
     * @param array $config
     *                      'uri' - (string) EPP server URI
     *                      'timeout' - (int) Connection timeout in seconds
     *                      'context' - (array) Value of $options parameter in stream_context_create(). See: https://secure.php.net/manual/en/function.stream-context-create.php
     *
     * @throws ConnectionException
     */
    public function __construct(array $config = [])
    {
        try {
            $resolver = new OptionsResolver();
            $resolver->setRequired('uri');
            $resolver->setRequired('timeout');
            $resolver->setRequired('context');
            $resolver->setDefault('context', []);
            $resolver->setAllowedTypes('uri', 'string');
            $resolver->setAllowedTypes('timeout', 'int');
            $resolver->setAllowedTypes('context', 'array');

            $this->config = $resolver->resolve($config);
        } catch (ExceptionInterface $e) {
            throw new ConnectionException('Invalid configuration parameters. See previous exception.', 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws ConnectionException
     */
    public function open()
    {
        try {
            // Setting up error handler
            $errorHandler = (new ErrorHandler())
                ->pushProcessor((new IntoExceptionProcessor())->setErrorTypes(E_ALL));
            $errorHandler->set();

            // Trying to open connection
            $context = stream_context_create($this->config['context']);
            $this->connection = stream_socket_client($this->config['uri'], $errno, $errstr, $this->config['timeout'], STREAM_CLIENT_CONNECT, $context);
            stream_set_timeout($this->connection, $this->config['timeout']);

            // Restore previous error handler
            $errorHandler->restore();

            return $this->connection;
        } catch (ErrorException $e) {
            $this->connection = null;
            throw new ConnectionException('Can not open connection to the EPP server. See previous exception.', 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isOpened()
    {
        return is_resource($this->connection);
    }

    /**
     * {@inheritdoc}
     */
    public function close()
    {
        if ($this->isOpened()) {
            fclose($this->connection);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return string
     *
     * @throws ConnectionException
     */
    public function read()
    {
        // Checking open connection
        if (!$this->isOpened()) {
            throw new ConnectionException('You tried to read from an closed connection.');
        }

        try {
            // Setting up error handler
            $errorHandler = (new ErrorHandler())
                ->pushProcessor((new IntoExceptionProcessor())->setErrorTypes(E_ALL));
            $errorHandler->set();

            // Trying to read a response
            $this->buffer = '';
            $length = $this->readResponseLength();
            if ($length) {
                $this->buffer .= fread($this->connection, $length);
            }

            // Restore previous error handler
            $errorHandler->restore();

            return $this->buffer;
        } catch (ErrorException $e) {
            throw new ConnectionException('An error occurred while trying to read the response. See previous exception.', 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws ConnectionException
     */
    public function write($xml)
    {
        try {
            // Setting up error handler
            $errorHandler = (new ErrorHandler())
                ->pushProcessor((new IntoExceptionProcessor())->setErrorTypes(E_ALL));
            $errorHandler->set();

            // Trying to send a request
            $command = $this->prependHeader($xml);
            fwrite($this->connection, $command);

            // Restore previous error handler
            $errorHandler->restore();
        } catch (ErrorException $e) {
            throw new ConnectionException('An error occurred while trying to send the request. See previous exception.', 0, $e);
        }
    }

    /**
     * Preparing the request for sending to the EPP server. Adds a header to the command text.
     *
     * @param string $xml RAW-request without header
     *
     * @return string
     */
    protected function prependHeader($xml)
    {
        $header = pack('N', strlen($xml) + self::HEADER_LENGTH);

        return $header.$xml;
    }

    /**
     * Returns the length of the response (without header) in bytes.
     *
     * @return int
     */
    protected function readResponseLength()
    {
        // Executing several attempt for reading
        $rawHeader = '';
        for ($i = 0; (strlen($rawHeader) < self::HEADER_LENGTH) && ($i < 10); ++$i) {
            $rawHeader .= fread($this->connection, self::HEADER_LENGTH - strlen($rawHeader));
            usleep($i * 100000); // 100000 = 1/10 seconds
        }

        // Unpack header from binary string
        $unpackedHeader = unpack('N', $rawHeader);
        $length = $unpackedHeader[1] - self::HEADER_LENGTH;

        return $length;
    }
}
