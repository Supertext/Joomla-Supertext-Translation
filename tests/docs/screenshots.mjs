#!/usr/bin/env node
/**
 * Regenerates docs/images from a freshly installed demo whose plugin talks to stand-in.mjs.
 * See docs/DEVELOPER.md -> Docs screenshots.
 *
 *   BASE_URL (default http://127.0.0.1:8092)
 *   DEMO_ADMIN_EMAIL / DEMO_ADMIN_PASSWORD     settings screens (Super User)
 *   DEMO_EDITOR_EMAIL / DEMO_EDITOR_PASSWORD   translating (Manager)
 *   PUBLIC_URL (default the Railway demo)      shown instead of BASE_URL in addresses on screen (the ping URL)
 *
 * Start the site with SUPERTEXT_REPORT_URL pointing at the stand-in (http://127.0.0.1:8765/report),
 * so saving the settings reports to the stand-in, not to Supertext's Commando Center.
 */
import { chromium } from 'playwright'

const B = process.env.BASE_URL || 'http://127.0.0.1:8092'
const PUBLIC = process.env.PUBLIC_URL || 'https://joomla-production-096c.up.railway.app'
const OUT = new URL('../../docs/images', import.meta.url).pathname
const pad = (r, p = 8) => ({ x: Math.max(0, r.x - p), y: Math.max(0, r.y - p), width: r.width + 2 * p, height: r.height + 2 * p })
const union = (...rs) => {
  const x = Math.min(...rs.map((r) => r.x)), y = Math.min(...rs.map((r) => r.y))
  return { x, y, width: Math.max(...rs.map((r) => r.x + r.width)) - x, height: Math.max(...rs.map((r) => r.y + r.height)) - y }
}

const browser = await chromium.launch()

async function session(email, password) {
  const page = await (await browser.newContext({ viewport: { width: 1280, height: 860 } })).newPage()
  await page.goto(`${B}/administrator/index.php`)
  await page.fill('#mod-login-username', email)
  await page.fill('#mod-login-password', password)
  await page.click('#btn-login-submit')
  await page.waitForLoadState('networkidle')
  // Collapse the sidebar so the article tables fit.
  await page.getByText('Toggle Menu', { exact: true }).first().click()
  await page.waitForTimeout(300)
  const shot = async (name, clip) => {
    await page.addStyleTag({ content: '*{animation:none!important;transition:none!important} .header .header-item-content .header-item-text{visibility:visible}' })
    await page.waitForTimeout(300)
    await page.screenshot({ path: `${OUT}/${name}.png`, ...(clip ? { clip } : {}) })
  }
  const box = async (selector) => pad(await page.locator(selector).first().boundingBox())
  const go = async (url) => { await page.goto(B + url); await page.waitForLoadState('networkidle') }
  return { page, shot, box, go }
}

