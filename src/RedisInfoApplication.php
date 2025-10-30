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

namespace Machinateur\RedisInfo;

use League\Route\Router;
use Machinateur\RedisInfo\Client\RedisInfoClient;
use Machinateur\RedisInfo\Client\RedisInfoClientPool;
use Machinateur\RedisInfo\Config\RedisInfoServerFactory;
use Machinateur\RedisInfo\History\Database;
use Machinateur\RedisInfo\Model\RedisInfoServer;
use Machinateur\RedisInfo\Route\HistoryRoute;
use Machinateur\RedisInfo\Route\IndexRoute;
use Machinateur\RedisInfo\Route\StatusRoute;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Http\HttpServer;
use React\Socket\SocketServer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\SingleCommandApplication;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\EventDispatcher\EventDispatcher;

class RedisInfoApplication extends SingleCommandApplication
{
    protected EventDispatcherInterface  $dispatcher;

    protected Database        $data;
    protected LoggerInterface $log;

    protected LoopInterface   $loop;
    protected Router          $route;
    protected HttpServer      $http;

    protected RedisInfoClientPool       $pool;

    public function __construct(
        string $name    = 'redis-info',
        string $version = 'current',
    ) {
        parent::__construct($name);

        $this->dispatcher = new EventDispatcher();

        $this->setVersion($version);

        // TODO: Allow non-default path (also consider \getcwd() when in phar-context) with command option `-f`.
        $this->data  = new Database(__DIR__ . \DIRECTORY_SEPARATOR . '..' . \DIRECTORY_SEPARATOR . 'redis-info-history.sqlite');
        $this->log   = new NullLogger();
        $this->loop  = Loop::get();
        $this->route = new Router();
        $this->http  = new HttpServer($this->loop, $this->route->handle(...));
        $this->http->on('error', function (\Exception $e): void {
            $this->log->error($e->getMessage(), ['exception' => $e]);
            if ($e = $e->getPrevious()) {
                $this->log->error($e->getMessage(), ['exception' => $e]);
            }
        });

        $this->pool  = new RedisInfoClientPool();
        $this->pool->on('info', $this->saveSnapshot(...));
        $this->pool->on('auth', function (RedisInfoClient $client, bool $result): void {
            if ($result) {
                $this->log->info('Authentication successful', ['server' => $client->server]);
            } else {
                $this->log->error('Authentication failed', ['server' => $client->server]);
            }
        });
        $this->pool->on('error', function (RedisInfoClient $client, \Exception $e): void {
            $this->log->error($e->getMessage(), ['exception' => $e, 'server' => $client->server]);
        });
        $this->pool->on('start', function (RedisInfoClient $client): void {
            $this->log->debug('Starting redis client', ['server' => $client->server]);
        });
        $this->pool->on('stop', function (RedisInfoClient $client): void {
            $this->log->debug('Stopping redis client', ['server' => $client->server]);
        });

        $this->route->get('/',        new IndexRoute($this->pool));
        $this->route->get('/status',  new StatusRoute($this->pool));
        $this->route->get('/history', new HistoryRoute($this->data));
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Pure PHP, self-contained Redis monitoring tool.')
            // TODO
            //->setHelp('')
            //->addUsage('')

            ->addOption('server', 's', InputOption::VALUE_OPTIONAL, 'Local socket server DSN', 'tcp://127.0.0.1:2002')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Ini config file path', RedisInfoServerFactory::DEFAULT_PATH)

            // TODO: Define custom format DSNs, implement direct $argv configuration, using command option `-m`.
            //->addOption('monitor', 'm', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Redis server host and port (DSN)')
            ->addOption('daemon', 'd', InputOption::VALUE_NONE, 'Run as daemon without the server')

            //->addOption('database', 'f', InputOption::VALUE_REQUIRED, 'SQLite history database path')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Can only be set here, because the application is assigned after the constructor call.
        $this->getApplication()
            ->setDispatcher($this->dispatcher);

        $this->log = new ConsoleLogger($output);
        $console   = new SymfonyStyle($input, $output);
        $status    = Command::SUCCESS;

        $console->title('Redis Info');

        $daemon    = (bool)$input->getOption('daemon');
        $socketDsn = $input->getOption('server');
        // TODO: Parse DSN
        $socket    = $daemon ? null : new SocketServer($socketDsn);

        $configPath = $input->getOption('config');
        try {
            $config     = (new RedisInfoServerFactory())->fromIniFile($configPath);
        } catch (\InvalidArgumentException $exception) {
            $console->error(\sprintf('Error: %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        $console->section('Configuration');
        $console->info(
            \array_map(function (RedisInfoServer $server): string {
                $this->pool->addServer($server);

                return \sprintf(' * "%s": %s:%s@%s:%d',
                    $server->label,
                    $server->username,
                    isset($server->password) ? \str_repeat('*', \strlen($server->password)) : '',
                    $server->host,
                    $server->port,
                );
            }, $config)
        );

        \set_exception_handler(function (\Throwable $e) use ( & $status): void {
            $this->log->error($e->getMessage(), ['exception' => $e]);
            if ($e = $e->getPrevious()) {
                $this->log->error($e->getMessage(), ['exception' => $e]);
            }

            $status = Command::FAILURE;

            $this->loop->stop();
        });

        \register_shutdown_function(function (): void {
            echo 'Bye!' . \PHP_EOL . \PHP_EOL;
        });

        \pcntl_signal(\SIGTERM, fn (): null => $this->stopCommand($console));
        \pcntl_signal(\SIGINT,  fn (): null => $this->stopCommand($console));

        $console->section('Log');

        if ( ! $daemon) {
            $console->info("Listening on {$socket->getAddress()}");

            $this->http->listen($socket);
        }

        $this->data->start();
        $this->pool->start();

        $this->loop->run();

        $this->pool->stop();
        $this->data->stop();

        if ( ! $daemon) {
            $console->info('Stopping');

            $socket->close();
        }

        return $status;
    }

    public function saveSnapshot(RedisInfoClient $client, array $info): void
    {
        $this->log->debug(\sprintf('Snapshot for server "%s" (%s)', $client->server->label, $client->server->id));

        $this->data->save($client->server->id, $info);
    }

    private function stopCommand(SymfonyStyle $console): void
    {
        $console->info('Stop signal received.');

        $this->loop->stop();
    }
}
