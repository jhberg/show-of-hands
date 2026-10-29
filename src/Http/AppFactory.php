<?php

declare(strict_types=1);

namespace ShowOfHands\Http;

use function DI\autowire;

use DI\Container;
use DI\ContainerBuilder;
use Psr\Http\Message\ResponseFactoryInterface;
use ShowOfHands\Http\Action\AdminPollsAction;
use ShowOfHands\Http\Action\DevLoginAction;
use ShowOfHands\Http\Action\GoogleCallbackAction;
use ShowOfHands\Http\Action\GoogleStartAction;
use ShowOfHands\Http\Action\HomeAction;
use ShowOfHands\Http\Action\LocaleAction;
use ShowOfHands\Http\Action\LoginAction;
use ShowOfHands\Http\Action\LogoutAction;
use ShowOfHands\Http\Action\PollAction;
use ShowOfHands\Http\Action\ThemeAction;
use ShowOfHands\Http\Middleware\CsrfMiddleware;
use ShowOfHands\Http\Middleware\DatabaseMiddleware;
use ShowOfHands\Http\Middleware\LocaleMiddleware;
use ShowOfHands\Http\Middleware\MemberMiddleware;
use ShowOfHands\Http\Middleware\RequireMember;
use ShowOfHands\Http\Middleware\RequireRole;
use ShowOfHands\Http\Middleware\SecurityHeadersMiddleware;
use ShowOfHands\Infrastructure\Auth\GoogleSignIn;
use ShowOfHands\Infrastructure\Auth\LeagueGoogleSignIn;
use ShowOfHands\Infrastructure\Clock\Clock;
use ShowOfHands\Infrastructure\Clock\SystemClock;
use ShowOfHands\Infrastructure\Config\BrandAssets;
use ShowOfHands\Infrastructure\Config\Config;
use ShowOfHands\Infrastructure\Database\ConnectionFactory;
use ShowOfHands\Infrastructure\Database\Migrator;
use ShowOfHands\Infrastructure\I18n\TranslatorFactory;
use ShowOfHands\Infrastructure\Log\ErrorLog;
use ShowOfHands\Infrastructure\Maintenance\Maintenance;
use ShowOfHands\Infrastructure\Setup\FirstStart;
use ShowOfHands\Infrastructure\Theme\Theme;
use ShowOfHands\Infrastructure\Theme\ThemeBuilder;
use ShowOfHands\Paths;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Interfaces\RouteResolverInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Routing\RouteCollectorProxy;
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

        $responses = $app->getResponseFactory();

        $app->get('/login', LoginAction::class);
        $app->post('/logout', LogoutAction::class);
        $app->post('/locale', LocaleAction::class);
        $app->get(ThemeAction::PATH, ThemeAction::class);
        if ($config->hasGoogleClient()) {
            $app->get('/auth/google', GoogleStartAction::class);
            $app->get(LeagueGoogleSignIn::CALLBACK_PATH, GoogleCallbackAction::class);
        }
        if ($config->isDev()) {
            $app->map(['GET', 'POST'], '/dev-login', DevLoginAction::class);
        }

        $app->group('', function (RouteCollectorProxy $member) use ($responses): void {
            $member->get('/', HomeAction::class);
            $member->get('/polls/{id:[0-9]+}', PollAction::class);
            $member->group('/admin', function (RouteCollectorProxy $admin): void {
                $admin->get('/polls', AdminPollsAction::class);
            })->add(RequireRole::admin($responses));
        })->add(new RequireMember($responses));

        // Slim runs the middleware added last first. Read the list from the bottom up.
        $app->add(CsrfMiddleware::class);
        $app->add(MemberMiddleware::class);
        $app->addRoutingMiddleware();
        $app->addBodyParsingMiddleware();
        $app->add(DatabaseMiddleware::class);
        $errors = $app->addErrorMiddleware($config->isDev(), true, true);
        // Resolved from the container only when an error happens.
        $errors->setDefaultErrorHandler(ErrorHandler::class);
        // Outside the error middleware, so error pages know the locale too.
        $app->add(LocaleMiddleware::class);
        $app->add(SecurityHeadersMiddleware::class);

        return $app;
    }

    /**
     * The service container, also used by bin/console.
     *
     * @param array<string, mixed> $overrides
     */
    public static function container(Paths $paths, Config $config, array $overrides = []): Container
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
            Session::class => static fn(): Session => Session::native($paths->sessions(), $config->sessionLifetimeDays, $config->isHttps()),
            Clock::class => autowire(SystemClock::class),
            ErrorLog::class => static fn(): ErrorLog => new ErrorLog($paths->data() . '/logs/app.log'),
            FirstStart::class => autowire()
                ->constructorParameter('admin', $config->bootstrapAdmin)
                ->constructorParameter('defaultLocale', $config->defaultLocale),
            Maintenance::class => autowire()
                ->constructorParameter('sessionsDir', $paths->sessions())
                ->constructorParameter('backupDir', $paths->data() . '/' . Migrator::BACKUP_DIR)
                ->constructorParameter('sessionLifetimeDays', $config->sessionLifetimeDays),
            GoogleSignIn::class => static fn(): GoogleSignIn => new LeagueGoogleSignIn($config->googleClientId, $config->googleClientSecret, $config->appUrl),
            DevLoginGuard::class => static fn(): DevLoginGuard => DevLoginGuard::fromEnvironment(),
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
            ErrorHandler::class => autowire()->constructorParameter('showDetails', $config->isDev()),
            LocaleMiddleware::class => static fn(Translator $translator): LocaleMiddleware => new LocaleMiddleware($translator, $config->defaultLocale),
            SecurityHeadersMiddleware::class => static fn(): SecurityHeadersMiddleware => new SecurityHeadersMiddleware($config->isHttps()),
            LocaleAction::class => autowire()->constructorParameter('https', $config->isHttps()),
            LoginAction::class => autowire()
                ->constructorParameter('googleConfigured', $config->hasGoogleClient())
                ->constructorParameter('devLogin', $config->isDev()),
        ]);
        $builder->addDefinitions($overrides);

        return $builder->build();
    }
}
