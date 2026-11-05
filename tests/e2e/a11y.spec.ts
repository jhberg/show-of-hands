import { expect, test, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

// The accessibility pass over every page, after all other journeys (see playwright.config.ts),
// so the pages hold the data those journeys left. The phone checks German, the desktop English,
// each in light and dark mode. Members from bin/console seed:demo.
const ADMIN = 'jonas.falk@example.org';
const MEMBER = 'lina.hofer@example.org';
// Not yet voted on "Mila Sandberg" (poll 2), and used by no other journey.
const VOTERS = { 'a11y-phone': 'kai.boehm@example.org', 'a11y-desktop': 'finn.reuter@example.org' };
// Lina Hofer's member id.
const LISTED = 2;

const SIGNED_OUT = ['/login', '/dev-login'];
const ADMIN_PAGES = [
  '/',
  '/polls/1',
  '/polls/1?delegate=1',
  '/polls/1?confirm=close',
  '/polls/3',
  '/polls/5',
  '/polls/4',
  '/polls/999',
  '/admin/polls',
  '/admin/polls/new',
  '/admin/polls/1',
  '/admin/polls/1/edit',
  '/admin/polls/3',
  '/admin/polls/3?confirm=reopen',
  '/admin/polls/4',
  '/admin/polls/4?confirm=delete',
  '/admin/polls/4/edit',
  '/admin/polls/5',
  '/admin/polls/999',
  '/admin/members',
  '/admin/members?add=1',
  `/admin/members?edit=${LISTED}`,
  `/admin/members?delete=${LISTED}`,
  '/admin/members?q=zzz',
  '/admin/settings',
  '/no-such-page',
];
// The phone menu, open, on the start page and in an admin section.
const MENU_PAGES = ['/#menu', '/admin/members#menu'];
const MEMBER_PAGES = ['/', '/polls/1', '/polls/1?delegate=1', '/polls/5'];

// A member's language is stored with them. The desktop switches back to German afterwards, because
// the keyboard journeys below and the phone's next run find the German labels.
function language(projectName: string) {
  return projectName === 'a11y-phone' ? { code: 'DE', back: null } : { code: 'EN', back: 'DE' };
}

async function signIn(page: Page, email: string) {
  await page.goto('/dev-login');
  await page.getByRole('button', { name: email }).click();
  await expect(page).toHaveURL('/');
}

// The flag shows the language in use and switches to the other one.
async function switchLanguage(page: Page, code: string) {
  const lang = code.toLowerCase();
  if ((await page.locator('html').getAttribute('lang')) !== lang) {
    await page.locator(`button[name=locale][value=${lang}]`).click();
  }
  await expect(page.locator('html')).toHaveAttribute('lang', lang);
}

/**
 * Records CSP violations in the page, which the browser reports as events, not as failed requests,
 * and returns the list that collects uncaught page errors.
 */
async function watchErrors(page: Page) {
  await page.addInitScript(() => {
    const seen: string[] = [];
    (window as unknown as { cspViolations: string[] }).cspViolations = seen;
    document.addEventListener('securitypolicyviolation', (event) => seen.push(`${event.violatedDirective} ${event.blockedURI}`));
  });
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));

  return errors;
}

/** Collects every problem of every page first, so one run lists them all. */
async function checkPages(page: Page, paths: string[], errors: string[]) {
  const problems: string[] = [];
  for (const entry of paths) {
    // "#menu" opens the phone menu before the check.
    const [path, open] = entry.split('#');
    // Reduced motion stops the rise into view and the colour transitions, so axe reads the
    // final colours, and the scheme can switch without loading the page again.
    await page.emulateMedia({ colorScheme: 'light', reducedMotion: 'reduce' });
    await page.goto(path);
    if (open === 'menu') await page.locator('details.menu summary').click();
    for (const scheme of ['light', 'dark'] as const) {
      await page.emulateMedia({ colorScheme: scheme, reducedMotion: 'reduce' });
      const { violations } = await new AxeBuilder({ page }).analyze();
      for (const violation of violations) {
        for (const node of violation.nodes) {
          problems.push(`${entry} (${scheme}): ${violation.id} at ${node.target.join(' ')}: ${node.failureSummary ?? ''}`);
        }
      }
    }
    for (const csp of await page.evaluate(() => (window as unknown as { cspViolations: string[] }).cspViolations)) {
      problems.push(`${entry}: CSP ${csp}`);
    }
  }
  expect(problems).toEqual([]);
  expect(errors).toEqual([]);
}

