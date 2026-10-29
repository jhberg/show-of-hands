<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Console;

use DI\Container;
use ShowOfHands\Http\AppFactory;
use ShowOfHands\Infrastructure\Config\ConfigLoader;
use ShowOfHands\Infrastructure\Database\Migrator;
use ShowOfHands\Infrastructure\Setup\FirstStart;
use ShowOfHands\Paths;
use Symfony\Component\Console\Command\Command;

/**
 * A command that works on the instance's database. It brings the schema up to date first,
 * the way the first request after an update would.
 */
abstract class DatabaseCommand extends Command
{
    private ?Container $container = null;

    public function __construct(private readonly Paths $paths)
    {
        parent::__construct();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected function service(string $class): object
    {
        $service = $this->container()->get($class);
        assert($service instanceof $class);

        return $service;
    }

    private function container(): Container
    {
        if ($this->container === null) {
            $this->container = AppFactory::container($this->paths, ConfigLoader::fromFile($this->paths->config()));
            $migrator = $this->container->get(Migrator::class);
            assert($migrator instanceof Migrator);
            $migrator->migrate();
            $firstStart = $this->container->get(FirstStart::class);
            assert($firstStart instanceof FirstStart);
            $firstStart->run();
        }

        return $this->container;
    }
}
