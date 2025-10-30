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

final class RedisInfoServer implements \JsonSerializable
{
    use JsonSerializableTrait;

    public readonly string $id;

    public function __construct(
        public readonly string  $label,
        public readonly float   $interval,
        public readonly string  $host,
        public readonly int     $port,
        #[\SensitiveParameter()]
        public readonly ?string $username,
        #[\SensitiveParameter()]
        public readonly ?string $password,
        ?string                 $serverId = null,
    ) {
        $this->id = $serverId ?? \hash('md5', $this->label);
    }

    public function hasAuth(): bool
    {
        return ! empty($this->username)
            || ! empty($this->password);
    }

    public function getAuth(): string
    {
        $auth = '';

        if ( ! empty($this->username)) {
            $auth .= $this->username;
        }

        if ( ! empty($this->password)) {
            if ( ! empty($this->username)) {
                $auth .= ' ';
            }

            $auth .= $this->password;
        }

        return $auth;
    }
}
