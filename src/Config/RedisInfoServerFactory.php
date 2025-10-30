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

namespace Machinateur\RedisInfo\Config;

use Machinateur\RedisInfo\Model\RedisInfoServer;

class RedisInfoServerFactory
{
    public const DEFAULT = [
        'label'    => null,
        'interval' => 5.0,
        'host'     => 'localhost',
        'port'     => 6379,
        'username' => null,
        'password' => null,
        //'serverId' => null,
    ];

    public const DEFAULT_PATH = 'redis-info.ini';

    private int $count = 0;

    public function fromArray(array $config): RedisInfoServer
    {
        $label     = $config['label'] ?? 'Redis Server ' . ++$this->count;
        $auth      = (isset($config['auth']) && \strlen($config['auth']) > 0)
            ? \explode(' ', $config['auth'], 2)
            : [];
        $auth[0] ??= null;
        $auth[1] ??= null;

        $config['label']       = $label;
        [$username, $password] = $auth;
        $config['username']    = $username ?? $config['username'] ?? null;
        $config['password']    = $password ?? $config['password'] ?? null;

        $config    = \array_replace(self::DEFAULT, $config);

        try {
            return new RedisInfoServer(...$config);
        } catch (\Error $error) {
            // TODO: Handle explicitly.
            throw new \RuntimeException(previous: $error);
        }
    }

    public function fromIniFile(string $path): array
    {
        if ( ! \file_exists($path)) {
            throw new \InvalidArgumentException(\sprintf('Configuration file "%s" not found', $path));
        }

        if (\pathinfo($path, \PATHINFO_EXTENSION) !== $extension = 'ini') {
            throw new \InvalidArgumentException(\sprintf('Configuration file "%s" is not a .%s file', $path, $extension));
        }

        $config = \parse_ini_file($path, true, \INI_SCANNER_TYPED);
        $server = [];

        if (false === $config) {
            // TODO: Exception
            throw new \InvalidArgumentException(\sprintf('Configuration file "%s" not readable', $path));
        }

        foreach ($config as $label => $value) {
            try {
                $server[$label] = $this->fromArray($value);
            } catch (\Error) {
            }
        }

        return $server;
    }
}