// --- Installation guide (Super User); also saves the API key the user guide needs ---
{
  const { page, shot, box, go } = await session(process.env.DEMO_ADMIN_EMAIL, process.env.DEMO_ADMIN_PASSWORD)

  await go('/administrator/index.php?option=com_plugins&view=plugins&filter[search]=Supertext')
  await page.locator('a', { hasText: 'System - Supertext Translation' }).first().click()
  await page.waitForLoadState('networkidle')
  // Enter a key (the stand-in accepts any), save, then test it.
  await page.locator('#jform_params_api_key_lock').click()
  await page.locator('#jform_params_api_key').fill('Supertext-Auth-Key docs-demo-key')
  await page.getByRole('button', { name: 'Save', exact: true }).click()
  await page.waitForLoadState('networkidle')
  // Saving runs the automatic key check and shows its result.
  await page.locator('joomla-alert, .alert', { hasText: 'API key checked' }).first().waitFor()
  const tested = page.waitForResponse((r) => r.url().includes('option=com_ajax'))
  await page.locator('button:has-text("Test connection")').click()
  await tested
  await page.locator('[id$="-result"]', { hasText: 'API key checked: the connection to Supertext works.' }).waitFor()
  // No local addresses on screen: show the ping URL with the public demo's address.
  await page.evaluate(([local, pub]) => document.querySelectorAll('.supertext-reporting code').forEach((c) => { c.textContent = c.textContent.replace(local, pub) }), [B, PUBLIC])
  await page.setViewportSize({ width: 1280, height: 1400 }) // the settings run below the default viewport
  await shot('plugin-settings', union(await box('joomla-tab[view="tabs"] [role="tablist"], #myTab [role="tablist"], [role="tablist"]'), await box('.supertext-reporting')))
  await page.setViewportSize({ width: 1280, height: 860 })
  await page.getByRole('tab', { name: 'Languages' }).click()
  // One select per content language, preselected with the suggested code: send French as Swiss French, formal.
  await page.locator('select[name="jform[params][languages][fr-FR][code]"]').selectOption('fr-CH')
  await page.locator('select[name="jform[params][languages][fr-FR][politeness]"]').selectOption('more')
  await page.getByRole('button', { name: 'Save', exact: true }).click()
  await page.waitForLoadState('networkidle')
  await page.getByRole('tab', { name: 'Languages' }).click()
  await page.waitForTimeout(300)
  await shot('plugin-languages', union(await box('[role="tablist"]'), await box('table.supertext-mapping')))
  await page.locator('joomla-toolbar-button button', { hasText: /^\s*Close\s*$/ }).click()
  await page.waitForLoadState('networkidle')

  await go('/administrator/index.php?option=com_languages&view=languages')
  await shot('content-languages', union(await box('#subhead-container'), await box('table.table')))

  await go('/administrator/index.php?option=com_plugins&view=plugins&filter[search]=Language Filter')
  await page.locator('a', { hasText: 'System - Language Filter' }).first().click()
  await page.waitForLoadState('networkidle')
  const assoc = page.locator('.control-group', { has: page.locator('label', { hasText: /Item Associations/ }) }).first()
  await assoc.scrollIntoViewIfNeeded()
  await shot('language-filter', pad(await assoc.boundingBox(), 12))
  await page.locator('joomla-toolbar-button button', { hasText: /^\s*Close\s*$/ }).click()
}

// --- User guide (editor account) -------------------------------------------------
{
  const { page, shot, box, go } = await session(process.env.DEMO_EDITOR_EMAIL, process.env.DEMO_EDITOR_PASSWORD)
  await go('/administrator/index.php?option=com_content&view=articles&filter[search]=&list[fullordering]=a.id ASC')
  const row = page.locator('tr', { hasText: 'Swiss chocolate' })
  await row.locator('input[name="cid[]"]').check()
  await shot('articles-list', union(await box('#subhead-container'), await box('table.table')))

  await page.locator('.supertext-open').click()
  await page.locator('.supertext-dialog fieldset').waitFor()
  await shot('translate-dialog', await box('.supertext-dialog'))

  await page.locator('.supertext-dialog [data-go]').click()
  await page.locator('.supertext-results').waitFor({ timeout: 120000 })
  await shot('translate-results', await box('.supertext-dialog'))
  await page.locator('.supertext-dialog footer [data-close]').click()
  await page.waitForLoadState('networkidle')
  await shot('articles-translated', union(await box('#subhead-container'), await box('table.table')))

  // The German translation in the editor
  await go('/administrator/index.php?option=com_content&view=articles&filter[language]=de-CH')
  await page.locator('a[href*="task=article.edit"]', { hasText: 'Schweizer Schokolade' }).first().click()
  await page.waitForLoadState('networkidle')
  await page.waitForTimeout(1500) // TinyMCE
  await shot('translated-article', { x: 0, y: 60, width: 1280, height: 640 })
  await page.locator('joomla-toolbar-button button', { hasText: /^\s*Close\s*$/ }).click()
  await page.waitForLoadState('networkidle')
  await go('/administrator/index.php?option=com_content&view=articles&filter[language]=')

  // From the editor of the English article: existing translations and the overwrite warning
  const english = page.locator('tr', { hasText: 'Swiss chocolate' }).locator('a[href*="task=article.edit"]').first()
  await english.click()
  await page.waitForLoadState('networkidle')
  await shot('editor-button', await box('#subhead-container'))
  await page.locator('.supertext-open').click()
  await page.locator('.supertext-dialog fieldset').waitFor()
  await page.locator('#st-de-CH').check()
  await page.locator('#st-overwrite').waitFor({ state: 'visible' })
  await shot('overwrite-warning', await box('.supertext-dialog'))
  await page.locator('.supertext-dialog header [data-close]').click()
  await page.locator('joomla-toolbar-button button', { hasText: /^\s*Close\s*$/ }).click()
  await page.waitForLoadState('networkidle')
}

await browser.close()
console.log(`Screenshots written to ${OUT}`)
