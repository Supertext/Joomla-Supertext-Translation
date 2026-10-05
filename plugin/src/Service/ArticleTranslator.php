<?php

/**
 * @package     Supertext Translation for Joomla
 * @copyright   (C) Supertext AG
 * @license     GNU General Public License version 2 or later
 */

namespace Supertext\Plugin\System\Supertext\Service;

use Joomla\CMS\Application\ApplicationHelper;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Language\Associations;
use Joomla\CMS\Language\LanguageHelper;
use Joomla\CMS\User\User;
use Joomla\Component\Fields\Administrator\Helper\FieldsHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;
use Supertext\Plugin\System\Supertext\Api\HtmlDocument;
use Supertext\Plugin\System\Supertext\Api\SupertextClient;
use Supertext\Plugin\System\Supertext\Api\SupertextException;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Translates Joomla articles into other content languages and links the results as
 * associations (Joomla's "Multilingual Associations").
 *
 * One HTML document per article and target language: every translatable field is one
 * <div data-st-id>, so the article text travels as a whole and keeps its markup.
 */
final class ArticleTranslator
{
    /** Article columns translated as plain text. */
    private const PLAIN_FIELDS = ['title', 'metadesc', 'metakey'];

    /** Article columns translated as HTML. */
    private const HTML_FIELDS = ['introtext', 'fulltext'];

    /** Keys inside the `images` JSON. */
    private const IMAGE_TEXTS = ['image_intro_alt', 'image_intro_caption', 'image_fulltext_alt', 'image_fulltext_caption'];

    /** Keys inside the `urls` JSON. */
    private const URL_TEXTS = ['urlatext', 'urlbtext', 'urlctext'];

    /** Custom field types translated (others are copied unchanged). */
    private const PLAIN_FIELD_TYPES = ['text', 'textarea'];
    private const HTML_FIELD_TYPES  = ['editor'];

    /**
     * @param array<string, array{code?: string, politeness?: string}> $languageSettings  keyed by Joomla language tag
     */
    public function __construct(
        private readonly CMSApplicationInterface $app,
        private readonly DatabaseInterface $db,
        private readonly SupertextClient $client,
        private readonly array $languageSettings = [],
        private readonly int $targetState = 0,
        private readonly bool $translateCustomFields = true,
    ) {
    }

    /**
     * Languages an article can be translated into, and which already have a translation.
     *
     * @return array{source: string, sourceTitle: string, languages: list<array{tag: string, title: string, image: string, existing: ?array{id: int, title: string}}>}
     */
    public function describe(int $articleId, User $user): array
    {
        $article = $this->loadArticle($articleId);
        $this->assertCanRead($article, $user);

        $all      = LanguageHelper::getContentLanguages([0, 1]);
        $existing = $this->associations($articleId);
        $out      = [];

        foreach ($all as $tag => $language) {
            if ($tag === $article->language) {
                continue;
            }

            $assoc = $existing[$tag] ?? null;
            $out[] = [
                'tag'       => $tag,
                'title'     => (string) $language->title,
                'image'     => (string) $language->image,
                'published' => (int) $language->published === 1,
                'existing'  => $assoc ? ['id' => (int) $assoc->id, 'title' => (string) $assoc->title] : null,
            ];
        }

        $sourceTitle = isset($all[$article->language]) ? (string) $all[$article->language]->title : (string) $article->language;

        return ['source' => (string) $article->language, 'sourceTitle' => $sourceTitle, 'languages' => $out];
    }

    /**
     * @param list<string> $targets   language tags
     * @param bool         $overwrite update translations that already exist (otherwise they are skipped)
     *
     * @return list<array{article: int, language: string, status: string, id?: int, title?: string, message?: string}>
     */
    public function translate(int $articleId, array $targets, bool $overwrite, User $user): array
    {
        if (!$this->client->hasApiKey()) {
            throw new SupertextException($this->text('PLG_SYSTEM_SUPERTEXT_JS_NOT_CONFIGURED'));
        }

        if (!Associations::isEnabled()) {
            throw new SupertextException($this->text('PLG_SYSTEM_SUPERTEXT_ERROR_ASSOCIATIONS_DISABLED'));
        }

        $article = $this->loadArticle($articleId);
        $this->assertCanRead($article, $user);

        if ($article->language === '*' || $article->language === '') {
            throw new SupertextException($this->text('PLG_SYSTEM_SUPERTEXT_ERROR_LANGUAGE_ALL'));
        }

        $languages = LanguageHelper::getContentLanguages([0, 1]);
        $results   = [];

        foreach (array_unique($targets) as $tag) {
            $result = ['article' => $articleId, 'language' => $tag];

            try {
                if (!isset($languages[$tag]) || $tag === $article->language) {
                    throw new SupertextException($this->text('PLG_SYSTEM_SUPERTEXT_ERROR_UNKNOWN_LANGUAGE', $tag));
                }

                $existing = $this->associations($articleId)[$tag] ?? null;

                if ($existing && !$overwrite) {
                    $results[] = $result + ['status' => 'skipped', 'id' => (int) $existing->id, 'title' => (string) $existing->title];

                    continue;
                }

                $saved     = $this->translateInto($article, $tag, $existing ? (int) $existing->id : 0, $user);
                $results[] = $result + ['status' => $existing ? 'updated' : 'created'] + $saved;
            } catch (\Throwable $e) {
                $results[] = $result + ['status' => 'error', 'message' => $e->getMessage()];
            }
        }

        return $results;
    }

    /** @return array{id: int, title: string} */
    private function translateInto(object $article, string $tag, int $existingId, User $user): array
    {
        $target = $existingId ? $this->loadArticle($existingId) : null;
        $catid  = $target ? (int) $target->catid : $this->targetCategory((int) $article->catid, $tag);

        if ($target) {
            $canEdit = $user->authorise('core.edit', 'com_content.article.' . $existingId)
                || ($user->authorise('core.edit.own', 'com_content.article.' . $existingId) && (int) $target->created_by === (int) $user->id);

            if (!$canEdit) {
                throw new SupertextException($this->text('PLG_SYSTEM_SUPERTEXT_ERROR_NOT_ALLOWED_EDIT', $tag));
            }
        } elseif (!$user->authorise('core.create', 'com_content.category.' . $catid)) {
            throw new SupertextException($this->text('PLG_SYSTEM_SUPERTEXT_ERROR_NOT_ALLOWED_CREATE', $tag));
        }

        // Collect what to translate: [key => [text, html]]
        $images = new Registry($article->images);
        $urls   = new Registry($article->urls);
        $fields = [];

        foreach (self::PLAIN_FIELDS as $name) {
            $fields['article.' . $name] = [(string) $article->$name, false];
        }

        foreach (self::HTML_FIELDS as $name) {
            $fields['article.' . $name] = [HtmlDocument::protect((string) $article->$name), true];
        }

        foreach (self::IMAGE_TEXTS as $name) {
            $fields['images.' . $name] = [(string) $images->get($name, ''), false];
        }

        foreach (self::URL_TEXTS as $name) {
            $fields['urls.' . $name] = [(string) $urls->get($name, ''), false];
        }

        $customFields = $this->translateCustomFields ? $this->customFields($article) : [];

        foreach ($customFields as $name => $field) {
            if ($field['translate'] !== null) {
                $fields['field.' . $name] = [$field['value'], $field['translate'] === 'html'];
            }
        }

        $translated = $this->translateFields($fields, $tag, (string) $article->language);

        foreach (self::IMAGE_TEXTS as $name) {
            if (isset($translated['images.' . $name])) {
                $images->set($name, $translated['images.' . $name]);
            }
        }

        foreach (self::URL_TEXTS as $name) {
            if (isset($translated['urls.' . $name])) {
                $urls->set($name, $translated['urls.' . $name]);
            }
        }

        $comFields = [];

        foreach ($customFields as $name => $field) {
            $comFields[$name] = $translated['field.' . $name] ?? $field['raw'];
        }

        $title = $translated['article.title'] ?? (string) $article->title;

        $data = [
            'id'        => $existingId,
            'title'     => $title,
            'introtext' => HtmlDocument::unprotect($translated['article.introtext'] ?? (string) $article->introtext),
            'fulltext'  => HtmlDocument::unprotect($translated['article.fulltext'] ?? (string) $article->fulltext),
            'metadesc'  => $translated['article.metadesc'] ?? (string) $article->metadesc,
            'metakey'   => $translated['article.metakey'] ?? (string) $article->metakey,
            'images'    => $images->toArray(),
            'urls'      => (string) $urls,
            'language'  => $tag,
        ];

        if ($comFields) {
            $data['com_fields'] = $comFields;
        }

        if (!$target) {
            $data += [
                'alias'     => $this->uniqueAlias($title, $catid, $tag),
                'catid'     => $catid,
                'state'     => $this->targetState,
                'access'    => (int) $article->access,
                'featured'  => (int) $article->featured,
                'attribs'   => (new Registry($article->attribs))->toArray(),
                'metadata'  => (new Registry($article->metadata))->toArray(),
                'note'      => (string) $article->note,
                'created_by' => (int) $user->id,
                'tags'      => $this->tagIds((int) $article->id),
            ];
        } else {
            // Keep the existing translation's URL, category, state and settings.
            $data += [
                'alias' => (string) $target->alias,
                'catid' => $catid,
            ];
        }

        // Link source, other translations and this one.
        $associations = [];

        foreach ($this->associations((int) $article->id) as $lang => $assoc) {
            $associations[$lang] = (int) $assoc->id;
        }

        $associations[(string) $article->language] = (int) $article->id;
        unset($associations[$tag]);
        $data['associations'] = $associations;

        $model = $this->app->bootComponent('com_content')->getMVCFactory()
            ->createModel('Article', 'Administrator', ['ignore_request' => true]);

        if (!$model->save($data)) {
            throw new SupertextException($model->getError() ?: $this->text('PLG_SYSTEM_SUPERTEXT_ERROR_SAVE'));
        }

        return ['id' => (int) $model->getState('article.id'), 'title' => $title];
    }

    /**
     * @param array<string, array{0: string, 1: bool}> $fields key => [text, isHtml]
     *
     * @return array<string, string> key => translation (only for non-empty fields that came back)
     */
    private function translateFields(array $fields, string $tag, string $sourceTag): array
    {
        $keys     = [];
        $segments = [];

        foreach ($fields as $key => [$text, $isHtml]) {
            if (trim(strip_tags($text)) === '') {
                continue;
            }

            $keys[]     = $key;
            $segments[] = ['text' => $text, 'html' => $isHtml];
        }

        if (!$segments) {
            return [];
        }

        $settings   = $this->languageSettings[$tag] ?? [];
        $code       = trim((string) ($settings['code'] ?? '')) ?: $tag;
        $politeness = (string) ($settings['politeness'] ?? 'default');
        $out        = [];

        // Chunk to stay below the per-document limit.
        $chunks = [[]];
        $size   = 0;

        foreach ($segments as $i => $segment) {
            $length = \strlen($segment['text']);

            if ($size + $length > SupertextClient::MAX_DOCUMENT_CHARACTERS && $chunks[\count($chunks) - 1]) {
                $chunks[] = [];
                $size     = 0;
            }

            $chunks[\count($chunks) - 1][$i] = $segment;
            $size += $length;
        }

        foreach ($chunks as $chunk) {
            $isHtml = array_map(static fn (array $s): bool => $s['html'], $chunk);
            $html   = $this->client->translateDocument(HtmlDocument::build($chunk), $code, $sourceTag, $politeness);

            foreach (HtmlDocument::parse($html, $isHtml) as $i => $translation) {
                if (isset($keys[$i]) && trim(strip_tags($translation)) !== '') {
                    $out[$keys[$i]] = $translation;
                }
            }
        }

        return $out;
    }

    /**
     * @return array<string, array{value: string, raw: mixed, translate: ?string}>
     */
    private function customFields(object $article): array
    {
        if (!class_exists(FieldsHelper::class)) {
            return [];
        }

        $item  = (object) ['id' => (int) $article->id, 'catid' => (int) $article->catid, 'language' => (string) $article->language];
        $out   = [];

        foreach (FieldsHelper::getFields('com_content.article', $item) as $field) {
            $raw       = $field->rawvalue ?? '';
            $translate = null;

            if (\is_string($raw) && $raw !== '') {
                if (\in_array($field->type, self::PLAIN_FIELD_TYPES, true)) {
                    $translate = 'plain';
                } elseif (\in_array($field->type, self::HTML_FIELD_TYPES, true)) {
                    $translate = 'html';
                }
            }

            $out[(string) $field->name] = ['value' => \is_string($raw) ? $raw : '', 'raw' => $raw, 'translate' => $translate];
        }

        return $out;
    }

    /** The category associated with the source category in the target language, else the source category. */
    private function targetCategory(int $catid, string $tag): int
    {
        $associated = Associations::getAssociations('com_categories', '#__categories', 'com_categories.item', $catid, 'id', 'alias', '');

        return isset($associated[$tag]) ? (int) explode(':', (string) $associated[$tag]->id)[0] : $catid;
    }

    private function uniqueAlias(string $title, int $catid, string $tag): string
    {
        $base  = ApplicationHelper::stringURLSafe($title, $tag) ?: 'article-' . strtolower($tag);
        $alias = $base;

        for ($n = 2; $this->aliasTaken($alias, $catid); $n++) {
            $alias = $base . '-' . $n;
        }

        return $alias;
    }

    private function aliasTaken(string $alias, int $catid): bool
    {
        $query = $this->db->createQuery()
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__content'))
            ->where($this->db->quoteName('alias') . ' = :alias')
            ->where($this->db->quoteName('catid') . ' = :catid')
            ->bind(':alias', $alias)
            ->bind(':catid', $catid, ParameterType::INTEGER);

        return (int) $this->db->setQuery($query)->loadResult() > 0;
    }

    /** @return array<string, object{id: int, title: string}> language tag => associated article */
    private function associations(int $articleId): array
    {
        $query = $this->db->createQuery()
            ->select([$this->db->quoteName('c.id'), $this->db->quoteName('c.title'), $this->db->quoteName('c.language')])
            ->from($this->db->quoteName('#__associations', 'a'))
            ->join('INNER', $this->db->quoteName('#__associations', 'b'), $this->db->quoteName('a.key') . ' = ' . $this->db->quoteName('b.key'))
            ->join('INNER', $this->db->quoteName('#__content', 'c'), $this->db->quoteName('c.id') . ' = ' . $this->db->quoteName('b.id'))
            ->where($this->db->quoteName('a.context') . ' = ' . $this->db->quote('com_content.item'))
            ->where($this->db->quoteName('b.context') . ' = ' . $this->db->quote('com_content.item'))
            ->where($this->db->quoteName('a.id') . ' = :id')
            ->where($this->db->quoteName('c.id') . ' <> :self')
            ->where($this->db->quoteName('c.state') . ' <> -2')
            ->bind(':id', $articleId, ParameterType::INTEGER)
            ->bind(':self', $articleId, ParameterType::INTEGER);

        $out = [];

        foreach ($this->db->setQuery($query)->loadObjectList() as $row) {
            $out[(string) $row->language] = $row;
        }

        return $out;
    }

    /** @return list<string> tag ids of the article (Joomla's tag field takes strings) */
    private function tagIds(int $articleId): array
    {
        $query = $this->db->createQuery()
            ->select($this->db->quoteName('tag_id'))
            ->from($this->db->quoteName('#__contentitem_tag_map'))
            ->where($this->db->quoteName('type_alias') . ' = ' . $this->db->quote('com_content.article'))
            ->where($this->db->quoteName('content_item_id') . ' = :id')
            ->bind(':id', $articleId, ParameterType::INTEGER);

        return array_map('strval', $this->db->setQuery($query)->loadColumn());
    }

    private function loadArticle(int $id): object
    {
        $query = $this->db->createQuery()
            ->select('*')
            ->from($this->db->quoteName('#__content'))
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        $article = $this->db->setQuery($query)->loadObject();

        if (!$article) {
            throw new SupertextException($this->text('PLG_SYSTEM_SUPERTEXT_ERROR_NOT_FOUND', $id));
        }

        return $article;
    }

    private function assertCanRead(object $article, User $user): void
    {
        $asset = 'com_content.article.' . (int) $article->id;

        if (!$user->authorise('core.manage', 'com_content')
            || !($user->authorise('core.edit', $asset) || ($user->authorise('core.edit.own', $asset) && (int) $article->created_by === (int) $user->id))) {
            throw new SupertextException($this->text('PLG_SYSTEM_SUPERTEXT_ERROR_NOT_ALLOWED'));
        }
    }

    private function text(string $key, string|int ...$args): string
    {
        $language = $this->app->getLanguage();

        return $args ? sprintf($language->_($key), ...$args) : $language->_($key);
    }
}
