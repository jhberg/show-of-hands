import { expect, test, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { openAdmin } from './header';

// Runs after the member journeys and one device after the other (see playwright.config.ts):
// saving the settings writes every value, and the other journeys read them.
const ADMIN = 'mara.kessler@example.org';

async function signIn(page: Page, email: string) {
  await page.goto('/dev-login');
  await page.getByRole('button', { name: email }).click();
  await expect(page).toHaveURL('/');
}

test('an admin changes a setting and maintains the voice groups', async ({ page }, testInfo) => {
  const device = testInfo.project.name.replace('settings-', '');
  const key = device === 'phone' ? 'PH' : 'DT';
  const label = `Extra ${device}`;
  const toast = page.getByRole('status');
  const keys = page.locator('.glist .key');
  await signIn(page, ADMIN);
  await openAdmin(page, 'Einstellungen');
  await expect(page.getByText('Bei 25 Mitgliedern sind 17 Ja-Stimmen nötig.')).toBeVisible();
  await expect(page.getByLabel('OAuth-Client-ID')).toHaveAttribute('readonly', '');
  expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);

  const retention = page.getByLabel('Löschen nach');
  await retention.selectOption('30');
  await page.getByRole('button', { name: 'Speichern' }).click();
  await expect(toast).toHaveText('Einstellungen gespeichert.');
  await page.reload();
  await expect(retention).toHaveValue('30');
  await retention.selectOption('14');
  await page.getByRole('button', { name: 'Speichern' }).click();
  await expect(toast).toHaveText('Einstellungen gespeichert.');

  // Groups that members use cannot be removed.
  await expect(page.locator('#g-remove-B2')).toBeDisabled();

  await page.getByLabel('Kürzel der neuen Gruppe').fill(key.toLowerCase());
  await page.getByRole('button', { name: 'Hinzufügen' }).click();
  await expect(toast).toHaveText('Kürzel und Name sind nötig.');
  await expect(page.getByLabel('Name der neuen Gruppe')).toBeFocused();
  await page.getByLabel('Name der neuen Gruppe').fill(label);
  await page.getByRole('button', { name: 'Hinzufügen' }).click();
  await expect(toast).toHaveText(`Gruppe ${label} hinzugefügt.`);
  await expect(keys.last()).toHaveText(key);

  await page.locator(`#g-up-${key}`).click();
  await expect(keys.nth(-2)).toHaveText(key);
  await expect(keys.last()).toHaveText('B2');
  await expect(page.locator(`#g-up-${key}`)).toBeFocused();

  const name = page.getByLabel(`Name der Gruppe ${key}`);
  await name.fill(`${label} 2`);
  await name.press('Enter');
  await expect(toast).toHaveText(`Gruppe umbenannt: ${label} 2.`);
  await expect(name).toHaveValue(`${label} 2`);

  await page.locator(`#g-remove-${key}`).click();
  await expect(toast).toHaveText('Gruppe entfernt.');
  await expect(page.getByLabel(`Name der Gruppe ${key}`)).toHaveCount(0);
  await expect(keys.last()).toHaveText('B2');
});
