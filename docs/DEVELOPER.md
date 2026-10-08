# Developer guide — Supertext Translation for Joomla

How the plugin is built, how to work on it, and how it is released and deployed.

## Architecture

One Joomla **system plugin** (`plg_system_supertext`, namespace `Supertext\Plugin\System\Supertext`):

```
Articles list / article editor                  php cli/joomla.php supertext:translate
  "Supertext" toolbar button + <dialog>           (Console\TranslateCommand)
  (media/js/supertext.js)                                  │
        │ POST administrator/index.php?option=com_ajax     │
        │      &group=system&plugin=supertext&format=json  │
        ▼                                                  ▼
Extension\Supertext::handleAjax ──► Service\ArticleTranslator
   describe | translate | test         │ collects fields, one HTML document per article + language
                                       │ Api\HtmlDocument   <div data-st-id="N"> per field
                                       │ Api\SupertextClient POST file → poll → GET → DELETE
                                       ▼
                         com_content ArticleModel::save()  (new or existing translation)
                         + data['associations']            (links source and all translations)
```

| Class / file | Responsibility |
| --- | --- |
| `Extension\Supertext` | Subscribes to `onAfterDispatch` (adds the toolbar button, script options and assets in `com_content` list and edit views), `onAjaxSupertext` (the endpoint: `describe`, `translate`, `test`) and `application.before_execute` (registers the CLI command). Builds the client from the plugin params and the `SUPERTEXT_API_KEY` / `SUPERTEXT_API_ENDPOINT` environment variables. |
| `Service\ArticleTranslator` | Permission checks, which languages exist and are already translated (from `#__associations`), field collection, chunking (< 900k characters per document), writing through the admin `ArticleModel` so versions, workflow, tags, custom fields and associations behave like a manual save. |
| `Api\SupertextClient` | Supertext AI file translation API v1. No Joomla dependency: HTTP goes through a transport callable (`Joomla\Http` in production, a fake in tests). |
| `Api\HtmlDocument` | Builds/parses the HTML document; wraps Joomla plugin tags (`{loadmodule …}`) in `<span translate="no">` and unwraps them afterwards. |
| `Field\ConnectionField` | The *Test connection* button in the plugin settings. |
| `Console\TranslateCommand` | `supertext:translate <ids>… [--to=tag]… [--overwrite] [--user=name]` |
| `media/js/supertext.js`, `media/css/supertext.css`, `media/joomla.asset.json` | The dialog (native `<dialog>`, Joomla's Bootstrap classes); registered as web assets `plg_system_supertext.dialog`. |
| `language/{en-GB,de-DE,de-CH,fr-FR,it-IT}` | UI strings (plugin-local language files, loaded with `autoloadLanguage`). |
| `Service\Messages` | Shows a `SupertextException` from the API client in the user's language (`PLG_SYSTEM_SUPERTEXT_API_<REASON>`), falling back to the client's English message. |

### What is translated

| Source | Sent as |
| --- | --- |
| `title`, `metadesc`, `metakey` | plain text |
| `introtext`, `fulltext` | HTML, one segment each (plugin tags protected) |
| `images`: `image_intro_alt`, `image_intro_caption`, `image_fulltext_alt`, `image_fulltext_caption` | plain text |
| `urls`: `urlatext`, `urlbtext`, `urlctext` | plain text |
| Custom fields `text`, `textarea` | plain text |
| Custom fields `editor` | HTML |

Everything else is copied for a **new** translation: category (or the category associated with it in the target language), access, featured, `attribs`, `metadata`, note, tags, other custom field values. New translations get `state` from the *Status of new translations* setting and an alias from the translated title (made unique in the category with `-2`, `-3`…). **Overwriting** an existing translation replaces only the translated fields (and custom fields); its alias, category, state and settings stay.

Each field is one `<div data-st-id>`. The live API translates every such element on its own, so a whole article text must stay in **one** element: splitting a sentence across several `data-st-id` elements breaks the translation at the boundaries.

### Associations and the open editor

`ArticleModel::save()` with `data['associations'] = [tag => id, …]` (source plus existing translations, minus the target) re-links all of them under one association key. When the dialog runs in the article editor, `supertext.js` also writes the new IDs into the editor's *Associations* fields (`#jform_associations_<tag>_id`), because saving the source article afterwards would otherwise post the old associations and unlink the new translations.

## Supertext API protocol

Shared with the WordPress plugin and every other Supertext CMS plugin:

1. `POST {base}translate/ai/file`: multipart with `file` (part `Content-Type` exactly `text/html`, no charset, or the API answers 415), `target_lang` (BCP-47, e.g. `de-CH`), optional `source_lang` (primary subtag only, e.g. `en`, or the pair is rejected), optional `politeness` (`more`/`less`). Returns `{file_id}`.
2. `GET …/{file_id}/status` until `done` (`error`, `limit_exceeded`, `deleted` are terminal).
3. `GET …/{file_id}/translation` returns the translated HTML.
4. `DELETE …/{file_id}` (files also expire after 24 h).

Auth header: `Authorization: Supertext-Auth-Key <key>`. The header name must be `Authorization` (`Authentication` gets 403). Supertext shows the key with the prefix, so the client strips a pasted `Supertext-Auth-Key ` and always sends exactly one. Base URLs: `https://api.supertext.com/v1/` (live), `https://api.staging.supertext.com/v1/`, `https://api.testing.supertext.com/v1/`. `GET features` is a cost-free key check (*Test connection*).

**Rate limit:** the API limits requests per second per key (HTTP 429, `RATE_LIMIT_EXCEEDED`). The client retries a 429 up to 4 times, waiting for `Retry-After` if sent, otherwise 1, 2, 4 and 8 seconds plus jitter. Languages are translated one after another.

**`translate="no"`** is respected by the API; the plugin uses it for Joomla plugin tags.

The multipart body is built by hand (`SupertextClient::submit()`): `Joomla\Http`'s curl transport only sends arrays as multipart when you set the `Content-Type` yourself, and then curl adds no boundary.

## Local development

You need PHP 8.3, Composer and a database (Joomla doesn't support SQLite; PostgreSQL or MySQL/MariaDB).

```bash
# Joomla
curl -LO https://github.com/joomla/joomla-cms/releases/download/6.1.4/Joomla_6.1.4-Stable-Full_Package.zip
mkdir site && cd site && unzip -q ../Joomla_6.1.4-Stable-Full_Package.zip
php installation/joomla.php install -n --site-name="Dev" --admin-user="Dev" --admin-username=dev \
  --admin-password='choose-a-long-one' --admin-email=dev@example.com \
  --db-type=pgsql --db-host=127.0.0.1:5432 --db-user=postgres --db-pass=… --db-name=joomla --db-prefix=jos_
php cli/joomla.php config:set live_site=http://127.0.0.1:8091   # needed with php -S

# The plugin, linked so edits are live
ln -s /path/to/this/repo/plugin plugins/system/supertext
ln -s /path/to/this/repo/plugin/media media/plg_system_supertext
php cli/joomla.php extension:discover && php cli/joomla.php extension:discover:install

# Optional: the demo setup (languages, home pages, sample articles, accounts)
ln -s /path/to/this/repo/demo/plg_console_supertextdemo plugins/console/supertextdemo
php cli/joomla.php extension:discover && php cli/joomla.php extension:discover:install
php cli/joomla.php extension:list --type=plugin | grep -i supertext   # enable both with extension:enable <id>
php cli/joomla.php supertext:demo-setup   # language packs: see demo/Dockerfile

SUPERTEXT_API_KEY=… php -S 127.0.0.1:8091
```

To work without a real key, run the stand-in API (`cd tests/docs && node stand-in.mjs`) and start PHP with `SUPERTEXT_API_KEY=anything SUPERTEXT_API_ENDPOINT=http://127.0.0.1:8765/v1/`. It returns real German, French and Italian for the demo's sample articles and `[de-CH] …`-prefixed text for anything else.

## Tests

```bash
composer install
composer test          # PHPUnit: API client (protocol, auth header, 429 retries, errors), HtmlDocument and the language files
./build.sh             # dist/plg_system_supertext-<version>.zip
```

CI (`.github/workflows/ci.yml`) on every push and pull request:

- **unit**: PHP lint on 8.2, 8.3 and 8.4, PHPUnit, `sh -n demo/entrypoint.sh`, package build.
- **joomla**: installs Joomla 6.1 on PostgreSQL, installs the built package and the demo setup plugin, runs `supertext:demo-setup`, then translates the sample articles with `supertext:translate` against the stand-in API and checks the German article and its association in the database.

## Demo (Railway)

The public demo is a container built from `demo/Dockerfile`: Joomla 6.1 with English, German (Switzerland), French and Italian, two English sample articles, and this plugin. It runs on Railway in the `supertext-cms-demos` project, service `Joomla`, region EU West (Amsterdam): <https://joomla-production-096c.up.railway.app/> (backend: `/administrator/`). The database is a `joomla` database on the project's PostgreSQL service.

**Deploys:** Railway watches `main` of this repository (`railway.json` points it at `demo/Dockerfile`) and rebuilds on every push.

**What's in `demo/`:**

| File | Purpose |
| --- | --- |
| `Dockerfile` | `php:8.3-apache` + extensions; downloads Joomla (into `/var/www/joomla`) and the language packs at build time; packages this plugin and the demo setup plugin as zips |
| `entrypoint.sh` | First start (empty database): creates the database if missing and installs Joomla with a throwaway installer account. Every start: restores `configuration.php` from the database, installs the language packs, this plugin and the demo setup plugin, then runs `supertext:demo-setup` |
| `state.php` | Saves/restores `configuration.php` in the table `supertext_demo_state` of the Joomla database |
| `plg_console_supertextdemo/` | Demo-only console plugin: `supertext:demo-setup` (accounts, languages, Language Filter with associations, a home page per language, language switcher, sample articles; switches off the welcome tour and the statistics prompt) |
| `createdb.php` | Creates the Joomla database on the server from `DATABASE_URL` |
| `apache.conf`, `php.ini` | Web server and PHP settings |
| `.env.example` | The variables below |

**No volume:** Railway allows only three volumes per project and they are taken, so the container keeps no files. Everything that must survive a deploy is in PostgreSQL: the content, the plugin settings and `configuration.php` (in `supertext_demo_state`). Language packs and the plugins are reinstalled from the image on every start, which also updates the plugin. Consequences: media uploaded in the demo's backend disappear with the next deploy (the sample articles use Joomla's bundled images), and Joomla itself is updated by changing `JOOMLA_VERSION` in the `Dockerfile`, not from the backend (after a version change, run `php cli/joomla.php maintenance:database` once if Joomla reports database problems). To reset the demo, drop the `joomla` database and redeploy.

**Service variables:**

| Variable | |
| --- | --- |
| `DATABASE_URL` | `${{Postgres.DATABASE_URL}}`; the Joomla database (`JOOMLA_DB_NAME`, default `joomla`) is created on that server if missing |
| `DEMO_ADMIN_EMAIL`, `DEMO_ADMIN_PASSWORD` | Super User (the e-mail address is also the username) |
| `DEMO_EDITOR_EMAIL`, `DEMO_EDITOR_PASSWORD` | Optional second account for automated tests and screenshots. Joomla's *Editor* group has no backend access, so this account is a **Manager** (backend, articles, no system settings). |
| `SUPERTEXT_API_KEY` | Supertext key used by the plugin (wins over the plugin setting) |
| `SUPERTEXT_API_ENDPOINT` | Optional, e.g. the staging API |
| `JOOMLA_SITE_NAME`, `JOOMLA_DB_NAME`, `JOOMLA_DB_PREFIX` | Optional, first boot only |
| `PORT` | Port Apache listens on (Railway sets it; the domain's target port must match) |

**Demo accounts:** on every boot `supertext:demo-setup` creates the `DEMO_ADMIN` and `DEMO_EDITOR` accounts if no account with that e-mail address or username exists. Existing accounts are never changed; change passwords in the backend. Passwords must meet Joomla's rules (*Users → Options → Password Options*; by default at least 12 characters). If one doesn't, that account is skipped with a warning naming the variable and the rule; the demo still starts. Joomla's installer needs a Super User, so the first start creates one with a random name and password that are never shown; it is deleted as soon as the `DEMO_ADMIN` account exists. Set `DEMO_ADMIN_*` before the first deploy (or add it later and redeploy). There is no web installer or "create admin" screen.

**Run it locally:**

```bash
docker build -f demo/Dockerfile -t supertext-joomla-demo .
docker run --rm -p 8080:80 \
  -e DATABASE_URL=postgresql://user:pass@host.docker.internal:5432/postgres \
  -e DEMO_ADMIN_EMAIL=you@example.com -e DEMO_ADMIN_PASSWORD='a-long-password-1' \
  -e SUPERTEXT_API_KEY=… supertext-joomla-demo
# site http://localhost:8080/  backend http://localhost:8080/administrator/
```

## Docs screenshots

The images in `docs/images/` are generated by `tests/docs/screenshots.mjs` (Playwright) from a freshly set-up demo whose plugin talks to `tests/docs/stand-in.mjs`. The stand-in returns German, French and Italian for the sample articles (`samples.json`, real Supertext output). Regenerate them whenever a screen they show changes:

```bash
cd tests/docs && npm install && npx playwright install chromium
npm run stand-in &
# a fresh demo (entrypoint or the local setup above, with supertext:demo-setup and DEMO_* set),
# served with SUPERTEXT_API_ENDPOINT=http://127.0.0.1:8765/v1/ and *no* SUPERTEXT_API_KEY:
# the script enters a key in the plugin settings itself
BASE_URL=http://127.0.0.1:8092 DEMO_ADMIN_EMAIL=… DEMO_ADMIN_PASSWORD=… \
  DEMO_EDITOR_EMAIL=… DEMO_EDITOR_PASSWORD=… npm run screenshots
```

Start from a database without translations of the sample articles.

## Releasing

Releases are published by `.github/workflows/release.yml` when the version is officially bumped; nobody tags or creates releases by hand.

1. Check that `composer test` and `./build.sh` pass.
2. Move the *Unreleased* entries in `CHANGELOG.md` under a new `## X.Y.Z — YYYY-MM-DD` section, and keep an empty *Unreleased* above it.
3. Set the same version in:
   - `plugin/supertext.xml`: `<version>`, shown in Joomla's extension manager
   - `plugin/media/joomla.asset.json`: `version` of the web assets
4. Push to `main`. The workflow checks that the version files match `CHANGELOG.md`, then tags `vX.Y.Z` and creates the GitHub release with the CHANGELOG section as notes (0.x versions as pre-releases). A push that adds no new version does nothing, and a version that is already released is skipped. After fixing a failed run, start it again with *Run workflow* on the *Release* workflow.

The release attaches `plg_system_supertext-X.Y.Z.zip`, built by `./build.sh`.
## Conventions

- Joomla coding standard (PSR-12 based), namespaced plugin structure (`services/provider.php`, `src/`), `\defined('_JEXEC') or die;` in files that use Joomla.
- Keep `src/Api` free of Joomla classes so it stays unit-testable.
- User-visible strings in the language files: `plugin/language/{en-GB,de-DE,de-CH,fr-FR,it-IT}/plg_system_supertext.ini` (and `.sys.ini` for the extension manager). New or changed strings need English, German (both `de-DE` and `de-CH`: `ss` instead of `ß`, `«»` quotes), French and Italian in the same commit; `tests/LanguageFilesTest.php` checks that every file has the same keys, placeholders and URLs as `en-GB`. Formal address (Sie, vous, Lei) and Joomla's own terms in each language (*Beitrag*, *article*, *articolo*; *Versteckt*, *Dépublié*, *Non pubblicato*).
- The API client (`src/Api`, no Joomla classes) throws `SupertextException` with an English message and a `reason` (e.g. `limit_exceeded`); `Service\Messages` shows the `PLG_SYSTEM_SUPERTEXT_API_<REASON>` string instead. A new reason needs that string in all five files.
- Keep the three docs in `docs/` current with every change (see `CLAUDE.md`).

## Known limitations / roadmap

- Articles only. Categories, menu items, modules and custom field labels are not translated yet.
- Translation runs inside the request (up to the timeout per language). Planned: a queue via Joomla's Task Scheduler for many articles.
- Not yet tested on Joomla 5.4, or on MySQL/MariaDB (developed and tested on PostgreSQL).
- Subform and repeatable custom fields are copied, not translated.
- Not yet in the Joomla Extensions Directory; no update server yet.
- Human (professional) translation orders are not supported yet (the WordPress plugin has them).
