import { expect, test, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

// Members from bin/console seed:demo who have neither voted nor delegated on "Klara Wendt"
// (poll 1). The phone and desktop projects run in parallel, so each uses its own members.
const PAIRS = {
  phone: { from: 'ida.brandl@example.org', fromName: 'Ida Brandl', to: 'Hedi Graf (Alt 2)', toName: 'Hedi Graf', toFirst: 'Hedi' },
  desktop: { from: 'ella.moser@example.org', fromName: 'Ella Moser', to: 'Frieda Haas (Alt 1)', toName: 'Frieda Haas', toFirst: 'Frieda' },
};
const PAIRS_WITHOUT_JS = {
  phone: { from: 'emma.pohl@example.org', to: 'Carla Mertens (Chorleitung)', toName: 'Carla Mertens' },
  desktop: { from: 'paul.arnold@example.org', to: 'Finn Reuter (Bass 2)', toName: 'Finn Reuter' },
};

async function signIn(page: Page, email: string) {
  await page.goto('/dev-login');
  await page.getByRole('button', { name: email }).click();
  await expect(page).toHaveURL('/');
}

test('a member delegates, votes to take it back, delegates again, and revokes', async ({ page }, testInfo) => {
  const pair = PAIRS[testInfo.project.name as keyof typeof PAIRS];
  await signIn(page, pair.from);
  await page.goto('/polls/1');

  const toggle = page.getByRole('button', { name: 'Stimme an ein anderes Mitglied übertragen' });
  await expect(toggle).toHaveAttribute('aria-expanded', 'false');
  await toggle.click();
  await expect(toggle).toHaveAttribute('aria-expanded', 'true');
  // Greta delegated to Mara, so Greta is not offered; Mara has voted.
  const select = page.getByLabel('An wen?');
  await expect(select.locator('option', { hasText: 'Greta Sommerfeld' })).toHaveCount(0);
  await expect(select.locator('option', { hasText: 'Mara Kessler (Sopran 1) · hat abgestimmt' })).toHaveCount(1);
  expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);

  await page.getByRole('button', { name: 'Stimme übertragen' }).click();
  await expect(page.getByRole('status')).toHaveText('Bitte eine Person auswählen.');

  await select.selectOption({ label: pair.to });
  await page.getByRole('button', { name: 'Stimme übertragen' }).click();
  await expect(page.getByRole('status')).toHaveText(`Stimme an ${pair.toName} übertragen.`);
  await expect(page.getByText(`Du hast deine Stimme an ${pair.toName} übertragen.`)).toBeVisible();
  await expect(page.getByText(`${pair.toFirst} hat noch nicht abgestimmt.`)).toBeVisible();
  const entry = page.locator('.dlist li', { hasText: pair.fromName });
  await expect(entry).toContainText(pair.toName);
  await expect(entry).toContainText('noch nicht abgegeben');
  expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);

  await page.getByRole('link', { name: 'Alle Abstimmungen' }).click();
  await expect(page.getByRole('link', { name: /Klara Wendt/ })).toContainText(`Übertragen an ${pair.toFirst}`);
  await page.getByRole('link', { name: /Klara Wendt/ }).click();

  await page.getByRole('button', { name: /^Ja/ }).click();
  await expect(page.getByRole('status')).toHaveText(`Stimme registriert: Ja. Die Übertragung an ${pair.toFirst} ist aufgehoben.`);
  await expect(page.locator('.dlist li', { hasText: pair.fromName })).toHaveCount(0);

  await toggle.click();
  await expect(page.getByText('Deine eigene Stimme wird dabei zurückgezogen.')).toBeVisible();
  await select.selectOption({ label: pair.to });
  await page.getByRole('button', { name: 'Stimme übertragen' }).click();
  await expect(page.getByRole('button', { name: /^Ja/ })).toHaveAttribute('aria-pressed', 'false');

  await page.getByRole('button', { name: 'Übertragung zurücknehmen, ohne abzustimmen' }).click();
  await expect(page.getByRole('status')).toHaveText('Übertragung zurückgenommen. Dein Stimmrecht liegt wieder bei dir.');
  await expect(page.getByText('Tippe auf Ja oder Nein.')).toBeVisible();
});

test.describe('without JavaScript', () => {
  test.use({ javaScriptEnabled: false });

  test('a member delegates through plain forms', async ({ page }, testInfo) => {
    const pair = PAIRS_WITHOUT_JS[testInfo.project.name as keyof typeof PAIRS_WITHOUT_JS];
    await signIn(page, pair.from);
    await page.goto('/polls/1');

    await page.getByRole('button', { name: 'Stimme an ein anderes Mitglied übertragen' }).click();
    await expect(page).toHaveURL('/polls/1?delegate=1#deleg');
    await page.getByLabel('An wen?').selectOption({ label: pair.to });
    await page.getByRole('button', { name: 'Stimme übertragen' }).click();
    await expect(page).toHaveURL('/polls/1');
    await expect(page.getByRole('status')).toHaveText(`Stimme an ${pair.toName} übertragen.`);

    await page.getByRole('button', { name: 'Übertragung zurücknehmen, ohne abzustimmen' }).click();
    await expect(page.getByRole('status')).toHaveText('Übertragung zurückgenommen. Dein Stimmrecht liegt wieder bei dir.');
  });
});
