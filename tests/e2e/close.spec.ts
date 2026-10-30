import { expect, test, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

// Members from bin/console seed:demo. Closing can happen only once, so only the phone
// project closes "Jakob Lenz" (poll 3), which no other journey uses.
const BOARD = 'max.seidel@example.org';
const MEMBER = 'rosa.kuehn@example.org';

async function signIn(page: Page, email: string) {
  await page.goto('/dev-login');
  await page.getByRole('button', { name: email }).click();
  await expect(page).toHaveURL('/');
}

test('a board member closes a poll and the result replaces the ballot', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'phone', 'A poll closes only once.');
  await signIn(page, BOARD);
  await page.goto('/polls/3');

  await expect(page.getByRole('heading', { name: 'Abschluss durch den Vorstand' })).toBeVisible();
  await page.getByRole('button', { name: 'Abstimmung abschließen' }).click();
  await expect(page.getByText('Wirklich abschließen? 2 von 25 Stimmrechten sind abgegeben, 23 Mitglieder fehlen noch.')).toBeVisible();
  const confirm = page.getByRole('button', { name: 'Abschließen und Ergebnis anzeigen' });
  await expect(confirm).toBeFocused();
  expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);

  await confirm.click();
  await expect(page.getByRole('status')).toHaveText('Abstimmung abgeschlossen. Das Ergebnis ist jetzt sichtbar.');
  await expect(page.getByRole('heading', { name: 'Ergebnis' })).toBeVisible();
  await expect(page.locator('.verdict')).toContainText('Ausnahme abgelehnt');
  await expect(page.locator('.verdict')).toContainText('8,0 % Ja');
  await expect(page.getByRole('heading', { name: 'Deine Stimme' })).toHaveCount(0);
  await expect(page.getByRole('heading', { name: 'Abschluss durch den Vorstand' })).toHaveCount(0);
  await expect(page).toHaveURL('/polls/3');
  expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);

  await page.getByRole('link', { name: 'Alle Abstimmungen' }).click();
  await expect(page.getByRole('link', { name: /Jakob Lenz.*Ausnahme abgelehnt/ })).toBeVisible();
});

test('a member sees the result of a closed poll and its verdict on the home page', async ({ page }) => {
  await signIn(page, MEMBER);

  const poll = page.getByRole('link', { name: /Lea Hartwig/ });
  await expect(poll).toContainText('Aufgenommen');
  await poll.click();

  await expect(page.locator('.verdict')).toContainText('Aufgenommen');
  await expect(page.locator('.verdict')).toContainText('68,0 % Ja');
  await expect(page.getByText('17 von 25 Stimmberechtigten haben mit Ja gestimmt')).toBeVisible();
  await expect(page.getByText('24 von 25 Stimmrechten wurden abgegeben, 1 davon übertragen.')).toBeVisible();
  await expect(page.getByText(/Diese Abstimmung wird samt Ergebnis am .* automatisch gelöscht\./)).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Abschluss durch den Vorstand' })).toHaveCount(0);
  expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
});

test.describe('without JavaScript', () => {
  test.use({ javaScriptEnabled: false });

  test('the board opens and cancels the confirmation', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop', 'One project is enough for a flow that changes nothing.');
    await signIn(page, BOARD);
    await page.goto('/polls/2');

    await page.getByRole('button', { name: 'Abstimmung abschließen' }).click();
    await expect(page).toHaveURL('/polls/2?confirm=close#board');
    await expect(page.getByText('Wirklich abschließen?')).toBeVisible();

    await page.getByRole('link', { name: 'Abbrechen' }).click();
    await expect(page).toHaveURL('/polls/2#board');
    await expect(page.getByText('Wirklich abschließen?')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Abstimmung abschließen' })).toBeVisible();
  });
});
