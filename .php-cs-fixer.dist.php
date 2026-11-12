<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/config'])
    ->append([__DIR__ . '/bin/console', __DIR__ . '/index.php', __FILE__])
    ->notPath('config.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS' => true,
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => true,
    ])
    ->setCacheFile(__DIR__ . '/data/cache/php-cs-fixer.cache')
    ->setFinder($finder);
