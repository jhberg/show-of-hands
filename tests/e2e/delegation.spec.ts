import { expect, test, type Page } from '@playwright/test';
import { noViolations } from './axe';

// Members from bin/console seed:demo who have neither voted nor delegated on "Klara Wendt"
// (poll 1). The phone, desktop, and iPhone projects run in parallel, so each uses its own members.
const PAIRS = {
  phone: { from: 'ida.brandl@example.org', fromName: 'Ida Brandl', to: 'Hedi Graf (Alt 2)', toName: 'Hedi Graf', toFirst: 'Hedi' },
  desktop: { from: 'ella.moser@example.org', fromName: 'Ella Moser', to: 'Frieda Haas (Alt 1)', toName: 'Frieda Haas', toFirst: 'Frieda' },
  iphone: { from: 'matti.busch@example.org', fromName: 'Matti Busch', to: 'Pia Lechner (Sopran 2)', toName: 'Pia Lechner', toFirst: 'Pia' },
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
  await noViolations(page);

  // Without a choice, the field names the problem and takes the focus.
  await page.getByRole('button', { name: 'Stimme übertragen' }).press('Enter');
  await expect(page.getByRole('status')).toHaveText('Bitte eine Person auswählen.');
  await expect(select).toHaveAttribute('aria-invalid', 'true');
  await expect(select).toHaveAccessibleDescription('Bitte eine Person auswählen.');
  await expect(select).toBeFocused();

  // The panel goes away with its button, so the focus moves to the notice that replaced it.
  await select.selectOption({ label: pair.to });
  await page.getByRole('button', { name: 'Stimme übertragen' }).press('Enter');
  await expect(page.getByRole('status')).toHaveText(`Stimme an ${pair.toName} übertragen.`);
  await expect(page.getByText(`Du hast deine Stimme an ${pair.toName} übertragen.`)).toBeVisible();
  await expect(page.locator('.notice', { hasText: 'Du hast deine Stimme an' })).toBeFocused();
  await expect(page.getByText(`${pair.toFirst} hat noch nicht abgestimmt.`)).toBeVisible();
  const entry = page.locator('.dlist li', { hasText: pair.fromName });
  await expect(entry).toContainText(pair.toName);
  await expect(entry).toContainText('noch nicht abgegeben');
  await noViolations(page);

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

  await page.getByRole('button', { name: 'Übertragung zurücknehmen, ohne abzustimmen' }).press('Enter');
  await expect(page.getByRole('status')).toHaveText('Übertragung zurückgenommen. Dein Stimmrecht liegt wieder bei dir.');
  await expect(page.getByText('Tippe auf Ja oder Nein.')).toBeVisible();
  // The notice with the button went away, so the focus starts over at the ballot.
  await expect(page.getByRole('heading', { name: 'Deine Stimme' })).toBeFocused();
});

test.describe('without JavaScript', () => {
  test.use({ javaScriptEnabled: false });

  test('a member delegates through plain forms', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name === 'iphone', 'Plain forms need no second engine.');
    const pair = PAIRS_WITHOUT_JS[testInfo.project.name as keyof typeof PAIRS_WITHOUT_JS];
    await signIn(page, pair.from);
    await page.goto('/polls/1');

    await page.getByRole('button', { name: 'Stimme an ein anderes Mitglied übertragen' }).click();
    await expect(page).toHaveURL('/polls/1?delegate=1#deleg');
    // Without a choice, the page answers with the message at the field.
    await page.getByRole('button', { name: 'Stimme übertragen' }).click();
    await expect(page.getByLabel('An wen?')).toHaveAttribute('aria-invalid', 'true');
    await expect(page.locator('#deleg-to-error')).toHaveText('Bitte eine Person auswählen.');
    await page.getByLabel('An wen?').selectOption({ label: pair.to });
    await page.getByRole('button', { name: 'Stimme übertragen' }).click();
    await expect(page).toHaveURL('/polls/1');
    await expect(page.getByRole('status')).toHaveText(`Stimme an ${pair.toName} übertragen.`);

    await page.getByRole('button', { name: 'Übertragung zurücknehmen, ohne abzustimmen' }).click();
    await expect(page.getByRole('status')).toHaveText('Übertragung zurückgenommen. Dein Stimmrecht liegt wieder bei dir.');
  });
});
