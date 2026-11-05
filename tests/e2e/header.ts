import { expect, type Page } from '@playwright/test';

const SECTIONS = { Abstimmungen: '/admin/polls', Mitglieder: '/admin/members', Einstellungen: '/admin/settings' };

/**
 * Opens an admin section the way the device offers it: on the phone through the menu, which
 * lists every section, on the desktop through the "Verwaltung" tab and the admin tabs.
 */
export async function openAdmin(page: Page, section: keyof typeof SECTIONS) {
  const menu = page.locator('details.menu');
  if (await menu.isVisible()) {
    await menu.locator('summary').click();
    await menu.getByRole('link', { name: section }).click();
  } else {
    await page.getByRole('link', { name: 'Verwaltung' }).click();
    if (section !== 'Abstimmungen') {
      await page.locator('.tabs').getByRole('link', { name: section }).click();
    }
  }
  await expect(page).toHaveURL(SECTIONS[section]);
}

/** The header's way into the admin area: the menu on the phone, the "Verwaltung" tab on the desktop. */
export function adminEntry(page: Page) {
  return page.locator('details.menu').or(page.getByRole('link', { name: 'Verwaltung' })).filter({ visible: true });
}
