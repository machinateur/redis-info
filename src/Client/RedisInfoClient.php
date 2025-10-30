<?php
/*
 * MIT License
 *
 * Copyright (c) 2025 machinateur
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

declare(strict_types=1);

namespace Machinateur\RedisInfo\Client;

use Evenement\EventEmitter;
use Machinateur\RedisInfo\Model\RedisInfoResult;
use Machinateur\RedisInfo\Model\RedisInfoServer;
use Machinateur\RedisInfo\ProcessInterface;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use React\Socket\Connection;
use React\Socket\ConnectionInterface;
use ReactLineStream\LineStream;

class RedisInfoClient extends EventEmitter implements ProcessInterface
{
    public const EVENT = ['start', 'stop', 'error', 'info', 'auth'];
    public const EOL   = "\r\n";

    private RedisInfoResult        $status;
    private array                  $section;

    private ?TimerInterface        $timer      = null;
    private ?ConnectionInterface   $connection = null;
    private ?LineStream            $lineReader = null;

    public function __construct(
        public readonly RedisInfoServer $server,
        string|array|null               $section = null
    ) {
        $this->setStatus();
        $this->section = $section ?? [];

        $this->on('info', $this->setStatus(...));
        $this->on('error', $this->setStatusError(...));
    }

    public function start(): void
    {
        if (null !== $this->timer) {
            return;
        }

        $this->emit('start');

        $this->updateStatus();
        $this->timer = Loop::addPeriodicTimer($this->server->interval, $this->updateStatus(...));
    }

    public function stop(): void
    {
        Loop::cancelTimer($this->timer);
        $this->timer = null;

        $this->emit('stop');
    }

    public function & getStatus(): RedisInfoResult
    {
        return $this->status;
    }

    protected function updateStatus(): void
    {
        try {
            $this->ensureConnection();
        } catch (\RuntimeException $exception) {
            $this->setStatusError($exception);

            return;
        }

        if (null === $this->connection) {
            return;
        }

        $maxLength    = 0;
        $buffer       = '';
        $handleBuffer = function (string $line) use ( & $maxLength, & $buffer, & $handleBuffer): void {
            if (empty($buffer)) {
                $kind = \substr($line, 0, 1);
                $data = \trim(\substr($line, 1));
                $stat = '$' === $kind;

                if ( ! $stat) {
                    $this->emit('error', [
                        new \UnexpectedValueException('No bulk response for INFO command.'),
                    ]);

                    return;
                }

                $buffer    .= $line;
                $maxLength  = (int)$data;
#\var_dump($maxLength);
                return;
            }

            $buffer .= $line;

            if (\strlen($buffer) < $maxLength) {
                return;
            }
            unset($line);

#\var_dump($buffer);
            $data    = \explode(self::EOL, $buffer);
#\var_dump($data);
            $info    = [];
            foreach ($data as $index => $line) {
                if (empty($line) || 0 === $index || '#' === $line[0]) {
                    continue;
                }

                [$key, $value] = \explode(':', $line, 2);
                $info[$key]    = $value;
            }

            $this->emit('info', [
                $info,
            ]);

            $this->lineReader->removeListener('line', $handleBuffer);
        };

        $this->lineReader->on('line', $handleBuffer);

        if ( ! empty($this->section)) {
            // Note: Multiple sections are only supported in Redis 7+.
            $this->connection->write('INFO ' . \implode(' ', $this->section) . self::EOL);
        } else {
            $this->connection->write('INFO' . self::EOL);
        }
    }

    /**
     * @throws \RuntimeException when the connection fails
     */
    protected function ensureConnection(float $timeout = 5.0): void
    {
        if (null !== $this->connection) {
            if ($this->connection->isReadable()
                && $this->connection->isWritable()
            ) {
                return;
            }

            $this->connection->close();
        }

        $this->connection = null;
        $this->lineReader = null;

        $connection       = @\stream_socket_client(\sprintf('tcp://%s:%s', $this->server->host, $this->server->port), $errno, $error, $timeout);
        if ( ! $connection) {
            throw new \RuntimeException($error, $errno);
        }


        $this->connection = new Connection($connection, Loop::get());
        $this->lineReader = new LineStream($this->connection, self::EOL);

        if ( ! $this->server->hasAuth()) {
            return;
        }

        // TODO: Test.
        $this->lineReader->once('line', function (string $line): void {
            $kind = \substr($line, 0, 1);
            $data = \trim(\substr($line, 1));
            $stat = '+' === $kind
                && 'OK' === $data;

            $this->emit('auth', [
                $stat,
            ]);

            if ( ! $stat) {
                $this->stop();
            }
        });

        $this->connection->write('AUTH ' . $this->server->getAuth() . self::EOL);
    }

    /**
     * @param array<string, mixed> $info
     */
    protected function setStatus(array $info = []): void
    {
        $this->status  = new RedisInfoResult($info);

        #echo 'Debug:   ',
        #    "[{$this->server->label}] update status",
        #    #\print_r($this->status, true),
        #\PHP_EOL;
    }

    protected function setStatusError(\Throwable $error): void
    {
        $this->setStatus([
            'error'    => $error->getMessage(),
        ]);
    }
}
