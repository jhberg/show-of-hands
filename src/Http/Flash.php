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
     * @param string|null $page the only page that may show the toast, for one that names what that page shows
     */
    public function toast(string $key, array $params = [], ?string $page = null): void
    {
        $this->session->set(self::KEY, ['key' => $key, 'params' => $params, 'page' => $page]);
    }

    /**
     * The pending toast as text, removed from the session.
     *
     * A toast meant for another page stays in the session until that page renders, so a page
     * opened in another tab neither shows nor swallows it.
     *
     * @param array<string, string|int> $params placeholders the page fills in when it renders,
     *                                         such as the member's choice, which the session never stores
     * @param string|null $page the page that renders, as given to toast(); every page shows a toast meant for any page
     */
    public function take(array $params = [], ?string $page = null): ?string
    {
        $flash = $this->session->get(self::KEY);
        if (!is_array($flash)) {
            return null;
        }
        $meantFor = $flash['page'] ?? null;
        if ($meantFor !== null && $meantFor !== $page) {
            return null;
        }
        $this->session->take(self::KEY);
        if (!is_string($flash['key'] ?? null) || !is_array($flash['params'] ?? null)) {
            return null;
        }

        /** @var array<string, string|int> $stored */
        $stored = $flash['params'];

        return $this->translator->trans($flash['key'], $stored + $params);
    }
}
