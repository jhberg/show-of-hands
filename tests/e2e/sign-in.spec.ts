import { expect, test } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

// Members from bin/console seed:demo.
const MEMBER = { name: 'Ben Engel', email: 'ben.engel@example.org' };
const ADMIN_PRIVATE = { name: 'Mara Kessler', email: 'mara.kessler@example.net' };

test('a member signs in through the dev login and signs out', async ({ page }) => {
  await page.goto('/');
  await expect(page).toHaveURL('/login');

  await page.getByRole('link', { name: 'Mit Google anmelden' }).click();
  await expect(page.getByRole('heading', { name: 'Konto auswählen' })).toBeVisible();
  await page.getByRole('button', { name: MEMBER.email }).click();

  await expect(page).toHaveURL('/');
  await expect(page.getByRole('heading', { name: 'Hallo Ben' })).toBeVisible();
  await expect(page.getByRole('status')).toHaveText(`Angemeldet als ${MEMBER.name}.`);
  await expect(page.getByText('Zurzeit ist keine Abstimmung offen.')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Verwaltung' })).toHaveCount(0);
  expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);

  await page.getByRole('button', { name: 'Abmelden' }).click();
  await expect(page).toHaveURL('/login');
  await page.goto('/');
  await expect(page).toHaveURL('/login');
});

test('a deep link returns to the poll after sign-in', async ({ page }) => {
  await page.goto('/polls/2');
  await expect(page).toHaveURL('/login?return=%2Fpolls%2F2');
  await expect(page.getByText('Nach der Anmeldung geht es direkt zur Abstimmung.')).toBeVisible();
  expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);

  await page.getByRole('link', { name: 'Mit Google anmelden' }).click();
  await page.getByRole('button', { name: ADMIN_PRIVATE.email }).click();

  await expect(page).toHaveURL('/polls/2');
  await expect(page.getByRole('status')).toHaveText(`Angemeldet als ${ADMIN_PRIVATE.name} über ${ADMIN_PRIVATE.email}.`);
  await expect(page.getByText('Diese Abstimmung gibt es nicht mehr oder sie ist noch nicht freigeschaltet.')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Verwaltung' })).toBeVisible();
});

test('an unknown address sees the error and can try another account', async ({ page }) => {
  await page.goto('/dev-login');
  await page.getByLabel('Andere Adresse').fill('stranger@example.net');
  await page.getByRole('button', { name: 'Anmelden', exact: true }).click();

  await expect(page).toHaveURL('/login');
  await expect(page.getByRole('alert')).toContainText('Diese Adresse ist nicht als Mitglied hinterlegt.');
  await expect(page.getByRole('alert')).toContainText('stranger@example.net');
  await expect(page.getByRole('link', { name: 'Mit einem anderen Konto anmelden' })).toBeVisible();
  expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
});
