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

namespace Machinateur\RedisInfo\History;

use Machinateur\RedisInfo\ProcessInterface;

class Database implements ProcessInterface, StorageInterface
{
    public const DATETIME_STORAGE_FORMAT = 'Y-m-d H:i:s';

    private readonly \SQLite3 $database;

    public function __construct(
        private readonly string $path,
    ) {
        if ( ! \file_exists($path)) {
            throw new \InvalidArgumentException(\sprintf('Database file "%s" not found', $path));
        }

        if (\pathinfo($path, \PATHINFO_EXTENSION) !== $extension = 'sqlite') {
            throw new \InvalidArgumentException(\sprintf('Database file "%s" is not a .%s file', $path, $extension));
        }

        $this->database = new \SQLite3(':memory:');
        $this->database->close();
    }

    public function start(): void
    {
        $this->database->open($this->path);

        $this->ensureTable();
    }

    public function stop(): void
    {
        $this->database->close();
    }

    protected function ensureTable(): void
    {
        $query = <<<'SQL'
create table if not exists redis_info_history
(
    id        integer
        constraint redis_info_history_id
            primary key autoincrement,
    server_id text                       not null,
    timestamp text                       not null,
    status    text                       not null,

    check ( timestamp is strftime('%Y-%m-%d %H:%M:%S', timestamp) )
)
SQL;

        $this->database->exec($query);
    }

    /**
     * @return \Generator<array>
     */
    public function load(int $interval, ?string $serverId = null): iterable
    {
        $query = <<<'SQL'
select id,
       server_id as serverId,
       timestamp,
       status
  from redis_info_history
 where timestamp >= :timestamp
   and (null is :serverId
    or server_id  = :serverId)
SQL;

        $timestamp = \date_create_immutable('@' . \max(0, \time() - $interval));

        $stmt  = $this->database->prepare($query);
        $stmt->bindValue('timestamp', $timestamp->format(self::DATETIME_STORAGE_FORMAT));
        $stmt->bindValue('serverId',  $serverId);

        $result = $stmt->execute();
        while ($row = $result->fetchArray()) {
            $row['id']        = (int)$row['id'];
            $row['serverId']  = (string)$row['serverId'];
            $row['timestamp'] = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $row['timestamp']);
            $row['status']    = \json_decode($row['status'], true, flags: \JSON_THROW_ON_ERROR);

            yield $row;
        }
        $stmt->close();
    }

    public function save(string $serverId, array $info): void
    {
        $query = <<<'SQL'
insert into redis_info_history(server_id, timestamp, status)

values (:serverId, :timestamp, :status)
SQL;

        $timestamp = \date_create_immutable('@' . \time());

        $stmt = $this->database->prepare($query);
        $stmt->bindValue('serverId',  $serverId);
        $stmt->bindValue('timestamp', $timestamp->format(self::DATETIME_STORAGE_FORMAT));

        $json = \json_encode($info, flags: \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION | \JSON_FORCE_OBJECT);
        $stmt->bindValue('status',    $json);

        $stmt->execute();
        $stmt->close();
    }

    public function getPath(): string
    {
        return $this->path;
    }
}
