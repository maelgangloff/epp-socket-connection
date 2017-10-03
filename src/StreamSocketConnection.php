<?php

namespace Struzik\EPPClient\Connection;

use Struzik\EPPClient\Exception\ConnectionException;
use Struzik\ErrorHandler\ErrorHandler;
use Struzik\ErrorHandler\Processor\IntoExceptionProcessor;
use Struzik\ErrorHandler\Exception\ErrorException;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface;
use Psr\Log\LoggerInterface;

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
     * Logger object.
     *
     * @var LoggerInterface
     */
    private $logger;

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
     * @param array           $config
     *                                'uri' - (string) EPP server URI
     *                                'timeout' - (int) Number of seconds until the connect() system call should timeout.
     *                                'context' - (array) Value of $options parameter in stream_context_create(). See: https://secure.php.net/manual/en/function.stream-context-create.php
     * @param LoggerInterface $logger PSR-3 compatible logger
     *
     * @throws ConnectionException
     */
    public function __construct(array $config = [], LoggerInterface $logger)
    {
        try {
            $this->logger = $logger;

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

            // Restore previous error handler
            $errorHandler->restore();
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
        return is_resource($this->connection) && !feof($this->connection);
    }

    /**
     * {@inheritdoc}
     */
    public function close()
    {
        if (!$this->isOpened()) {
            return;
        }

        if (fclose($this->connection) === false) {
            throw new ConnectionException('An error occured while closing the connection.');
        }

        $this->connection = null;
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
            $beginTime = microtime(true);
            $this->buffer = '';
            $length = $this->readResponseLength();
            $this->logger->debug(sprintf('The length of the response body is %s bytes.', $length));
            if ($length) {
                for ($i = 0; (strlen($this->buffer) < $length) && ($i < 25); ++$i) {
                    usleep($i * 100000); // 100000 = 1/10 seconds
                    $residualLength = $length - strlen($this->buffer);
                    $this->logger->debug(sprintf('Trying to read %s bytes of the response body.', $residualLength), ['iteration-number' => $i]);
                    $this->buffer .= fread($this->connection, $residualLength);
                }
            }
            $endTime = microtime(true);
            $this->logger->debug(sprintf('The response time is %s seconds.', round($endTime - $beginTime, 3)));

            // Checking lengths of the response body
            if ($length !== strlen($this->buffer)) {
                throw new ConnectionException('The number of bytes of a response body is not equal to the number of bytes from header.');
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
        // Checking open connection
        if (!$this->isOpened()) {
            throw new ConnectionException('You tried to write to a closed connection.');
        }

        try {
            // Setting up error handler
            $errorHandler = (new ErrorHandler())
                ->pushProcessor((new IntoExceptionProcessor())->setErrorTypes(E_ALL));
            $errorHandler->set();

            // Trying to send a request
            $command = $this->prependHeader($xml);
            $commandLength = strlen($command);
            $this->logger->debug(sprintf('Number of bytes of a command: %s', $commandLength));
            $writtenLength = fwrite($this->connection, $command);
            $this->logger->debug(sprintf('Number of bytes written to the connection: %s', $writtenLength));

            // Checking lengths of the request
            if ($commandLength !== $writtenLength) {
                throw new ConnectionException('The number of bytes of a command is not equal to the number of bytes written to the connection.');
            }

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
        for ($i = 0; (strlen($rawHeader) < self::HEADER_LENGTH) && ($i < 25); ++$i) {
            usleep($i * 100000); // 100000 = 1/10 seconds
            $residualLength = self::HEADER_LENGTH - strlen($rawHeader);
            $this->logger->debug(sprintf('Trying to read %s bytes of the response header.', $residualLength), ['iteration-number' => $i]);
            $rawHeader .= fread($this->connection, $residualLength);
        }

        // Unpack header from binary string
        $this->logger->debug(sprintf('Number of bytes of a response header: %s', strlen($rawHeader)));
        $unpackedHeader = unpack('N', $rawHeader);
        $length = $unpackedHeader[1] - self::HEADER_LENGTH;

        return $length;
    }
}
