import { expect, test, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { openAdmin } from './header';

// Runs after every other journey (see playwright.config.ts), because its new polls join the
// lists the others read. Each device works on polls of its own name.
const ADMIN = 'mara.kessler@example.org';
const MEMBER = 'rosa.kuehn@example.org';

async function signIn(page: Page, email: string) {
  await page.goto('/dev-login');
  await page.getByRole('button', { name: email }).click();
  await expect(page).toHaveURL('/');
}

// The confirmations fade in; axe would measure their colours halfway.
async function noViolations(page: Page) {
  await page.waitForFunction(() => document.getAnimations().length === 0);
  expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
}

test('an admin creates, publishes, and after an accidental close reopens a poll', async ({ page }, testInfo) => {
  const name = `Noah ${testInfo.project.name}`;
  const toast = page.getByRole('status');
  await signIn(page, ADMIN);
  await openAdmin(page, 'Abstimmungen');
  await expect(page.getByRole('link', { name: /^Details\W+Lea Hartwig$/ })).toBeVisible();
  await noViolations(page);

  // The kind switches labels and the question field in place.
  await page.getByRole('link', { name: 'Neue Abstimmung' }).click();
  await expect(page.getByLabel('Name der Person, die sich bewirbt')).toBeFocused();
  await expect(page.getByLabel('Frage, über die abgestimmt wird')).toBeHidden();
  await page.getByLabel('Ausnahme in der Probezeit').check();
  await expect(page.getByLabel('Frage, über die abgestimmt wird')).toBeVisible();
  await expect(page.getByLabel('Entscheidung bis')).toBeVisible();
  await page.getByLabel('Name der Person in der Probezeit').fill(name);
  await page.getByRole('button', { name: 'Als Entwurf speichern' }).click();
  await expect(toast).toHaveText('Bitte die Frage formulieren, über die abgestimmt wird.');
  await expect(page.getByLabel('Frage, über die abgestimmt wird')).toBeFocused();
  await noViolations(page);
  await page.getByLabel('Frage, über die abgestimmt wird').fill('Darf Noah beim Sommerkonzert mitsingen?');
  await page.getByRole('button', { name: 'Als Entwurf speichern' }).click();
  await expect(toast).toHaveText('Entwurf gespeichert. Jetzt freischalten, wenn es losgehen soll.');
  await expect(page.getByRole('heading', { name: 'Entwurf' })).toBeVisible();
  await expect(page.getByText('Darf Noah beim Sommerkonzert mitsingen?')).toBeVisible();
  const detail = page.url();
  const pollPath = new URL(detail).pathname.replace('/admin', '');
  await expect(page.locator('#share-link')).toHaveText(new RegExp(`${pollPath}$`));
  await noViolations(page);

  await page.getByRole('button', { name: 'Abstimmung freischalten' }).click();
  await expect(toast).toHaveText('Freigeschaltet. Mitglieder können jetzt abstimmen.');
  await expect(page.getByRole('heading', { name: /^Offen seit / })).toBeVisible();
  await expect(page.getByText('Ergebnis: wird erst nach dem Abschluss berechnet.', { exact: false })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Beteiligung' })).toBeVisible();
  await noViolations(page);

  // The admin is also on the board, so they vote and close on the poll page.
  await page.getByRole('link', { name: 'Zur Abstimmungsseite' }).click();
  await page.getByRole('button', { name: /^Ja/ }).click();
  // The poll page drops a tap while the vote is on its way, so the close waits for its answer.
  await expect(toast).toHaveText('Stimme registriert: Ja.');
  await page.getByRole('button', { name: 'Abstimmung abschließen' }).click();
  await page.getByRole('button', { name: 'Abschließen und Ergebnis anzeigen' }).click();
  await expect(page.getByRole('heading', { name: 'Ergebnis' })).toBeVisible();

  await page.goto(detail);
  await expect(page.getByRole('heading', { name: /^Abgeschlossen am / })).toBeVisible();
  await page.getByRole('link', { name: 'Wieder öffnen' }).click();
  await expect(page).toHaveURL(`${detail}?confirm=reopen`);
  const confirm = page.getByRole('button', { name: 'Ja, wieder öffnen' });
  await expect(confirm).toBeFocused();
  await noViolations(page);
  await confirm.click();
  await expect(toast).toHaveText('Abstimmung wieder geöffnet. Alle Mitglieder sehen den Hinweis.');
  await expect(page).toHaveURL(detail);
  await expect(page.getByText(/Diese Abstimmung wurde am .* wieder geöffnet\./)).toBeVisible();

  // Every member sees the notice, and the admin's vote is still there.
  await signIn(page, MEMBER);
  await page.goto(pollPath);
  await expect(page.getByText('wieder geöffnet', { exact: true })).toBeVisible();
  await expect(page.getByText(/^Wieder geöffnet am /)).toBeVisible();
  await expect(page.locator('.part-head .count')).toHaveText(/^1 von \d+ Stimmrechten abgegeben$/);
  await noViolations(page);
});

test('an admin deletes a draft after the confirmation and edits only the note of an open poll', async ({ page }, testInfo) => {
  const name = `Ida ${testInfo.project.name}`;
  const toast = page.getByRole('status');
  await signIn(page, ADMIN);
  await page.goto('/admin/polls/new');
  await page.getByLabel('Name der Person, die sich bewirbt').fill(name);
  await page.getByRole('button', { name: 'Als Entwurf speichern' }).click();
  await expect(toast).toHaveText('Entwurf gespeichert. Jetzt freischalten, wenn es losgehen soll.');

  await page.getByRole('link', { name: 'Löschen' }).click();
  await expect(page.getByText('Entwurf wirklich löschen?')).toBeVisible();
  await page.getByRole('link', { name: 'Abbrechen' }).click();
  await expect(page.getByText('Entwurf wirklich löschen?')).toHaveCount(0);
  await page.getByRole('link', { name: 'Löschen' }).click();
  await page.getByRole('button', { name: 'Ja, löschen' }).click();
  await expect(toast).toHaveText('Entwurf gelöscht.');
  await expect(page).toHaveURL('/admin/polls');
  await expect(page.getByText(name)).toHaveCount(0);

  await page.goto('/admin/polls/1');
  await page.getByRole('link', { name: 'Hinweis bearbeiten' }).click();
  await expect(page.getByLabel('Name der Person, die sich bewirbt')).toHaveCount(0);
  await noViolations(page);
  await page.getByRole('button', { name: 'Speichern' }).click();
  await expect(toast).toHaveText('Gespeichert.');
});

test.describe('without JavaScript', () => {
  test.use({ javaScriptEnabled: false });

  test('the form shows the question once the save names it missing', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'polls-desktop', 'One project is enough for a flow that saves nothing.');
    await signIn(page, ADMIN);
    await page.goto('/admin/polls/1');
    await expect(page.locator('#share-link')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Kopieren' })).toBeHidden();
    await page.goto('/admin/polls/new');
    await page.getByLabel('Ausnahme in der Probezeit').check();
    await expect(page.getByLabel('Frage, über die abgestimmt wird')).toBeHidden();
    await page.getByLabel('Name der Person, die sich bewirbt').fill('Noah Example');
    await page.getByRole('button', { name: 'Als Entwurf speichern' }).click();

    await expect(page.getByText('Bitte die Frage formulieren, über die abgestimmt wird.').first()).toBeVisible();
    await expect(page.getByLabel('Frage, über die abgestimmt wird')).toBeVisible();
    await expect(page.getByLabel('Name der Person in der Probezeit')).toHaveValue('Noah Example');
  });
});
