# Supertext Translation for Joomla

Translate Joomla articles into your site's other languages with **Supertext AI**, straight from the Articles list or the article editor.

Select articles, click **Supertext**, tick the languages and click **Translate**. The plugin translates each article and saves the result as a linked translation (Joomla's *Multilingual Associations*), unpublished, so an editor can review it before it goes live.

- Works with Joomla's own multilingual setup: content languages and associations
- Translates title, article text (formatting, links and lists are kept), meta description and keywords, image alt texts and captions, link labels, and Text/Textarea/Editor custom fields
- Leaves plugin tags such as `{loadmodule …}` untouched
- Overwrites existing translations only when you ask for it, with a clear warning first
- Formal or informal tone and custom Supertext language codes per language
- Also available on the command line: `php cli/joomla.php supertext:translate`

![The Supertext dialog in the Joomla Articles list](docs/images/translate-dialog.png)

## Documentation

| Guide | For |
| --- | --- |
| [Installation guide](docs/INSTALLATION.md) | Administrators: requirements, install, API key, languages, settings, troubleshooting |
| [User guide](docs/USER_GUIDE.md) | Editors: translating, reviewing, what gets translated |
| [Developer guide](docs/DEVELOPER.md) | Architecture, API protocol, local development, tests, demo deployment, releases |

Quick start: build the package with `./build.sh` and install `dist/plg_system_supertext-<version>.zip` in *System → Install → Extensions*, then enter your API key in *System → Plugins → System - Supertext Translation*. No Supertext account yet? [Create one](https://www.supertext.com/person/en/account/signin), then generate the key at [supertext.com → Integrations → API](https://www.supertext.com/en/integrations/api) (requires the Admin role).

## Demo

`demo/` is a Joomla 6 site with English sample articles and German (CH), French and Italian, deployed to Railway from this repository. See the [developer guide](docs/DEVELOPER.md#demo-railway).

## Changelog and roadmap

See [CHANGELOG.md](CHANGELOG.md) and the [roadmap](docs/DEVELOPER.md#known-limitations--roadmap).

## License

GNU General Public License version 2 or later (like Joomla itself). © Supertext AG
