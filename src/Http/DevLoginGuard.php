<?php

declare(strict_types=1);

namespace ShowOfHands\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Keeps the dev login closed to everyone but the developer's own machine.
 *
 * The config loader already refuses app.env 'dev' unless app.url is localhost. This guard also
 * checks each request, so a dev config copied to a public host stays closed:
 *
 * - The app must run on PHP's built-in server, as `composer serve` and the e2e copy do. Host and
 *   peer can be faked behind a proxy: nginx in front of Apache reaches PHP from 127.0.0.1.
 * - The Host header must name loopback.
 * - The peer must be loopback or in SHOW_OF_HANDS_DEV_PEERS. Docker's port forwarding makes
 *   requests from the host arrive from the bridge gateway, so compose.yaml lists the private
 *   ranges Docker uses there.
 */
final readonly class DevLoginGuard
{
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]', '::1'];
    private const LOOPBACK_PEERS = ['127.0.0.0/8', '::1/128'];

    /** @var list<string> */
    private array $peers;

    /**
     * @param bool $builtInServer whether the app runs on PHP's built-in server
     * @param list<string> $trustedPeers extra CIDR ranges, for example a container's gateway
     */
    public function __construct(
        private bool $builtInServer,
        array $trustedPeers = [],
    ) {
        $this->peers = [...self::LOOPBACK_PEERS, ...$trustedPeers];
    }

    public static function fromEnvironment(): self
    {
        $value = getenv('SHOW_OF_HANDS_DEV_PEERS');
        $ranges = is_string($value) ? array_values(array_filter(array_map(trim(...), explode(',', $value)))) : [];

        return new self(PHP_SAPI === 'cli-server', $ranges);
    }

    public function allows(ServerRequestInterface $request): bool
    {
        if (!$this->builtInServer) {
            return false;
        }
        if (!in_array(strtolower($request->getUri()->getHost()), self::LOOPBACK_HOSTS, true)) {
            return false;
        }
        $peer = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        if (!is_string($peer)) {
            return false;
        }
        foreach ($this->peers as $range) {
            if (self::inRange($peer, $range)) {
                return true;
            }
        }

        return false;
    }

    private static function inRange(string $ip, string $cidr): bool
    {
        [$network, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $address = @inet_pton($ip);
        $base = @inet_pton((string) $network);
        if ($address === false || $base === false || strlen($address) !== strlen($base)) {
            return false;
        }
        $bits = $bits === null ? strlen($base) * 8 : (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($address, 0, $bytes) !== substr($base, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($address[$bytes]) & $mask) === (ord($base[$bytes]) & $mask);
    }
}
