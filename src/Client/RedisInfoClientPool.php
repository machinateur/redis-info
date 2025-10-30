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

class RedisInfoClientPool extends EventEmitter implements ProcessInterface
{
    public const EVENT = RedisInfoClient::EVENT;

    /**
     * @var RedisInfoClient[]
     */
    private array $pool = [];

    /**
     * @param RedisInfoServer[] $config
     */
    public function __construct(
        array $config = [],
    ) {
        \array_walk($config, $this->addServer(...));
    }

    public function addServer(RedisInfoServer $server): RedisInfoClient
    {
        $this->pool[] = $client = new RedisInfoClient($server);

        foreach (RedisInfoClient::EVENT as $event) {
            $client->on($event, function () use ($event, $client) {
                $this->emit($event, [$client, ...\func_get_args()]);
            });
        }

        return $client;
    }

    public function start(): void
    {
        foreach ($this->pool as $client) {
            $client->start();
        }
    }

    public function stop(): void
    {
        foreach ($this->pool as $client) {
            $client->stop();
        }
    }

    /**
     * @return RedisInfoClient[]
     */
    public function getPool(): array
    {
        return $this->pool;
    }

    /**
     * @return RedisInfoResult[]
     */
    public function getPoolStatus(): array
    {
        return \array_map(
            static fn(RedisInfoClient $client): RedisInfoResult => $client->getStatus(),
            $this->pool,
        );
    }
}
