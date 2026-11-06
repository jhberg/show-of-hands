import { expect, test, type Page } from '@playwright/test';

// Members from bin/console seed:demo who have not voted on "Mila Sandberg" (poll 2) and whom no
// other journey uses. The phone and desktop projects run in parallel, so each has its own.
const VOTERS = {
  phone: 'greta.sommerfeld@example.org',
  desktop: 'nils.ott@example.org',
};

async function openPoll(page: Page, email: string) {
  await page.goto('/dev-login');
  await page.getByRole('button', { name: email }).click();
  await expect(page).toHaveURL('/');
  await page.goto('/polls/2');
}

test('a vote that does not reach the server asks to tap again', async ({ page }, testInfo) => {
  await openPoll(page, VOTERS[testInfo.project.name as keyof typeof VOTERS]);
  const yes = page.getByRole('button', { name: /^Ja/ });

  await page.route('**/polls/2/ballot', (route) => route.abort('internetdisconnected'));
  await yes.click();
  await expect(page.getByRole('status')).toHaveText('Nicht gespeichert, bitte nochmal tippen.');
  await expect(yes).toHaveAttribute('aria-pressed', 'false');

  await page.unrouteAll({ behavior: 'wait' });
  await yes.click();
  await expect(page.getByRole('status')).toHaveText('Stimme registriert: Ja.');
  await expect(yes).toHaveAttribute('aria-pressed', 'true');
});

test('a vote after the session expired leads to the login', async ({ page }, testInfo) => {
  await openPoll(page, VOTERS[testInfo.project.name as keyof typeof VOTERS]);

  await page.context().clearCookies({ name: 'soh_session' });
  await page.getByRole('button', { name: /^Nein/ }).click();

  await expect(page).toHaveURL('/login?return=%2Fpolls%2F2');
  await expect(page.getByRole('link', { name: 'Mit Google anmelden' })).toBeVisible();
});
