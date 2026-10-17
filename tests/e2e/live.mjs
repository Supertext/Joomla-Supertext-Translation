#!/usr/bin/env node
/**
 * End-to-end test against the REAL Supertext API: a fresh demo (nothing translated yet)
 * with a real SUPERTEXT_API_KEY. The editor (a Manager, DEMO_EDITOR_*) selects every English
 * article in the Articles list, opens the plugin's "Supertext" dialog and translates them into
 * every other content language, then the database is checked: every article has exactly one
 * associated translation per language, unpublished, its fields are filled and really in the
 * target language (de-CH without "ß"), formatting and links survived, and translating again
 * (with "overwrite") updates the same articles instead of duplicating them.
 * See "End-to-end tests" in docs/DEVELOPER.md.
 *
 *   JOOMLA_URL=http://127.0.0.1:8081 \
 *   DATABASE_URL=postgresql://postgres:postgres@127.0.0.1:5432/joomla_live \
 *   EDITOR_EMAIL=… EDITOR_PASSWORD=… node tests/e2e/live.mjs
 */
import { chromium } from 'playwright';
import pg from 'pg';
import { mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const B = (process.env.JOOMLA_URL ?? 'http://127.0.0.1:8081').replace(/\/$/, '');
const P = process.env.DB_PREFIX ?? 'jos_';
const SOURCE = process.env.SOURCE_LANGUAGE ?? 'en-GB';
const need = (name) => process.env[name] || (() => { throw new Error(`Set ${name}`); })();
const artifacts = fileURLToPath(new URL('./artifacts/', import.meta.url));
mkdirSync(artifacts, { recursive: true });

const failures = [];
function check(name, ok, detail = '') {
	console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${detail ? ` — ${detail}` : ''}`);
	if (!ok) failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
	return ok;
}

// Common words, to tell the target languages apart. Lower case, whole words.
const STOPWORDS = {
	en: ['the', 'and', 'is', 'are', 'with', 'for', 'you', 'your', 'our', 'of', 'to', 'we', 'who', 'what', 'from', 'in', 'on'],
	de: ['der', 'die', 'das', 'und', 'ist', 'sind', 'mit', 'für', 'sie', 'ihre', 'ihr', 'unser', 'unsere', 'unserer', 'wir', 'eine', 'ein', 'den', 'zu', 'von', 'was', 'in', 'auf'],
	fr: ['le', 'la', 'les', 'et', 'est', 'sont', 'avec', 'pour', 'vous', 'votre', 'vos', 'notre', 'nos', 'nous', 'des', 'une', 'un', 'du', 'de', 'qui', 'que', 'en', 'dans', 'sur'],
	it: ['il', 'lo', 'la', 'gli', 'e', 'è', 'sono', 'con', 'per', 'lei', 'sua', 'suo', 'nostro', 'nostra', 'noi', 'una', 'un', 'del', 'della', 'di', 'che', 'chi', 'in', 'nel', 'nella', 'sul'],
};
const words = (text) => text.toLowerCase().match(/[\p{L}]+/gu) ?? [];
const score = (text, lang) => words(text).filter((w) => STOPWORDS[lang].includes(w)).length;
const plain = (v) => String(v ?? '').replace(/<[^>]+>/g, ' ');
const count = (haystack, needle) => String(haystack ?? '').split(needle).length - 1;
const json = (v) => { try { return JSON.parse(v || '{}') ?? {}; } catch { return {}; } };

// What the plugin translates (plugin/src/Service/ArticleTranslator.php). Custom fields: the demo has none.
const COLUMNS = ['title', 'introtext', 'fulltext', 'metadesc', 'metakey'];
const IMAGE_TEXTS = ['image_intro_alt', 'image_intro_caption', 'image_fulltext_alt', 'image_fulltext_caption'];
const URL_TEXTS = ['urlatext', 'urlbtext', 'urlctext'];
const LEFTOVERS = ['data-st-id', 'data-st-ph', 'st-ph-'];
// Markup and Joomla's plugin tags ({loadposition …}, {loadmodule …}) that must survive, counted.
const KEEP = [['plugin tags', '{'], ['links', '<a '], ['bold', '<strong'], ['italics', '<em'], ['list items', '<li'], ['headings', '<h2']];

/** [label, source value, translated value] of every translated field. */
function fieldsOf(s, t) {
	const out = COLUMNS.map((c) => [c, s[c], t[c]]);
	const [si, ti, su, tu] = [json(s.images), json(t.images), json(s.urls), json(t.urls)];
	for (const k of IMAGE_TEXTS) out.push([`images.${k}`, si[k], ti[k]]);
	for (const k of URL_TEXTS) out.push([`urls.${k}`, su[k], tu[k]]);
	return out;
}

const db = new pg.Client({ connectionString: need('DATABASE_URL') });
await db.connect();
const q = async (sql, params = []) => (await db.query(sql, params)).rows;

/** Translations per source article: language → rows (more than one is a duplicate). */
async function snapshot(sourceIds) {
	const rows = await q(
		`SELECT a.id AS source_id, c.* FROM ${P}associations a
		 JOIN ${P}associations b ON b."key" = a."key" AND b.context = a.context
		 JOIN ${P}content c ON c.id = b.id
		 WHERE a.context = 'com_content.item' AND a.id = ANY($1::int[]) AND c.id <> a.id AND c.state <> -2`,
		[sourceIds],
	);
	const out = new Map(sourceIds.map((id) => [id, {}]));
	for (const r of rows) (out.get(r.source_id)[r.language] ??= []).push(r);
	return out;
}

// --- Setup -------------------------------------------------------------------------------
const sources = await q(`SELECT * FROM ${P}content WHERE language = $1 AND state = 1 ORDER BY id`, [SOURCE]);
const targets = (await q(`SELECT lang_code FROM ${P}languages WHERE published IN (0, 1) AND lang_code <> $1 ORDER BY ordering, lang_code`, [SOURCE])).map((r) => r.lang_code);
const ids = sources.map((s) => s.id);
console.log(`Source (${SOURCE}): ${sources.length} articles; targets: ${targets.join(', ')}`);
if (!check('demo has source articles and target languages', sources.length > 0 && targets.length > 0)) throw new Error('Is this a fresh demo?');
check('fresh demo (nothing translated yet)', (await q(`SELECT COUNT(*)::int AS n FROM ${P}content WHERE language = ANY($1::text[])`, [targets]))[0].n === 0);

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
const page = await browser.newPage({ viewport: { width: 1280, height: 1000 }, locale: 'en-GB' });
page.setDefaultTimeout(30_000);

/** Joomla's own error messages on the current page. */
const joomlaErrors = async () => (await page.locator('joomla-alert[type="danger"], joomla-alert[type="error"], #system-message-container .alert-danger').allTextContents()).map((e) => e.replace(/\s+/g, ' ').trim()).filter(Boolean);

async function translateAll(label, overwrite) {
	await page.goto(`${B}/administrator/index.php?option=com_content&view=articles&filter[search]=&filter[published]=&filter[language]=${SOURCE}&list[limit]=100&list[fullordering]=a.id ASC`);
	await page.waitForLoadState('networkidle');
	check(`${label}: Articles list without errors`, (await joomlaErrors()).length === 0, (await joomlaErrors()).join(' | '));
	for (const id of ids) await page.locator(`input[name="cid[]"][value="${id}"]`).check();
	await page.locator('.supertext-open').first().click();
	const dialog = page.locator('.supertext-dialog');
	await dialog.locator('fieldset, .alert').first().waitFor();
	const before = await dialog.locator('.alert-danger, .alert-warning:not(.supertext-overwrite)').allTextContents();
	check(`${label}: Supertext dialog without errors`, before.length === 0, before.join(' | '));
	for (const tag of targets) {
		const box = dialog.locator(`[id="st-${tag}"]`);
		if (check(`${label}: ${tag} offered in the dialog`, (await box.count()) === 1)) await box.check();
	}
	const warning = dialog.locator('.supertext-overwrite');
	if (overwrite) {
		check(`${label}: overwrite warning shown for existing translations`, await warning.isVisible());
		await dialog.locator('#st-overwrite').check();
	} else {
		check(`${label}: no overwrite warning on a fresh demo`, !(await warning.isVisible()));
	}
	const started = Date.now();
	await dialog.locator('[data-go]').click();
	await dialog.locator('.supertext-results, .alert-danger').first().waitFor({ timeout: 900_000 });
	await page.screenshot({ path: `${artifacts}${label}-result.png`, fullPage: true });
	console.log(`${label}: ${Math.round((Date.now() - started) / 1000)} s`);
	const results = dialog.locator('.supertext-results li');
	const errors = [...await dialog.locator('.alert-danger').allTextContents(), ...await dialog.locator('.supertext-results li.text-danger').allTextContents()];
	const done = await dialog.locator('.supertext-results li.text-success').count();
	const skipped = await dialog.locator('.supertext-results li.text-muted').count();
	check(`${label}: every article translated into every language`, done === ids.length * targets.length, `${done}/${ids.length * targets.length} (${await results.count()} results, ${skipped} skipped)`);
	check(`${label}: no errors in the dialog`, errors.length === 0, errors.map((e) => e.replace(/\s+/g, ' ').trim()).join(' | '));
	// Closing the dialog reloads the Articles list.
	await Promise.all([page.waitForNavigation(), dialog.locator('footer [data-close]').click()]);
	await page.waitForLoadState('networkidle');
	check(`${label}: Articles list without errors afterwards`, (await joomlaErrors()).length === 0, (await joomlaErrors()).join(' | '));
}

// --- Log in as the editor ----------------------------------------------------------------
await page.goto(`${B}/administrator/index.php`);
await page.fill('#mod-login-username', need('EDITOR_EMAIL'));
await page.fill('#mod-login-password', need('EDITOR_PASSWORD'));
await Promise.all([page.waitForNavigation(), page.click('#btn-login-submit')]);
if (!check('editor can log in (DEMO_EDITOR_*)', (await page.locator('#mod-login-username').count()) === 0)) {
	await page.screenshot({ path: `${artifacts}login.png` });
	throw new Error(failures.join('\n'));
}

// --- First translation -------------------------------------------------------------------
await translateAll('first-run', false);
const first = await snapshot(ids);

for (const tag of targets) {
	const lang = tag.split('-')[0].toLowerCase();
	let fields = 0;
	let changed = 0;
	let text = '';
	for (const s of sources) {
		const copies = first.get(s.id)[tag] ?? [];
		if (!check(`${tag}: "${s.title}" has exactly one translation`, copies.length === 1, `${copies.length}`)) continue;
		const t = copies[0];
		check(`${tag}: "${s.title}" is unpublished`, t.state === 0, `state ${t.state}`);
		for (const [field, sv, tv] of fieldsOf(s, t)) {
			if (!sv || !plain(sv).trim()) continue;
			fields++;
			const v = String(tv ?? '');
			if (!check(`${tag}: #${s.id}.${field} filled`, plain(v).trim() !== '')) continue;
			// A single word can be the same in the target language ("Bern"): counted, not failed.
			if (v !== String(sv)) changed++;
			else console.log(`note ${tag}: #${s.id}.${field} unchanged: "${v}"`);
			text += ` ${plain(v)}`;
			const left = LEFTOVERS.filter((l) => v.includes(l));
			check(`${tag}: #${s.id}.${field} has no placeholders left`, left.length === 0, left.join(', '));
			for (const [what, needle] of KEEP) {
				if (count(sv, needle)) check(`${tag}: #${s.id}.${field} keeps its ${what}`, count(v, needle) === count(sv, needle), `${count(v, needle)}/${count(sv, needle)}`);
			}
			for (const href of String(sv).match(/href="[^"]*"/g) ?? []) check(`${tag}: #${s.id}.${field} keeps ${href}`, v.includes(href));
		}
	}
	check(`${tag}: texts were changed by the translation`, fields > 0 && changed / fields >= 0.8, `${changed}/${fields} fields`);
	if (STOPWORDS[lang]) {
		const scores = Object.fromEntries(Object.keys(STOPWORDS).map((l) => [l, score(text, l)]));
		const best = Object.entries(scores).sort((a, b) => b[1] - a[1])[0][0];
		check(`${tag}: text is in the target language`, best === lang, JSON.stringify(scores));
	}
	if (/^de-ch$/i.test(tag)) check(`${tag}: Swiss German spelling (no "ß")`, !text.includes('ß'));
}

// --- Translating again updates in place ---------------------------------------------------
await translateAll('second-run', true);
const second = await snapshot(ids);
for (const tag of targets) {
	for (const s of sources) {
		const before = (first.get(s.id)[tag] ?? []).map((r) => r.id).sort().join(',');
		const after = (second.get(s.id)[tag] ?? []).map((r) => r.id).sort().join(',');
		check(`${tag}: translating "${s.title}" again updates the same article (no duplicates)`, before !== '' && before === after, `${before || '-'} → ${after || '-'}`);
	}
}
const total = (await q(`SELECT COUNT(*)::int AS n FROM ${P}content WHERE language = ANY($1::text[])`, [targets]))[0].n;
check('no extra articles after translating again', total === ids.length * targets.length, `${total}/${ids.length * targets.length}`);

await browser.close();
await db.end();

if (failures.length) {
	console.error(`\n${failures.length} check(s) failed:\n- ${failures.join('\n- ')}`);
	process.exit(1);
}
console.log('\nAll end-to-end checks passed.');
