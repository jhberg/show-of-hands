import { expect, test, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

// Members from bin/console seed:demo who have not voted on "Mila Sandberg" yet. The phone,
// desktop, and iPhone projects run in parallel against the same data, so each uses its own
// members. The name lists show a display name where a member has one.
const VOTERS = {
  phone: { name: 'Theo Reinhardt', email: 'theo.reinhardt@example.org' },
  desktop: { name: 'Matti Busch', email: 'matti.busch@example.org' },
  iphone: { name: 'Lotte B.', email: 'charlotte.brenner-waldheim@example.org' },
};
const VOTERS_WITHOUT_JS = {
  phone: { name: 'Erik Voss', email: 'erik.voss@example.org' },
  desktop: { name: 'Leo Kranz', email: 'leo.kranz@example.org' },
};

async function signIn(page: Page, email: string) {
  await page.goto('/dev-login');
  await page.getByRole('button', { name: email }).click();
  await expect(page).toHaveURL('/');
}

test('a member votes, changes the vote, and sees the turnout', async ({ page }, testInfo) => {
  const voter = VOTERS[testInfo.project.name as keyof typeof VOTERS];
  await signIn(page, voter.email);

  const poll = page.getByRole('link', { name: /Mila Sandberg/ });
  await expect(poll).toContainText('Noch nicht abgestimmt');
  await poll.click();

  await expect(page.getByRole('heading', { level: 1, name: 'Mila Sandberg' })).toBeVisible();
  await expect(page.getByText('Soll Mila Sandberg in den Chor aufgenommen werden?')).toBeVisible();
  await expect(page.getByText('Tippe auf Ja oder Nein.')).toBeVisible();
  const missing = page.locator('.names');
  await expect(missing.getByText(voter.name)).toBeVisible();
  expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);

  const yes = page.getByRole('button', { name: /^Ja/ });
  const no = page.getByRole('button', { name: /^Nein/ });
  await yes.click();
  await expect(page.getByRole('status')).toHaveText('Stimme registriert: Ja.');
  await expect(yes).toHaveAttribute('aria-pressed', 'true');
  await expect(no).toHaveAttribute('aria-pressed', 'false');
  await expect(page.getByText('Deine Stimme ist registriert: Ja.')).toBeVisible();
  await expect(missing.getByText(voter.name)).toHaveCount(0);
  await expect(page).toHaveURL('/polls/2');
  expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);

  await no.click();
  await expect(page.getByRole('status')).toHaveText('Stimme geändert: Nein.');
  await expect(no).toHaveAttribute('aria-pressed', 'true');
  await no.click();
  await expect(page.getByRole('status')).toHaveText('Bleibt bei Nein.');

  // A second tap while the first is still on its way is dropped, so the page shows the stored vote.
  let held = false;
  await page.route('**/polls/2/ballot', async (route) => {
    if (!held) {
      held = true;
      await new Promise((resolve) => setTimeout(resolve, 500));
    }
    await route.continue();
  });
  await yes.click();
  await no.click();
  await expect(page.getByRole('status')).toHaveText('Stimme geändert: Ja.');
  await page.unrouteAll({ behavior: 'wait' });
  await page.reload();
  await expect(yes).toHaveAttribute('aria-pressed', 'true');

  await page.getByRole('link', { name: 'Alle Abstimmungen' }).click();
  await expect(page.getByRole('link', { name: /Mila Sandberg/ })).toContainText('Abgestimmt');
});

test.describe('without JavaScript', () => {
  test.use({ javaScriptEnabled: false });

  test('a member votes through a plain form post', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name === 'iphone', 'A plain form post needs no second engine.');
    const voter = VOTERS_WITHOUT_JS[testInfo.project.name as keyof typeof VOTERS_WITHOUT_JS];
    await signIn(page, voter.email);
    await page.goto('/polls/2');

    await page.getByRole('button', { name: /^Ja/ }).click();
    await expect(page).toHaveURL('/polls/2');
    await expect(page.getByRole('status')).toHaveText('Stimme registriert: Ja.');
    await expect(page.getByRole('button', { name: /^Ja/ })).toHaveAttribute('aria-pressed', 'true');
    await expect(page.locator('.names').getByText(voter.name)).toHaveCount(0);
  });
});

test('a draft poll looks like a poll that does not exist', async ({ page }) => {
  await signIn(page, 'lina.hofer@example.org');
  await page.goto('/polls/4');
  await expect(page.getByText('Diese Abstimmung gibt es nicht mehr oder sie ist noch nicht freigeschaltet.')).toBeVisible();
});
