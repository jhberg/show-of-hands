import { expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/** Panels, notices, and confirmations fade in; axe would measure their colours halfway, so it waits. */
export async function noViolations(page: Page) {
  await page.waitForFunction(() => document.getAnimations().length === 0);
  expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
}
