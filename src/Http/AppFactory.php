<?php

declare(strict_types=1);

namespace ShowOfHands\Http;

use function DI\autowire;

use DI\Container;
use DI\ContainerBuilder;
use Psr\Http\Message\ResponseFactoryInterface;
use ShowOfHands\Http\Action\HomeAction;
use ShowOfHands\Http\Action\LocaleAction;
use ShowOfHands\Http\Action\LoginAction;
use ShowOfHands\Http\Action\ThemeAction;
use ShowOfHands\Http\Middleware\CsrfMiddleware;
use ShowOfHands\Http\Middleware\LocaleMiddleware;
use ShowOfHands\Http\Middleware\MigrateMiddleware;
use ShowOfHands\Http\Middleware\SecurityHeadersMiddleware;
use ShowOfHands\Infrastructure\Config\BrandAssets;
use ShowOfHands\Infrastructure\Config\Config;
use ShowOfHands\Infrastructure\Database\ConnectionFactory;
use ShowOfHands\Infrastructure\Database\Migrator;
use ShowOfHands\Infrastructure\I18n\TranslatorFactory;
use ShowOfHands\Infrastructure\Theme\Theme;
use ShowOfHands\Infrastructure\Theme\ThemeBuilder;
use ShowOfHands\Paths;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Interfaces\RouteResolverInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Wires the container, the middleware stack, and the routes.
 */
final class AppFactory
{
    /**
     * @param array<string, mixed> $overrides container entries that replace the defaults, for tests
     *
     * @return App<Container>
     */
    public static function create(Paths $paths, Config $config, array $overrides = []): App
    {
        $container = self::container($paths, $config, $overrides);
        $app = SlimAppFactory::createFromContainer($container);
        $container->set(RouteResolverInterface::class, $app->getRouteResolver());

        $app->get('/', HomeAction::class);
        $app->get('/login', LoginAction::class);
        $app->post('/locale', LocaleAction::class);
        $app->get(ThemeAction::PATH, ThemeAction::class);

        // Slim runs the middleware added last first. Read the list from the bottom up.
        $app->add(CsrfMiddleware::class);
        $app->addRoutingMiddleware();
        $app->addBodyParsingMiddleware();
        $app->add(MigrateMiddleware::class);
        $errors = $app->addErrorMiddleware($config->isDev(), true, true);
        // Resolved from the container only when an error happens.
        $errors->setDefaultErrorHandler(ErrorHandler::class);
        // Outside the error middleware, so error pages know the locale too.
        $app->add(LocaleMiddleware::class);
        $app->add(SecurityHeadersMiddleware::class);

        return $app;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private static function container(Paths $paths, Config $config, array $overrides): Container
    {
        $themeVersion = substr(sha1(json_encode([
            ThemeBuilder::VERSION,
            $config->brand->accent,
            $config->brand->ink,
            $config->brand->fontFamily,
            $config->brand->fontFiles,
        ], JSON_THROW_ON_ERROR)), 0, 12);

        $builder = new ContainerBuilder();
        $builder->addDefinitions([
            Paths::class => $paths,
            Config::class => $config,
            ResponseFactoryInterface::class => autowire(ResponseFactory::class),
            \PDO::class => static fn(): \PDO => ConnectionFactory::open($paths->database()),
            Migrator::class => static fn(\PDO $pdo): Migrator => new Migrator($pdo, $paths->migrations(), $paths->data()),
            Session::class => static fn(): Session => Session::native($paths->data() . '/sessions', $config->sessionLifetimeDays, $config->isHttps()),
            Translator::class => static fn(): Translator => TranslatorFactory::create(
                [$paths->translations(), $paths->translationOverrides()],
                $config->defaultLocale,
            ),
            Theme::class => static fn(): Theme => (new ThemeBuilder())->build($config->brand),
            BrandAssets::class => static fn(): BrandAssets => new BrandAssets($paths->public()),
            Environment::class => static function (Translator $translator, BrandAssets $assets, Session $session) use ($paths, $config, $themeVersion): Environment {
                $twig = new Environment(new FilesystemLoader($paths->templates()), [
                    'cache' => $paths->data() . '/cache/twig',
                    'auto_reload' => true,
                    'strict_variables' => $config->isDev(),
                ]);
                $twig->addExtension(new TranslationExtension($translator));
                $twig->addExtension(new TwigExtension($config->brand, $assets, $themeVersion, $paths->templates() . '/icons', $session));

                return $twig;
            },
            ErrorHandler::class => static fn(Renderer $renderer, ResponseFactoryInterface $responses): ErrorHandler => new ErrorHandler(
                $renderer,
                $responses,
                $paths->data() . '/logs/app.log',
                $config->isDev(),
            ),
            LocaleMiddleware::class => static fn(Translator $translator): LocaleMiddleware => new LocaleMiddleware($translator, $config->defaultLocale),
            SecurityHeadersMiddleware::class => static fn(): SecurityHeadersMiddleware => new SecurityHeadersMiddleware($config->isHttps()),
            LocaleAction::class => static fn(ReturnPath $returnPath): LocaleAction => new LocaleAction($returnPath, $config->isHttps()),
        ]);
        $builder->addDefinitions($overrides);

        return $builder->build();
    }
}