test('every page passes axe in light and dark mode', async ({ page }, testInfo) => {
  test.setTimeout(120_000);
  const errors = await watchErrors(page);
  const { code, back } = language(testInfo.project.name);

  await page.goto('/login');
  await switchLanguage(page, code);
  await checkPages(page, SIGNED_OUT, errors);

  await signIn(page, ADMIN);
  await switchLanguage(page, code);
  await checkPages(page, testInfo.project.name === 'a11y-phone' ? [...ADMIN_PAGES, ...MENU_PAGES] : ADMIN_PAGES, errors);
  if (back !== null) {
    await page.goto('/');
    await switchLanguage(page, back);
  }

  await page.context().clearCookies();
  await signIn(page, MEMBER);
  await switchLanguage(page, code);
  await checkPages(page, MEMBER_PAGES, errors);
  if (back !== null) {
    await page.goto('/');
    await switchLanguage(page, back);
  }
});

test('a member votes with the keyboard alone', async ({ page }, testInfo) => {
  await signIn(page, VOTERS[testInfo.project.name as keyof typeof VOTERS]);
  await page.goto('/polls/2');

  const yes = page.getByRole('button', { name: /^Ja/ });
  await expect(page.getByRole('group', { name: 'Deine Wahl' })).toBeVisible();
  await expect(yes).toHaveAttribute('aria-pressed', 'false');
  // Tab moves through the page until it reaches Yes.
  for (let i = 0; i < 30 && !(await yes.evaluate((el) => el === document.activeElement)); i++) {
    await page.keyboard.press('Tab');
  }
  await expect(yes).toBeFocused();

  await page.keyboard.press('Enter');
  // The toast is a polite live region, so a screen reader reads it without moving the focus.
  const toast = page.getByRole('status');
  await expect(toast).toHaveAttribute('aria-live', 'polite');
  await expect(toast).toHaveText('Stimme registriert: Ja.');
  await expect(yes).toHaveAttribute('aria-pressed', 'true');
  // The swap keeps the focus on the button, so the member can change the vote right away.
  await expect(yes).toBeFocused();

  const no = page.getByRole('button', { name: /^Nein/ });
  await page.keyboard.press('Tab');
  await expect(no).toBeFocused();
  await page.keyboard.press('Space');
  await expect(toast).toHaveText('Stimme geändert: Nein.');
  await expect(no).toHaveAttribute('aria-pressed', 'true');
  await expect(no).toBeFocused();
});

test('the board opens and cancels the close confirmation with the keyboard', async ({ page }) => {
  await signIn(page, ADMIN);
  await page.goto('/polls/1');

  const close = page.getByRole('button', { name: 'Abstimmung abschließen' });
  await close.focus();
  await page.keyboard.press('Enter');
  const confirm = page.getByRole('button', { name: 'Abschließen und Ergebnis anzeigen' });
  await expect(confirm).toBeFocused();

  await page.keyboard.press('Tab');
  await expect(page.getByRole('link', { name: 'Abbrechen' })).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(confirm).toHaveCount(0);
  // Cancelling returns the focus to where the confirmation started.
  await expect(close).toBeFocused();
});

