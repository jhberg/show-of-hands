import { expect, test, type Page } from '@playwright/test';

// Members from bin/console seed:demo who take no part in "Mila Sandberg" (poll 2). The journey
// only looks, so it changes nothing that other journeys count.
const WATCHERS = {
  phone: 'pia.lechner@example.org',
  desktop: 'jana.wirth@example.org',
};

async function signIn(page: Page, email: string) {
  await page.goto('/dev-login');
  await page.getByRole('button', { name: email }).click();
  await expect(page).toHaveURL('/');
}

type Recorder = { refreshes: string[]; answered: number };

/**
 * Records in the page every refresh that htmx sends once app.js let it through, and every answer.
 * The swap, if app.js lets it happen, runs in the same task as the answer arrives.
 */
async function recordRefreshes(page: Page) {
  await page.addInitScript(() => {
    const w = window as unknown as Recorder;
    w.refreshes = [];
    w.answered = 0;
    const path = (event: Event) => (event as CustomEvent<{ pathInfo: { requestPath: string } }>).detail.pathInfo.requestPath;
    document.addEventListener('htmx:beforeRequest', (event) => {
      if ((event.target as Element).hasAttribute('data-live')) w.refreshes.push(path(event));
    });
    document.addEventListener('htmx:beforeSwap', (event) => {
      if (path(event).includes('/live')) w.answered++;
    });
  });
}

/**
 * Lets 15 seconds pass and returns the refreshes the page sent meanwhile, once their <main> is in.
 * htmx sends a refresh inside the timer, so the list is complete once the clock has run.
 */
async function refreshesIn15Seconds(page: Page): Promise<string[]> {
  const before = await page.evaluate(() => (window as unknown as Recorder).refreshes.length);
  await page.clock.runFor(15_000);
  await expect.poll(() => page.evaluate(() => {
    const w = window as unknown as Recorder;
    return w.refreshes.length - w.answered;
  })).toBe(0);
  return page.evaluate((from) => (window as unknown as Recorder).refreshes.slice(from), before);
}

test('the poll page refreshes and keeps the open panel and the focus', async ({ page }, testInfo) => {
  await page.clock.install();
  await recordRefreshes(page);
  await signIn(page, WATCHERS[testInfo.project.name as keyof typeof WATCHERS]);
  await page.goto('/polls/2');

  expect(await refreshesIn15Seconds(page)).toEqual(['/polls/2/live']);
  // A refresh that brings nothing new keeps the very same <main>.
  // A property, because an attribute would make it differ from the fresh copy.
  await page.evaluate(() => { (document.querySelector('main') as HTMLElement & { seen?: boolean }).seen = true; });
  expect(await refreshesIn15Seconds(page)).toEqual(['/polls/2/live']);
  expect(await page.evaluate(() => (document.querySelector('main') as HTMLElement & { seen?: boolean }).seen)).toBe(true);

  const toggle = page.getByRole('button', { name: 'Stimme an ein anderes Mitglied übertragen' });
  await toggle.click();
  await expect(toggle).toHaveAttribute('aria-expanded', 'true');
  await expect(toggle).toBeFocused();
  expect(await refreshesIn15Seconds(page)).toEqual(['/polls/2/live?delegate=1']);
  await expect(toggle).toHaveAttribute('aria-expanded', 'true');
  await expect(toggle).toBeFocused();

  // While a person is chosen, the refresh waits, so the choice stays.
  const select = page.getByLabel('An wen?');
  await select.selectOption({ index: 1 });
  const chosen = await select.inputValue();
  expect(await refreshesIn15Seconds(page)).toEqual([]);
  await page.getByRole('button', { name: 'Stimme übertragen', exact: true }).focus();
  expect(await refreshesIn15Seconds(page)).toEqual([]);
  await expect(select).toHaveValue(chosen);

  await select.selectOption('');
  await toggle.focus();
  expect(await refreshesIn15Seconds(page)).toEqual(['/polls/2/live?delegate=1']);

  // A person chosen while a refresh is under way stays chosen too.
  let release = () => {};
  const held = new Promise<void>((resolve) => { release = resolve; });
  await page.route('**/polls/2/live*', async (route) => {
    await held;
    await route.continue();
  });
  const sent = await page.evaluate(() => (window as unknown as Recorder).refreshes.length);
  const underWay = refreshesIn15Seconds(page);
  await expect.poll(() => page.evaluate(() => (window as unknown as Recorder).refreshes.length)).toBe(sent + 1);
  await select.selectOption(chosen);
  release();
  expect(await underWay).toEqual(['/polls/2/live?delegate=1']);
  await expect(select).toHaveValue(chosen);
  await page.unroute('**/polls/2/live*');
  await select.selectOption('');
  await toggle.focus();

  // A hidden page waits, and refreshes at once when it shows again.
  await page.evaluate(() => {
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => true });
    document.dispatchEvent(new Event('visibilitychange'));
  });
  expect(await refreshesIn15Seconds(page)).toEqual([]);
  const before = await page.evaluate(() => (window as unknown as Recorder).refreshes.length);
  await page.evaluate(() => {
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => false });
    document.dispatchEvent(new Event('visibilitychange'));
  });
  await expect.poll(() => page.evaluate((from) => (window as unknown as Recorder).refreshes.slice(from), before)).toEqual(['/polls/2/live?delegate=1']);
  await expect(toggle).toBeFocused();
  await expect(page).toHaveURL('/polls/2');
});
