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
   export | import (files)             │   (export/import: Api\FileTransfer, the SDK's TranslationFiles, no API call)
                                       │ Api\SupertextClient (bundled Supertext SDK):
                                       │   <div data-st-id="N"> per field, POST file → poll → GET → DELETE
                                       ▼
                         com_content ArticleModel::save()  (new or existing translation)
                         + data['associations']            (links source and all translations)
```

| Class / file | Responsibility |
| --- | --- |
| `Extension\Supertext` | Subscribes to `onAfterDispatch` (adds the toolbar button, script options and assets in `com_content` list and edit views), `onAjaxSupertext` (the endpoint: `describe`, `translate`, `test`, `export`, `import`; on the site: the ping), `onAfterInitialise` (answers the ping before routing), `onExtensionBeforeSave` (keeps the locked API key), `onExtensionAfterSave` (key check and report after saving the settings), `onPageCacheIsExcluded` (no page cache for the ping) and `application.before_execute` (registers the CLI command). Builds the client from the plugin params and the `SUPERTEXT_API_KEY` / `SUPERTEXT_API_ENDPOINT` / `SUPERTEXT_REVIEW_URL` environment variables, and the Commando Center glue (`reporting()`). |
| `Service\ArticleTranslator` | Permission checks, which languages exist and are already translated (from `#__associations`), field collection, writing through the admin `ArticleModel` so versions, workflow, tags, custom fields and associations behave like a manual save. |
| `Api\SupertextClient` | Wrapper around the bundled Supertext SDK (`src/Sdk`): documents below 900k characters, protocol, retries; maps SDK error codes to reasons; `forReview()` for languages with *Translation review*. No Joomla dependency: HTTP goes through a transport callable (`Joomla\Http` in production, a fake in tests; `Api\CallableTransport`). |
| `Sdk\…` | Bundled copy of the Supertext PHP SDK, namespace `Supertext\Plugin\System\Supertext\Sdk` (see *Supertext SDK*). Never edit. |
| `PluginHealth\…` | Bundled copy of Supertext Plugin Health (PHP), namespace `Supertext\Plugin\System\Supertext\PluginHealth` (see *Supertext Plugin Health*). Never edit. |
| `Api\Languages` | Content language → Supertext code with the SDK's `V1\Languages` (the Languages tab's choice, else an exact Supertext code, else the SDK's default, else `unsupported_language`), the source, the tone check (`unsupported_feature`), reading the saved tab (`settings()`, also the 0.1.0 rows) and validating it for saving (`clean()`); no Joomla dependency. |
| `Api\Reporting`, `Api\StateStorage`, `Api\ReportTransport` | Commando Center: version reports, the API key check and the ping answer, on Supertext Plugin Health's `InstallationReporter` with the SDK's key check; never throws. No Joomla dependency. |
| `Service\ExtensionStateStorage` | Keeps the reporter's state (JSON) and the last site URL in the plugin's `#__extensions.custom_data`. |
| `Api\FileTransfer`, `Api\FileTransferException` | *Export for translation* / *Import translation* on the SDK's `Files\TranslationFiles` (see *Translation files*); no Joomla dependency. |
| `Api\HtmlDocument` | Wraps Joomla plugin tags (`{loadmodule …}`) in `<span translate="no">` and unwraps them afterwards. |
| `Field\ConnectionField` | *API key check* in the plugin settings: the latest check with its time and the *Test connection* button; schedules the back-end report check. |
| `Field\ReportingField` | Under *Send version information to Supertext*: what is sent, a `SUPERTEXT_VERSION_REPORTS` override, the ping address. |
| `Field\LanguagemappingField` | The Languages tab: one row per content language with a native select of Supertext's codes (`targetOptions()`, named with PHP intl in the user's language, code in brackets), preselected with the saved code, else `suggestTarget()`, else *— choose —* (with a warning), and the tone select, shown only where the code offers it (inline script). `filter()` saves only `Languages::clean()`. |
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

### Translation files (export for translation, import)

The dialog's tabs *Export for translation* and *Import translation* use the SDK's `Files\TranslationFiles` (SDK 0.5.0; XLIFF 1.2 and HTML, outside `V1`, no API call). The glue is `Api\FileTransfer` (no Joomla classes, unit-tested in `tests/FileTransferTest.php`); `ArticleTranslator` gives it the segments and the write-back:

