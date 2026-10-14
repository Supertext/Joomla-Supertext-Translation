# Changelog

All notable changes to this project are documented here.

## Unreleased

- Changed: **languages are no longer guessed.** A content language whose tag is a Supertext code (`de-CH`, `fr-FR`, `it-IT`, `en-GB`, …) is sent as it is; every other content language (e.g. `es-ES` → `es`, `nl-NL` → `nl`, `zh-CN` → `zh-Hans`) needs a row on the plugin's *Languages* tab. Default regions are gone: they were wrong for most languages (Supertext rejects `es-ES`, `sl-SI`, `zh-CN`). An unmapped language gets the message *The language … has no Supertext language yet* and nothing is sent for it. The source is sent as the bare language of the article's language or its row (`en-GB` → `en`).
- Changed: the *Supertext language* of a Languages row is now a list of Supertext's languages, named in the user's backend language; choosing a content language pre-selects its Supertext code if it has one. A code saved before that isn't a Supertext code stays selected and is marked *(not a Supertext code)*. New overview *Your content languages* on the Languages tab: the code each content language is sent as, *Not mapped*, and a warning for a tone Supertext doesn't offer.
- Added: *Supertext offers no formal or informal tone for this language* when a row sets *Formal* or *Informal* for English, Swiss German, Romansh, Albanian or Serbian (nothing is sent).
- Added: automatic API key check after every save of the plugin settings (shown as a message), on **Test connection**, when a back-end page finds a key it hasn't checked (e.g. a new `SUPERTEXT_API_KEY`) and when Supertext rejects the key during a translation. *API key check* in the settings shows the latest result with its time (*the connection to Supertext works*, *rejected*, *no API key*, *can't be reached*, *error*), with the links to create an account and generate a key.
- Added: *Send version information to Supertext* (on by default; `SUPERTEXT_VERSION_REPORTS=0` turns it off): after saving the settings and after updates, the plugin sends the site URL, the plugin, Joomla, PHP and SDK versions, whether a key is set, the key check result and the ping address to Supertext. No content, no API key and no personal data.
- Added: public ping endpoint `index.php?option=com_ajax&plugin=supertext&group=system&format=json` on the site, which only echoes Supertext's challenge (404 with version reports off), answered before the Language Filter's redirect and never page-cached. The plugin keeps its reporting state in its own `#__extensions` row (`custom_data`).
- Fixed: saving the plugin settings again without clicking **Modify** next to the API key deleted the stored key (the locked field isn't submitted). The key is kept now.
- Fixed: bundled Supertext SDK 0.2.1 (commit `eeba8fa`): one Languages row serves both directions; a mapped language is sent as a source in its source form (`de-CH` → `de`), so English as the source no longer fails with an `en-US` mapping.
- Changed: bundled Supertext SDK 0.2.0 (commit `c66cb4d`, `V1` namespace): the API's language list, the key check and the version reports. All new and changed texts in English, German, French and Italian.
- Changed: all Supertext API calls now go through the official Supertext PHP SDK, shipped inside the plugin (nothing extra to install). Behaviour stays the same.
- Added: its own message for an unsupported language pair, naming the codes ("Supertext does not support this language pair (en → xx-YY)"), in English, German, French and Italian.
- Added: French and Italian interface (`fr-FR`, `it-IT`), and German where it was missing: messages from Supertext (limit reached, authentication failed, timeouts and others) now appear in the user's backend language too. The "no API key" and "authentication failed" messages now also link to Supertext account sign-up.

## 0.1.0 — 2026-10-07

- The API key setting, the installation guide and the README now link to Supertext account sign-up and to *Integrations → API* to generate the key (Admin role required).
- First version: Joomla system plugin `plg_system_supertext` (Joomla 6.1, PHP 8.3).
- **Supertext** button in the Articles list (one or more articles) and the article editor; a dialog to pick target languages, with already translated languages marked and an explicit *Overwrite existing translations* option.
- Translations are saved as associated articles in each content language, unpublished by default, with alias from the translated title, category (or its associated category), access, featured, options, tags and custom fields copied.
- Translates title, intro and full text (HTML kept), meta description and keywords, image alt texts and captions, link labels, and Text/Textarea/Editor custom fields; Joomla plugin tags such as `{loadmodule …}` are protected.
- Settings: API key (with or without the `Supertext-Auth-Key` prefix; `SUPERTEXT_API_KEY` wins), *Test connection*, API environment, custom endpoint (`SUPERTEXT_API_ENDPOINT` wins), status of new translations, custom fields on/off, per-language Supertext code and tone, timeout.
- Requests that hit Supertext's per-second rate limit are retried automatically.
- CLI: `php cli/joomla.php supertext:translate <ids> --to=<tag>`.
- UI in English and German.
- Demo for Railway (`demo/`): Joomla 6.1 on PostgreSQL with German (CH), French and Italian, sample articles, and `DEMO_ADMIN_*` / `DEMO_EDITOR_*` accounts created on every start.
- Docs with screenshots, regenerated by `tests/docs/screenshots.mjs` against a stand-in API.