test('an admin opens and cancels the removal of a member with the keyboard', async ({ page }) => {
  await signIn(page, ADMIN);
  await page.goto('/admin/members');

  const row = page.locator(`#m-${LISTED}`);
  const remove = row.getByRole('link', { name: 'Entfernen' });
  await remove.focus();
  await page.keyboard.press('Enter');
  const confirm = row.getByRole('button', { name: 'Ja, entfernen' });
  await expect(confirm).toBeFocused();

  await page.keyboard.press('Tab');
  await expect(row.getByRole('link', { name: 'Abbrechen' })).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(confirm).toHaveCount(0);
  await expect(remove).toBeFocused();
});

test('a member opens and cancels the delegation panel with the keyboard', async ({ page }) => {
  await signIn(page, MEMBER);
  await page.goto('/polls/1');

  const toggle = page.getByRole('button', { name: 'Stimme an ein anderes Mitglied übertragen' });
  await toggle.focus();
  await page.keyboard.press('Enter');
  await expect(toggle).toHaveAttribute('aria-expanded', 'true');
  await expect(toggle).toBeFocused();

  const cancel = page.getByRole('link', { name: 'Abbrechen' });
  await cancel.focus();
  await page.keyboard.press('Enter');
  await expect(toggle).toHaveAttribute('aria-expanded', 'false');
  await expect(toggle).toBeFocused();
});

test('an admin cancels the member form with the keyboard', async ({ page }) => {
  await signIn(page, ADMIN);
  await page.goto('/admin/members');

  const add = page.getByRole('link', { name: 'Mitglied hinzufügen' });
  await add.focus();
  await page.keyboard.press('Enter');
  const cancel = page.locator('#member-form').getByRole('link', { name: 'Abbrechen' });
  await cancel.focus();
  await page.keyboard.press('Enter');
  await expect(page.locator('#member-form')).toHaveCount(0);
  await expect(add).toBeFocused();

  const edit = page.locator(`#m-${LISTED}`).getByRole('link', { name: 'Bearbeiten' });
  await edit.focus();
  await page.keyboard.press('Enter');
  await cancel.focus();
  await page.keyboard.press('Enter');
  await expect(page.locator('#member-form')).toHaveCount(0);
  await expect(edit).toBeFocused();
});

// Poll 3 closed today in the close journey, so it can still be reopened. Poll 4 is the seeded draft.
test('an admin opens and cancels the poll confirmations with the keyboard', async ({ page }) => {
  await signIn(page, ADMIN);
  for (const [path, opener, confirm] of [
    ['/admin/polls/4', 'Löschen', 'Ja, löschen'],
    ['/admin/polls/3', 'Wieder öffnen', 'Ja, wieder öffnen'],
  ]) {
    await page.goto(path);
    const open = page.getByRole('link', { name: opener, exact: true });
    await open.focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('button', { name: confirm })).toBeFocused();

    await page.keyboard.press('Tab');
    await expect(page.getByRole('link', { name: 'Abbrechen' })).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('button', { name: confirm })).toHaveCount(0);
    await expect(open).toBeFocused();
  }
});

test('an admin opens the phone menu and closes it with Escape or a tap beside it', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'a11y-phone', 'Only the phone has the menu.');
  await signIn(page, ADMIN);

  const toggle = page.locator('details.menu summary');
  const members = page.getByRole('navigation', { name: 'Bereiche' }).getByRole('link', { name: 'Mitglieder' });
  // Escape and the tap beside the menu need app.js, which marks the page once it runs.
  await expect(page.locator('html')).toHaveClass(/\bjs\b/);
  await toggle.focus();
  await page.keyboard.press('Enter');
  await expect(members).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(members).toBeHidden();
  await expect(toggle).toBeFocused();

  await toggle.click();
  await expect(members).toBeVisible();
  // The open menu covers the middle of the heading, so the tap goes to its left edge.
  await page.getByRole('heading', { level: 1 }).click({ position: { x: 4, y: 4 } });
  await expect(members).toBeHidden();
});