- **Segments:** `ArticleTranslator::collect()`, the same collector as for API translations (keys `article.title`, `article.introtext`, `images.image_intro_alt`, `urls.urlatext`, `field.<name>` …, plugin tags protected), with blank fields left out the same way (`FileTransfer::segments()`). So an import reuses the write-back (`ArticleTranslator::save()`).
- **Job metadata:** `plugin` = `Joomla-Supertext-Translation`; `reference` = `article:<source article id>:<target content language tag>`, e.g. `article:12:fr-FR` (`FileTransfer::reference()`/`parseReference()`); `sourceLanguage`/`targetLanguage` = the codes `Api\Languages` resolves with the Languages tab's mapping, else the tag itself (`_` → `-`), so an unmapped language never blocks an export.
- **Export** (`action=export`, POST `id`, `targets[]`, `format=xliff|html`): XLIFF with one `<file>` per target language, or HTML for exactly one. `Extension\Supertext::sendExport()` answers with the file itself (`Content-Disposition: attachment`, `application/x-xliff+xml` or `text/html; charset=utf-8`, named `joomla-<id>-<tag>.xlf`, `joomla-<id>.xlf` for several languages, `.html`), bypassing com_ajax's JSON; errors come back as com_ajax JSON. `supertext.js` downloads it through a blob URL.
- **Import** (`action=import`, multipart `file`, `include_changed`, `overwrite`): `importUpload()` refuses files over 10 MB (`FileTransfer::MAX_UPLOAD_BYTES`, also PHP's `UPLOAD_ERR_INI_SIZE`) before reading, reads the temporary upload in memory (never stored), then `FileTransfer::import()`: `TranslationFiles::read()`, and per job: `plugin` must be ours (`other_system`), the reference must parse (`not_found`), the article must exist (`not_found`) and the language must be a content language other than the article's (`no_language`); the user needs the same permissions as for translating (`assertCanRead()`, then `prepareTarget()`: Create in the category or Edit on the translation). `importTranslations()` with the freshly collected segments; `ok` (and `source_changed` with *Also import texts whose source changed*) are written, `untranslated`, `invalid_markup`, `missing` never. The write-back is `ArticleTranslator::save()` with `$keepTarget`: an existing translation is only replaced with *Overwrite existing translations* (else `skipped`), and keeps its own values for keys the file doesn't translate (custom fields not in `com_fields` are left alone by Joomla); a new one is created unpublished (setting) like an API translation.
- **Summary:** per job `counts` (`imported`, `changed`, `untranslated`, `invalid`, `missing`), `saved` (`created`/`updated`/`skipped`, id, title) or `message`. Errors are `FileTransferException` reasons shown as `PLG_SYSTEM_SUPERTEXT_FILE_<REASON>` (`Service\Messages`).
- Not covered by the CI job (it would need a backend login); `tests/docs/screenshots.mjs` runs an HTML export → translate → import round trip against a real Joomla.

## Supertext API protocol

Shared with every Supertext plugin. Every call goes through the Supertext SDK (see *Supertext SDK*); this is what it does:

1. `POST {base}translate/ai/file`: multipart with `file` (part `Content-Type` exactly `text/html`, no charset, or the API answers 415), `target_lang` (a Supertext target code, e.g. `de-CH`, `es`; see *Language codes*), optional `source_lang` (bare language, e.g. `en`), optional `politeness` (`more`/`less`). Returns `{file_id}`.
2. `GET …/{file_id}/status` until `done` (`error`, `limit_exceeded`, `deleted` are terminal).
3. `GET …/{file_id}/translation` returns the translated HTML.
4. `DELETE …/{file_id}` (files also expire after 24 h).

Auth header: `Authorization: Supertext-Auth-Key <key>`. The header name must be `Authorization` (`Authentication` gets 403). Supertext shows the key with the prefix, so the client strips a pasted `Supertext-Auth-Key ` and always sends exactly one. Base URLs: `https://api.supertext.com/v1/` (live), `https://api.staging.supertext.com/v1/`, `https://api.testing.supertext.com/v1/`. `GET features` is a cost-free key check (*Test connection*).

**Rate limit:** the API limits requests per second per key (HTTP 429, `RATE_LIMIT_EXCEEDED`). The client retries a 429 up to 4 times, waiting for `Retry-After` if sent, otherwise 1, 2, 4 and 8 seconds plus jitter. Languages are translated one after another.

**`translate="no"`** is respected by the API; the plugin uses it for Joomla plugin tags.

User-Agent: `Joomla-Supertext-Translation/<version>` (the version from `plugin/supertext.xml`, as in the reports).

### Translation review

Per content language (`review` in the Languages tab's settings, `Field\LanguagemappingField`, off by default; settings saved before have none and read as off): `ArticleTranslator` takes `SupertextClient::forReview($settings[$tag]['review'])` for each target language, a second SDK client with the same key, transport, timeouts and User-Agent whose base URL is the setting `review_url` (`SUPERTEXT_REVIEW_URL` wins; never saved = `SupertextClient::REVIEW_URL`, the translation proxy of [Supertext Localization Review](https://github.com/Supertext/Localization-Review-Supertext-Translation)). It is created once per request. The SDK's `translateHtml` runs the whole round trip on one client, so a job is always collected (status, download, delete) with the client that submitted it. The proxy speaks the same API v1, forwards to the live Supertext API with the plugin's key, stores every segment per key and serves reviewers' corrections on the next upload, so nothing else changes in the plugin. `review_url` saved empty (or equal to the API URL) means `forReview()` always returns the direct client. The key check (*Test connection*, on saving), the version reports and the translation files (`Api\FileTransfer`, no API calls) never use the review service. Tests: `SupertextClientTest` (all four calls of a review language to the review URL, the other language directly, key check directly, empty URL or the API's own URL = off, the manifest's default is `REVIEW_URL`, the User-Agent's version) and `LanguagesTest` (`review` read, saved and off by default).

The SDK builds the multipart body itself and passes it as a string with its boundary in `Content-Type`: `Joomla\Http`'s curl transport only sends arrays as multipart when you set the `Content-Type` yourself, and then curl adds no boundary.

### Supertext SDK

The protocol, the retries, the language list, the API key check (`Client::checkApiKey()`, `Protocol::apiKeyFingerprint()`) and the `data-st-id` document come from the official Supertext PHP SDK ([Supertext/Supertext-API-SDK-and-Guide](https://github.com/Supertext/Supertext-API-SDK-and-Guide), MIT; 0.5.0): it provides the client, the key check and the translation files (`Sdk\Files`, see *Translation files*). The version reports are no longer in the SDK (since 0.3.0) but in *Supertext Plugin Health* (below). The plugin ships a copy in `plugin/src/Sdk/` with its namespace changed from `Supertext\Sdk` to `Supertext\Plugin\System\Supertext\Sdk`, so Joomla's autoloader for the plugin (`<namespace path="src">`) loads it, sites install nothing extra and another extension that ships the SDK itself can't clash with it. Everything that talks to the API is in its `V1` namespace (`Sdk\V1\Client`, `Languages`, `ApiKeyCheck`, …); only the HTTP transport (`Sdk\Http`), the translation files (`Sdk\Files`) and `Sdk\Supertext::SDK_VERSION` are outside. `scripts/vendor-sdk.sh` rewrites the namespace in every file of the copy, `Files\` included. `plugin/src/Sdk/COMMIT` names the SDK revision.

- Never edit `plugin/src/Sdk/`. Fix the SDK in its repository, then run `scripts/vendor-sdk.sh [ref]` (default `main`; `SDK_DIR=/path/to/clone` uses a local clone) and the tests.
- `Api\SupertextClient` keeps the plugin's interface (`translateDocument`, `translateSegments`, `validateApiKey`, `checkApiKey`, transport callable) and turns every SDK error code into a `SupertextException` reason (`REASONS`, `fromSdk()`). A new SDK error code needs a reason there and its `PLG_SYSTEM_SUPERTEXT_API_<REASON>` string in all five language files.
- PHPStan analyses `plugin/src/`, the SDK copy included (it passes level 8 in its own repository).
- The live API answers an unknown language (`xx-YY`) with HTTP 404 `INVALID_LANGUAGE_PAIR`; the SDK reports it as `invalid_language_pair` with both codes, shown as "Supertext does not support this language pair (en → xx-YY)".

### Supertext Plugin Health

[Supertext Plugin Health](https://github.com/Supertext/Commando-Center-Supertext-Translation/tree/main/plugin-health) (`plugin-health/php` in the Commando Center repository, MIT) sends the version reports to the Supertext Commando Center, reports the result of the SDK's API key check and builds the ping answer (see *Commando Center* below). It has no dependencies and doesn't need the SDK: `Api\Reporting` passes it the SDK's key check (`CallableKeyChecker`) and `Sdk\Supertext::SDK_VERSION`. The plugin ships a copy in `plugin/src/PluginHealth/` with its namespace changed from `Supertext\PluginHealth` to `Supertext\Plugin\System\Supertext\PluginHealth`, loaded by the plugin's autoloader like the SDK copy. `COMMIT` names the revision, `VERSION` its version (0.1.0).

- Never edit `plugin/src/PluginHealth/`. Fix it in Commando-Center-Supertext-Translation/plugin-health, then run `scripts/vendor-plugin-health.sh <tag>` (e.g. `plugin-health-v0.1.0`; default `main`; `PLUGIN_HEALTH_DIR=/path/to/clone` uses a local clone) and the tests.
- PHPStan analyses it with `plugin/src/`, like the SDK copy.

### Language codes

Supertext accepts exactly the codes its `GET features` lists (the SDK's `V1\Languages::SOURCES` and `TARGETS`, from the live API in October 2026): bare sources (`en`, `de`); targets in one exact form each, regional for German, English, French, Italian and Portuguese (`de-CH`, `en-US`, `pt-BR`), with a script for Chinese and Serbian (`zh-Hans`, `sr-Latn`), a dialect for Swiss German (`gsw-u-sd-chzh`), bare for the others (`es`, `nl`, `sl`). Anything else is HTTP 404 `INVALID_LANGUAGE_PAIR`; formal/informal for a target without it (`en-US`, `en-GB`, `rm`, `sq`, `sr-*`, `gsw-*`) is HTTP 400 `FEATURE_ERROR` (`unsupported_feature`). The old default regions (`es-ES`, `sl-SI`, `zh-CN`) are rejected by the live API, so they're gone.

`Api\Languages` resolves every content language with the SDK's rules (no guessed regions); `ArticleTranslator::translateInto()` calls it first, so a language without a code fails before anything is checked, read or sent:

- **Mapping** (`Languages::settings()` → `Languages::mapping()`): `params.languages`, keyed by content language tag, `{"es-ES": {"code": "es", "politeness": "default"}, …}`, written by `Field\LanguagemappingField` (one native select per content language). `settings()` also reads the repeatable rows of 0.1.0 (`{"languages0": {"language": "es-ES", "code": "es", "politeness": …}}`), so an upgrade loses nothing; the next save writes the keyed form. Saving goes through `Languages::clean()`: codes only from `V1\Languages::TARGETS` (canonical spelling, anything else becomes ""), the tone only where `supportsPoliteness()` is `true`, tags matching `[A-Za-z0-9_-]`. Settings of tags that are no longer content languages are posted back as hidden values. There is no environment override for the mapping in Joomla.
- **Preselection** (`Languages::suggest()`, the SDK's `suggestTarget()`): what a row shows when nothing is saved: the exact code, else the SDK default (`de` → `de-DE`, `en` → `en-US`, `fr` → `fr-FR`, `it` → `it-IT`, `pt` → `pt-PT`, `zh-CN`/`zh-SG` → `zh-Hans`, `zh-TW`/`zh-HK`/`zh-MO` → `zh-Hant`, `sr-Latn-RS` → `sr-Latn`, `es-ES` → `es`, `nl-NL` → `nl`), else *— choose —*.
- **Target** (`Languages::target()`, the SDK's `Languages::target($tag, $mapping)`): the mapping's value (sent as it is, so a code Supertext adds later works before the SDK knows it); else the tag if it equals a Supertext code apart from case and `_`/`-` (`de-CH`, `fr-FR`, `it-IT`, `en-GB`); else the SDK default (same table); else `unsupported_language` with the tag, shown as *The language en-IE has no Supertext language yet…* (with the menu path to the Languages tab). `en-IE`, `de-LI`, `fr-BE`, `sr-RS`, `gsw` have no default.
- **Source** (`Languages::source()`, the SDK's `V1\Languages::source()` with the same mapping): the article language's mapped code in its source form (`de-CH` → `de`, `zh-Hans` → `zh`; one mapping for both directions, an unknown mapped code is sent as it is), else its tag's language (`en-GB` → `en`); else `unsupported_language`. No pair within one language.
- **Tone** (`Languages::politeness()`): `more`/`less` for a target where the SDK's `supportsPoliteness()` is `false` throws `unsupported_feature` before sending (the setting is never dropped silently; the tab no longer saves such a tone, only settings from before can have one); unknown (mapped) codes are sent and Supertext decides.

The stand-in (`tests/docs/stand-in.mjs`, also used by the CI job **joomla**) accepts exactly the live list, answers other pairs with HTTP 404 `INVALID_LANGUAGE_PAIR` and politeness where it isn't offered with HTTP 400 `FEATURE_ERROR`.

### Commando Center: version reports, key check, ping

Protocol: [Commando-Center-Supertext-Translation](https://github.com/Supertext/Commando-Center-Supertext-Translation) → `docs/DEVELOPER.md` → *Report protocol*. Supertext Plugin Health's `InstallationReporter` implements it, with the SDK's `checkApiKey()` as key checker (against the configured API); `Api\Reporting` only supplies what the plugin knows and never throws (a broken database, an unreachable center or Supertext never break a save or a translation):

- **Setting:** `version_reports` (*Send version information to Supertext*, radio, default on) on the Plugin tab; `Reporting::enabled()` = Plugin Health's `reportsEnabled()`, so `SUPERTEXT_VERSION_REPORTS` (`0`/`false`/`off`/`no`, `1`/`true`/`on`/`yes`) wins. Off: nothing is sent, the ping answers 404. `SUPERTEXT_REPORT_URL` sends reports elsewhere (tests, the stand-in).
- **Report fields:** `site_url` = `Uri::root()` in web requests (`live_site` wins there too); on the CLI `live_site`, else the site URL stored by the last web request (none: the key is checked, nothing reported). Plugin `Joomla-Supertext-Translation` with the version from `plugin/supertext.xml` (`Reporting::pluginVersion()`, the same source as the extension manager); system `Joomla` with `Version::getShortVersion()`; runtime PHP; `sdk_version` = the SDK's `Supertext::SDK_VERSION`; `callback.url` = `<site>/index.php?option=com_ajax&plugin=supertext&group=system&format=json`. Reports and key checks go through Joomla's HTTP client (`Api\CallableTransport`, for the reports wrapped in `Api\ReportTransport`; it passes the timeouts: 3 s for a report, 10 s for the check).
- **When:**
  - `afterSettingsSaved` in `onExtensionAfterSave` (context `com_plugins.plugin`, this plugin), with the params just saved; the check is shown with `enqueueMessage()` (success or warning).
  - `testConnection` on *Test connection* (`action=test`; returns `{ok, status, message, checked}`).
  - `onRequest` on back-end requests that run the plugin: opening its settings (`Field\ConnectionField`), the dialog (`describe`), `translate` and `supertext:translate`.
  - `authenticationFailed` when a translation got 401/403 (`ArticleTranslator` returns `reason: auth_failed` and `httpStatus`, stripped from the AJAX answer).
  - `reportLater()` runs these in a shutdown function, after `fastcgi_finish_request()` on PHP-FPM (otherwise at the end of the request); one per request, a rejected key wins.
- **State:** `Service\ExtensionStateStorage`: JSON `{"reporter": <ReporterState>, "siteUrl": "…"}` in the plugin's `#__extensions.custom_data`. Joomla's plugin form only replaces `params`, and installing an update loads and keeps the row. Never the key: the state has its SHA-256 fingerprint only. `<ReporterState>` is the same JSON the SDK 0.2 reporter stored, so the switch to Plugin Health sent no new `installed` report.
- **Ping:** `GET index.php?option=com_ajax&plugin=supertext&group=system&format=json&challenge=…` on the site, answered with `Reporting::ping()` (Plugin Health's `pingResponse()`: 200 `{"ok":true,"plugin","challenge"}`, 400 for another challenge, 404 with reports off; `Content-Type: application/json`, `Cache-Control: no-store`) and `$app->close()`. It is answered in `onAfterInitialise`, before routing, because the Language Filter redirects `index.php?option=…` to `/en/…` on multilingual sites and the center follows no redirects; `onAjaxSupertext` answers it too on sites without the filter. Other methods get 405. `onPageCacheIsExcluded` keeps it out of the System - Page Cache.
- **API key field:** the field is `lock="true"`, so it's disabled (not submitted) until *Modify*. `onExtensionBeforeSave` keeps the stored key when the form sent none; before this, saving the settings again deleted the key.

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
composer test          # PHPUnit: SDK wrapper (protocol, auth header, 429 retries, errors, key check, segments, splitting, Translation review client, User-Agent), languages (mapping, defaults, settings migration, saving, tone), Commando Center glue (ReportingTest: setting, when it reports, state, ping, broken storage), translation files (FileTransferTest: reference, export keys and mapping, import rules, errors, size limit), plugin-tag protection and the language files
./build.sh             # dist/plg_system_supertext-<version>.zip
```

CI (`.github/workflows/ci.yml`) on every push and pull request:

- **unit**: PHP lint on 8.2, 8.3 and 8.4, PHPUnit, `sh -n demo/entrypoint.sh`, package build.
- **PHPStan**: level 5 against a downloaded Joomla (see *Code quality and security checks*).
- **joomla**: installs Joomla 6.1 on PostgreSQL, installs the built package and the demo setup plugin, runs `supertext:demo-setup`, then translates the sample articles with `supertext:translate` against the stand-in API and checks the German article and its association in the database. The stand-in also plays the Commando Center (`SUPERTEXT_REPORT_URL`): the job checks the one `installed` report (plugin, Joomla version, callback URL, no key) and the public ping through `php -S` on a site with the Language Filter on.

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
| `apache.conf`, `php.ini` | Web server and PHP settings (`HTTPS=on` behind Railway's TLS proxy, so Joomla's site URL, and the reported ping address, are `https://`) |
| `.env.example` | The variables below |

**No volume:** Railway allows only three volumes per project and they are taken, so the container keeps no files. Everything that must survive a deploy is in PostgreSQL: the content, the plugin settings and `configuration.php` (in `supertext_demo_state`). Language packs and the plugins are reinstalled from the image on every start, which also updates the plugin. Consequences: media uploaded in the demo's backend disappear with the next deploy (the sample articles use Joomla's bundled images), and Joomla itself is updated by changing `JOOMLA_VERSION` in the `Dockerfile`, not from the backend (after a version change, run `php cli/joomla.php maintenance:database` once if Joomla reports database problems). To reset the demo, drop the `joomla` database and redeploy.

**Service variables:**

| Variable | |
| --- | --- |
| `DATABASE_URL` | `${{Postgres.DATABASE_URL}}`; the Joomla database (`JOOMLA_DB_NAME`, default `joomla`) is created on that server if missing |
| `DEMO_ADMIN_EMAIL`, `DEMO_ADMIN_PASSWORD` | Super User (the e-mail address is also the username) |
| `DEMO_EDITOR_EMAIL`, `DEMO_EDITOR_PASSWORD` | Optional second account for automated tests and screenshots. Joomla's *Editor* group has no backend access, so this account is a **Manager** (backend, articles, no system settings). |
| `SUPERTEXT_API_KEY` | Supertext key used by the plugin (wins over the plugin setting) |
| `SUPERTEXT_API_ENDPOINT` | Optional, e.g. the staging API (to try the review service, switch on *Translation review* on the Languages tab instead) |
| `SUPERTEXT_REVIEW_URL` | Optional: another review service than Supertext's (wins over the *Review service URL* setting; empty keeps the setting) |
| `SUPERTEXT_VERSION_REPORTS` | Optional: `0` sends no version information to Supertext (wins over the plugin setting) |
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
# served with SUPERTEXT_API_ENDPOINT=http://127.0.0.1:8765/v1/, SUPERTEXT_REPORT_URL=http://127.0.0.1:8765/report
# (reports go to the stand-in, never to Supertext) and *no* SUPERTEXT_API_KEY:
# the script enters a key in the plugin settings itself
BASE_URL=http://127.0.0.1:8092 DEMO_ADMIN_EMAIL=… DEMO_ADMIN_PASSWORD=… \
  DEMO_EDITOR_EMAIL=… DEMO_EDITOR_PASSWORD=… npm run screenshots
```

Start from a database without translations of the sample articles. The script also exports the second sample article as HTML, fills in the French sample translations and imports the file (`export-dialog`, `import-dialog`, `import-summary`). The script saves the settings twice (the key, then on the Languages tab `fr-FR` → `fr-CH`, formal) and shows the ping address with the public demo's URL (`PUBLIC_URL`) instead of the local one.

## Code quality and security checks

- **Checks** workflow (`.github/workflows/checks.yml`): actionlint and zizmor lint the workflows on every push and pull request; dependency review fails a pull request that adds a package with a known vulnerability (moderate or worse). Third-party actions are pinned to commit SHAs (Dependabot keeps them current), the default token is read-only and checkouts don't keep credentials. Locally: `pip install actionlint-py zizmor`, then `actionlint` and `zizmor .github/workflows` in the repo root.
- **Links** workflow (`.github/workflows/links.yml`): lychee checks all Markdown links weekly and whenever docs change on `main`. Broken links open (or update) the issue "Broken links in the docs". Links that can't work from CI (local URLs, placeholders, pages behind a login) are excluded in `.lycheeignore`.
- **PHPStan** (job *PHPStan* in `.github/workflows/ci.yml`, config `phpstan.neon`; `phpstan/phpstan` is a dev dependency): level 5 over `plugin/src/` and `plugin/services/`, no baseline (nothing to baseline when it was introduced). Joomla isn't a Composer package, so the job downloads the Joomla full package (the version in the job's `JOOMLA_VERSION`) into `.joomla/` (git-ignored); `phpstan.neon` loads `tests/phpstan/bootstrap.php` (defines `_JEXEC` and the `JPATH_*` constants the plugin uses; without `_JEXEC` PHPStan stops silently at the first `\defined('_JEXEC') or die;` it loads and skips the rest) and Joomla's `libraries/vendor/autoload.php`, and scans `libraries/src` and the com_content and com_fields sources. Locally: unzip a `Joomla_X.Y.Z-Stable-Full_Package.zip` into `.joomla/`, then `composer install && vendor/bin/phpstan analyse`. If findings ever have to be accepted, put them in `phpstan-baseline.neon` (`--generate-baseline`) and include it from `phpstan.neon`.
- **GitHub settings** (set by Remy's setup script, not in the repo): secret scanning with push protection (a push containing a known token format is rejected; findings under *Security → Secret scanning*) and CodeQL default setup (findings under *Security → Code scanning* and as pull request comments). CodeQL doesn't cover PHP, which is why this repo runs PHPStan.
- `.github/workflows/support-triage.yml`: support requests from the Supertext integrations website arrive as issues labelled `support` (contact details stay in the Supertext CMS). Claude classifies each one: answers questions (marked `<!-- supertext:reply -->`, emailed to the sender by the CMS) and closes them, fixes bugs as pull requests for review (never merged automatically), labels feature requests `feature-request`. Only people with triage rights can add labels, so outsiders can't start it. Needs the organisation secret `ANTHROPIC_API_KEY`; the workflow is the same in every Supertext repo.

Before starting work in this repo, look at its open findings: code scanning alerts, secret scanning alerts, Dependabot pull requests and the issue "Broken links in the docs".

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
- The API client (`src/Api`, no Joomla classes, built on the bundled SDK in `src/Sdk`) throws `SupertextException` with an English message and a `reason` (e.g. `limit_exceeded`); `Service\Messages` shows the `PLG_SYSTEM_SUPERTEXT_API_<REASON>` string instead. A new reason needs that string in all five files.
- Keep the three docs in `docs/` current with every change (see `CLAUDE.md`).

## Known limitations / roadmap

- Articles only. Categories, menu items, modules and custom field labels are not translated yet.
- Without PHP-FPM (e.g. mod_php), a due version report or key check runs at the end of the request, so that one response waits for it (at most 3 s for the report, 10 s for the check); it happens only after updates or a changed key.
- The *Supertext language* list is the SDK's (October 2026): a language Supertext adds later can be chosen once the bundled SDK is updated.
- Re-translating overwrites manual changes; no change detection. A translation memory and reviewers' corrections come from the review service when a language has *Translation review* on; corrections reach Joomla only with the next translation (no push).
- Translation runs inside the request (up to the timeout per language). Planned: a queue via Joomla's Task Scheduler for many articles.
- Not yet tested on Joomla 5.4, or on MySQL/MariaDB (developed and tested on PostgreSQL).
- Subform and repeatable custom fields are copied, not translated.
- Not yet in the Joomla Extensions Directory; no update server yet.
- Human (professional) translation orders are not supported yet (the WordPress plugin has them).
