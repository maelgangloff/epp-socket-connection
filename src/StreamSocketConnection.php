<?php

namespace Struzik\EPPClient\SocketConnection;

use Psr\Log\LoggerInterface;
use Struzik\EPPClient\Connection\ConnectionInterface;
use Struzik\EPPClient\Exception\ConnectionException;
use Struzik\EPPClient\Response\Session\GreetingResponse;
use Struzik\ErrorHandler\ErrorHandler;
use Struzik\ErrorHandler\Exception\ErrorException;
use Struzik\ErrorHandler\Processor\IntoExceptionProcessor;

/**
 * Connection to EPP server based on stream_socket_client.
 */
class StreamSocketConnection implements ConnectionInterface
{
    /**
     * Server connection settings.
     */
    private StreamSocketConfig $config;

    /**
     * Logger object.
     */
    private LoggerInterface $logger;

    /**
     * Resource of connection to the server.
     *
     * @var resource
     */
    private $connection;

    /**
     * Creating connection object to the EPP server.
     *
     * @param StreamSocketConfig $config connection settings
     * @param LoggerInterface $logger PSR-3 compatible logger
     */
    public function __construct(StreamSocketConfig $config, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     *
     * @throws ConnectionException
     */
    public function open(): void
    {
        try {
            // Setting up error handler
            $errorHandler = (new ErrorHandler())
                ->pushProcessor((new IntoExceptionProcessor())->setErrorTypes(E_ALL));
            $errorHandler->set();

            // Trying to open connection
            $context = stream_context_create($this->config->context);
            $this->connection = stream_socket_client($this->config->uri, $errno, $errstr, $this->config->timeout, STREAM_CLIENT_CONNECT, $context);
            stream_set_timeout($this->connection, $this->config->timeout);

            // Read greeting and check it
            $greeting = new GreetingResponse($this->read());
            if (!$greeting->isSuccess()) {
                throw new ConnectionException('Invalid greeting content. Node <greeting> not found. See log for details.');
            }

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
    public function isOpened(): bool
    {
        return is_resource($this->connection) && !feof($this->connection);
    }

    /**
     * {@inheritdoc}
     */
    public function close(): void
    {
        if ($this->isOpened() && fclose($this->connection) === false) {
            throw new ConnectionException('An error occurred while closing the connection.');
        }

        $this->connection = null;
    }

    /**
     * {@inheritdoc}
     *
     * @throws ConnectionException
     */
    public function read(): string
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
            $readBuffer = '';
            $length = $this->readResponseLength();
            $this->logger->debug(sprintf('The length of the response body is %s bytes.', $length));
            if ($length) {
                for ($i = 0; (strlen($readBuffer) < $length) && ($i < 25); ++$i) {
                    usleep($i * 100000); // 100000 = 1/10 seconds
                    $residualLength = $length - strlen($readBuffer);
                    $this->logger->debug(sprintf('Trying to read %s bytes of the response body.', $residualLength), ['iteration-number' => $i]);
                    $readBuffer .= fread($this->connection, $residualLength);
                }
            }
            $endTime = microtime(true);
            $usedTime = round($endTime - $beginTime, 3);
            $this->logger->debug(sprintf('The response time is %s seconds.', $usedTime));

            // Checking lengths of the response body
            if ($length !== strlen($readBuffer)) {
                throw new ConnectionException('The number of bytes of the response body is not equal to the number of bytes from header.');
            }

            // Restore previous error handler
            $errorHandler->restore();

            $this->logger->info('The data read from the EPP connection', ['body' => $readBuffer, 'time' => $usedTime]);

            return $readBuffer;
        } catch (ErrorException $e) {
            throw new ConnectionException('An error occurred while trying to read the response. See previous exception.', 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws ConnectionException
     */
    public function write(string $xml): void
    {
        $this->logger->info('The data written to the EPP connection', ['body' => $xml]);

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
            $this->logger->debug(sprintf('Number of bytes of the request: %s', $commandLength));
            $writtenLength = fwrite($this->connection, $command);
            $this->logger->debug(sprintf('Number of bytes written to the connection: %s', $writtenLength));

            // Checking lengths of the request
            if ($commandLength !== $writtenLength) {
                throw new ConnectionException('The number of bytes of the request is not equal to the number of bytes written to the connection.');
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
     */
    protected function prependHeader(string $xml): string
    {
        $header = pack('N', strlen($xml) + self::HEADER_LENGTH);

        return $header . $xml;
    }

    /**
     * Returns the length of the response (without header) in bytes.
     */
    protected function readResponseLength(): int
    {
        // Executing several attempt for reading
        $rawHeader = '';
        $readTimeout = time() + $this->config->timeout;
        for ($i = 0; $this->isOpened() && (time() < $readTimeout) && (strlen($rawHeader) < self::HEADER_LENGTH); ++$i) {
            usleep($i * 100000); // 100000 = 1/10 seconds
            $residualLength = self::HEADER_LENGTH - strlen($rawHeader);
            $this->logger->debug(sprintf('Trying to read %s bytes of the response header.', $residualLength), ['iteration-number' => $i]);
            $rawHeader .= fread($this->connection, $residualLength);
        }

        // Unpack header from binary string
        $this->logger->debug(sprintf('Number of bytes of the response header: %s', strlen($rawHeader)));
        $unpackedHeader = unpack('N', $rawHeader);

        return $unpackedHeader[1] - self::HEADER_LENGTH;
    }
}
