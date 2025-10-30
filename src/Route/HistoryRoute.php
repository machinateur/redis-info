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

namespace Machinateur\RedisInfo\Route;

use Machinateur\RedisInfo\History\Database;
use Machinateur\RedisInfo\Model\History;
use Machinateur\RedisInfo\Model\HistorySnapshot;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

class HistoryRoute implements RouteInterface
{
    public const DEFAULT_INTERVAL = 60 * 15;

    public const INTERVAL_MAX = 60 * 60;
    public const INTERVAL_MIN = 60;

    public function __construct(
        private readonly Database $database,
        private readonly int      $interval = self::DEFAULT_INTERVAL,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $interval = $request->getQueryParams()['interval']  ?? $this->interval;
        $interval = \max(self::INTERVAL_MAX, \min((int)$interval, self::INTERVAL_MIN));
        $serverId = $request->getQueryParams()['serverId'] ?? null;

        // Only allow history for one instance at a time (for now).
        if ( ! \is_string($serverId)) {
            $error = [
                'message' => 'No serverId provided',
            ];
            return Response::json($error)
                ->withStatus(400);
        }

        return Response::json(
            $this->getStatusHistory($interval, $serverId)
        );
    }

    public function getStatusHistory(int $interval, ?string $serverId): History
    {
        $history = [];
        foreach ($this->database->load($interval, $serverId) as $row) {
            try {
                $history[] = new HistorySnapshot(...$row);
            } catch (\Error $error) {
                // TODO: Handle explicitly.
                throw new \RuntimeException(previous: $error);

                continue;
            }
        }

        return new History($history);
    }
}
