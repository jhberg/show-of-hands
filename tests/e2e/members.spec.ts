import { expect, test, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

// Runs after the other journeys (see playwright.config.ts), because adding a member changes
// the eligible count they read. Each project adds and removes a member of its own.
const ADMIN = 'mara.kessler@example.org';

async function signIn(page: Page, email: string) {
  await page.goto('/dev-login');
  await page.getByRole('button', { name: email }).click();
  await expect(page).toHaveURL('/');
}

test('an admin adds, finds, edits, and removes a member', async ({ page }, testInfo) => {
  const device = testInfo.project.name.replace('members-', '');
  const name = `Robin ${device}`;
  const email = `robin.${device}@example.org`;
  await signIn(page, ADMIN);
  await page.getByRole('link', { name: 'Verwaltung' }).click();
  await page.getByRole('link', { name: 'Mitglieder' }).click();
  await expect(page).toHaveURL('/admin/members');
  await expect(page.getByText('Mara Kessler du')).toBeVisible();

  await page.getByRole('link', { name: 'Mitglied hinzufügen' }).click();
  await expect(page.getByRole('heading', { name: 'Mitglied hinzufügen' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Mitglied hinzufügen' })).toHaveCount(0);
  await expect(page.getByLabel('Name', { exact: true })).toBeFocused();
  expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);

  await page.getByRole('button', { name: 'Hinzufügen' }).click();
  await expect(page.getByRole('status')).toHaveText('Name und mindestens eine gültige E-Mail-Adresse sind nötig.');
  await expect(page.locator('#mf-name-error')).toBeVisible();

  await page.getByLabel('Name', { exact: true }).fill(name);
  await page.getByLabel('E-Mail-Adresse (Example Choir-Konto)').fill(email);
  await page.getByRole('button', { name: 'Hinzufügen' }).click();
  await expect(page.getByRole('status')).toHaveText(`${name} hinzugefügt.`);
  await expect(page.getByRole('heading', { name: 'Mitglied hinzufügen' })).toHaveCount(0);

  // The toast arrives before htmx has set up the swapped panel, so the first keystrokes may come too early.
  const search = page.getByLabel('Mitglieder durchsuchen');
  await expect(async () => {
    await search.fill('');
    await search.fill(email);
    await expect(page).toHaveURL(`/admin/members?q=${encodeURIComponent(email)}`, { timeout: 1000 });
  }).toPass();
  await expect(page.locator('#member-list > li')).toHaveCount(1);
  const row = page.locator('#member-list > li', { hasText: email });
  await expect(row).toContainText(name);

  await row.getByRole('link', { name: 'Bearbeiten' }).click();
  await page.getByLabel('Stimmgruppe', { exact: true }).selectOption({ label: 'Bass 2' });
  await page.getByText('Vorstand', { exact: true }).click();
  await page.getByRole('button', { name: 'Speichern' }).click();
  await expect(page.getByRole('status')).toHaveText('Mitglied gespeichert.');
  // A saved form shows every member again.
  await expect(page).toHaveURL('/admin/members');
  await expect(row).toContainText('Bass 2');
  await expect(row).toContainText('Vorstand');

  await row.getByRole('link', { name: 'Entfernen' }).click();
  await expect(row).toContainText(`${name} entfernen?`);
  const confirm = page.getByRole('button', { name: 'Ja, entfernen' });
  await expect(confirm).toBeFocused();
  expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
  await confirm.click();
  await expect(page.getByRole('status')).toHaveText(`${name} entfernt.`);
  await expect(row).toHaveCount(0);
});

test.describe('without JavaScript', () => {
  test.use({ javaScriptEnabled: false });

  test('an admin adds, finds, and removes a member', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'members-desktop', 'One project is enough for the same flow without JavaScript.');
    const email = 'robin.nojs@example.org';
    await signIn(page, ADMIN);
    await page.goto('/admin/members');

    await page.getByRole('link', { name: 'Mitglied hinzufügen' }).click();
    await expect(page).toHaveURL('/admin/members?add=1#member-form');
    await page.getByLabel('Name', { exact: true }).fill('Robin Nojs');
    await page.getByLabel('E-Mail-Adresse (Example Choir-Konto)').fill(email);
    await page.getByLabel('Weitere E-Mail-Adresse (optional)').fill(email);
    await page.getByRole('button', { name: 'Hinzufügen' }).click();
    await expect(page.locator('#mf-email2-error')).toHaveText('Die beiden Adressen sind gleich.');

    await page.getByLabel('Weitere E-Mail-Adresse (optional)').fill('');
    await page.getByRole('button', { name: 'Hinzufügen' }).click();
    await expect(page).toHaveURL('/admin/members');
    await expect(page.getByRole('status')).toHaveText('Robin Nojs hinzugefügt.');

    await page.getByLabel('Mitglieder durchsuchen').fill('nojs');
    await page.getByLabel('Mitglieder durchsuchen').press('Enter');
    await expect(page).toHaveURL('/admin/members?q=nojs');
    await page.getByRole('link', { name: 'Entfernen' }).click();
    await page.getByRole('button', { name: 'Ja, entfernen' }).click();
    await expect(page).toHaveURL('/admin/members?q=nojs');
    await expect(page.getByRole('status')).toHaveText('Robin Nojs entfernt.');
    await expect(page.locator('#member-list')).toHaveText('Keine Treffer.');
  });
});
