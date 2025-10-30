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

namespace Machinateur\RedisInfo\Model;

#[\AllowDynamicProperties()]
final class RedisInfoResult implements \JsonSerializable
{
    public const DATABASE_PATTERN = '/keys=(?<keys>\d+),expires=(?<expires>\d+)/';

    public array $database = [];

    private array $data = [];
    /*
        'redis_version',
        'redis_git_sha1',
        'redis_git_dirty',
        'arch_bits',
        'multiplexing_api',
        'process_id',
        'uptime_in_seconds',
        'uptime_in_days',
        'connected_clients',
        'connected_slaves',
        'blocked_clients',
        'used_memory',
        'used_memory_human',
        'changes_since_last_save',
        'bgsave_in_progress',
        'last_save_time',
        'bgrewriteaof_in_progress',
        'total_connections_received',
        'total_commands_processed',
        'expired_keys',
        'hash_max_zipmap_entries',
        'hash_max_zipmap_value',
        'pubsub_channels',
        'pubsub_patterns',
        'vm_enabled',
        'role',
     */

    public function __construct(
        array $info = [],
    ) {
        foreach ($info as $key => $value) {
            if (0 === \strpos($key, 'db')) {
                // Parse `db0:keys=17741,expires=75,avg_ttl=31403559156782,subexpiry=0`.
                $this->database[$key] = new RedisInfoResultDatabase($key, ...$this->parseDatabaseValue($value));
            }

            $this->$key = $value;
        }
    }

    protected function parseDatabaseValue(string $value): array
    {
        \preg_match(self::DATABASE_PATTERN, $value, $match, \PREG_UNMATCHED_AS_NULL);

        return [$match['keys'], $match['expires']];
    }

    public function __get(string $name): mixed
    {
        // 'database'
        if (\property_exists($this, $name)) {
            return $this->{$name} ?? null;
        }

        return $this->data[$name] ?? null;
    }

    public function __set(string $name, mixed $value): void
    {
        if (\property_exists($this, $name)) {
            $this->{$name} = $value;
        }

        $this->data[$name] = $value;
    }

    public function jsonSerialize(): array
    {
        $data = $this->data;
        foreach ($this->database as $key => $database) {
            $data['database'][$key] = $this->database;
        }

        return $data;
    }
}
