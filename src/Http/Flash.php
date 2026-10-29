<?php

declare(strict_types=1);

namespace ShowOfHands\Http;

use Symfony\Component\Translation\Translator;

/**
 * The toast for the page after a redirect. The session stores the message key and its
 * placeholders, never finished text, so the toast renders in the language of the next page.
 */
final readonly class Flash
{
    private const KEY = 'flash';

    public function __construct(
        private Session $session,
        private Translator $translator,
    ) {}

    /**
     * @param array<string, string|int> $params
     */
    public function toast(string $key, array $params = []): void
    {
        $this->session->set(self::KEY, ['key' => $key, 'params' => $params]);
    }

    /**
     * The pending toast as text, removed from the session.
     */
    public function take(): ?string
    {
        $flash = $this->session->take(self::KEY);
        if (!is_array($flash) || !is_string($flash['key'] ?? null) || !is_array($flash['params'] ?? null)) {
            return null;
        }

        /** @var array<string, string|int> $params */
        $params = $flash['params'];

        return $this->translator->trans($flash['key'], $params);
    }
}
