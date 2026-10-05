<?php

/**
 * @package     Supertext Translation for Joomla — demo setup
 * @copyright   (C) Supertext AG
 * @license     GNU General Public License version 2 or later
 */

namespace Supertext\Plugin\Console\SupertextDemo\Command;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Table\Content;
use Joomla\CMS\Table\Menu;
use Joomla\CMS\Table\MenuType;
use Joomla\CMS\Table\Module;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserHelper;
use Joomla\Console\Command\AbstractCommand;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * `php cli/joomla.php supertext:demo-setup` — runs on every start of the demo container:
 *
 * - creates the DEMO_ADMIN / DEMO_EDITOR accounts if missing (never changes existing ones)
 * - publishes the demo languages, turns on multilingual associations, adds a home page and
 *   a language switcher per language
 * - enables the Supertext plugin
 * - adds the sample articles (English) once
 */
final class SetupCommand extends AbstractCommand
{
    protected static $defaultName = 'supertext:demo-setup';

    /** Content languages of the demo (English is the source). */
    private const LANGUAGES = ['en-GB', 'de-CH', 'fr-FR', 'it-IT'];

    private SymfonyStyle $io;
    private DatabaseInterface $db;

    protected function configure(): void
    {
        $this->setDescription('Creates the demo accounts, languages and sample content (idempotent).');
        $this->addOption('remove-user', null, InputOption::VALUE_REQUIRED, 'Username of the throwaway installer account to delete once a demo admin exists.');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->db = Factory::getContainer()->get(DatabaseInterface::class);

        $this->ensureAccounts((string) $input->getOption('remove-user'));
        $this->ensureLanguages();
        $this->ensurePlugins();
        $this->ensureHomePages();
        $this->ensureLanguageSwitcher();
        $this->ensureSampleContent();

        return 0;
    }

    /* ---------------------------------------------------------------- accounts */

    private function ensureAccounts(string $installerUser): void
    {
        $accounts = [
            ['DEMO_ADMIN', 'Demo Admin', 8],     // Super Users
            ['DEMO_EDITOR', 'Demo Editor', 6],   // Manager: the closest built-in role with backend access
        ];

        foreach ($accounts as [$prefix, $name, $group]) {
            $email    = (string) getenv($prefix . '_EMAIL');
            $password = (string) getenv($prefix . '_PASSWORD');

            if ($email === '' || $password === '') {
                $this->io->writeln("{$prefix}_EMAIL / {$prefix}_PASSWORD not set; skipping that account.");

                continue;
            }

            if ($this->userExists($email)) {
                $this->io->writeln("{$prefix}: account exists, left unchanged.");

                continue;
            }

            $problem = $this->passwordProblem($password);

            if ($problem !== null) {
                $this->io->warning("{$prefix}_PASSWORD does not meet Joomla's password rules ({$problem}); account not created.");

                continue;
            }

            $data = [
                'username' => $email,
                'name'     => $name,
                'email'    => $email,
                'password' => $password,
                'groups'   => [$group],
                'block'    => 0,
            ];
            $user = new User();
            $user->bind($data);

            if (!$user->save()) {
                $this->io->warning("{$prefix}: could not create the account: " . $user->getError());

                continue;
            }

            $this->io->writeln("{$prefix}: account created.");
        }

        // The installer needs a Super User; remove that throwaway account once a real one exists.
        if ($installerUser !== '') {
            $id = (int) UserHelper::getUserId($installerUser);

            if ($id && $this->superUserCount($id) > 0) {
                User::getInstance($id)->delete();
                $this->io->writeln('Removed the installer account.');
            }
        }
    }

    private function userExists(string $email): bool
    {
        $query = $this->db->createQuery()
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__users'))
            ->where('(LOWER(' . $this->db->quoteName('email') . ') = LOWER(:email) OR ' . $this->db->quoteName('username') . ' = :username)')
            ->bind(':email', $email)
            ->bind(':username', $email);

        return (int) $this->db->setQuery($query)->loadResult() > 0;
    }

    /** Super Users other than $exceptId. */
    private function superUserCount(int $exceptId): int
    {
        $query = $this->db->createQuery()
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__user_usergroup_map', 'm'))
            ->join('INNER', $this->db->quoteName('#__users', 'u'), 'u.id = m.user_id')
            ->where('m.group_id = 8')
            ->where('u.block = 0')
            ->where('u.id <> :id')
            ->bind(':id', $exceptId, ParameterType::INTEGER);

        return (int) $this->db->setQuery($query)->loadResult();
    }

    /** Joomla's own rules from Users → Options → Password Options. */
    private function passwordProblem(string $password): ?string
    {
        $params = ComponentHelper::getParams('com_users');
        $rules  = [
            'minimum_length'    => [(int) $params->get('minimum_length', 12), mb_strlen($password), 'at least %d characters'],
            'minimum_integers'  => [(int) $params->get('minimum_integers', 0), preg_match_all('/\d/', $password), 'at least %d digits'],
            'minimum_symbols'   => [(int) $params->get('minimum_symbols', 0), preg_match_all('/[^\p{L}\p{N}]/u', $password), 'at least %d symbols'],
            'minimum_uppercase' => [(int) $params->get('minimum_uppercase', 0), preg_match_all('/\p{Lu}/u', $password), 'at least %d upper-case letters'],
            'minimum_lowercase' => [(int) $params->get('minimum_lowercase', 0), preg_match_all('/\p{Ll}/u', $password), 'at least %d lower-case letters'],
        ];

        foreach ($rules as [$min, $actual, $message]) {
            if ($actual < $min) {
                return sprintf($message, $min);
            }
        }

        if (preg_match('/^\s|\s$/', $password)) {
            return 'no spaces at the start or end';
        }

        return null;
    }

    /* --------------------------------------------------------------- languages */

    private function ensureLanguages(): void
    {
        $query = $this->db->createQuery()
            ->update($this->db->quoteName('#__languages'))
            ->set($this->db->quoteName('published') . ' = 1')
            ->whereIn($this->db->quoteName('lang_code'), self::LANGUAGES, ParameterType::STRING);
        $this->db->setQuery($query)->execute();

        $missing = array_diff(self::LANGUAGES, $this->db->setQuery(
            $this->db->createQuery()->select('lang_code')->from('#__languages')
        )->loadColumn());

        if ($missing) {
            $this->io->warning('Content languages missing (language pack not installed?): ' . implode(', ', $missing));
        }
    }

    private function ensurePlugins(): void
    {
        $params = json_encode([
            'detect_browser'        => '0',
            'automatic_change'      => '1',
            'item_associations'     => '1',
            'alternate_meta'        => '1',
            'xdefault'              => '1',
            'xdefault_language'     => 'default',
            'remove_default_prefix' => '0',
            'lang_cookie'           => '0',
        ]);

        $query = $this->db->createQuery()
            ->update($this->db->quoteName('#__extensions'))
            ->set($this->db->quoteName('enabled') . ' = 1')
            ->set($this->db->quoteName('params') . ' = :params')
            ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
            ->where($this->db->quoteName('folder') . ' = ' . $this->db->quote('system'))
            ->where($this->db->quoteName('element') . ' = ' . $this->db->quote('languagefilter'))
            ->bind(':params', $params);
        $this->db->setQuery($query)->execute();

        // The "Welcome to Joomla" tour would open over every page on each account's first visit.
        $this->db->setQuery(
            $this->db->createQuery()->update($this->db->quoteName('#__guidedtours'))->set($this->db->quoteName('autostart') . ' = 0')
        )->execute();

        // No "Help us make Joomla better" statistics prompt on every page of a demo.
        $this->db->setQuery(
            $this->db->createQuery()->update($this->db->quoteName('#__extensions'))->set($this->db->quoteName('enabled') . ' = 0')
                ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
                ->where($this->db->quoteName('folder') . ' = ' . $this->db->quote('system'))
                ->where($this->db->quoteName('element') . ' = ' . $this->db->quote('stats'))
        )->execute();

        foreach (['supertext', 'languagecode'] as $element) {
            $query = $this->db->createQuery()
                ->update($this->db->quoteName('#__extensions'))
                ->set($this->db->quoteName('enabled') . ' = 1')
                ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
                ->where($this->db->quoteName('folder') . ' = ' . $this->db->quote('system'))
                ->where($this->db->quoteName('element') . ' = :element')
                ->bind(':element', $element);
            $this->db->setQuery($query)->execute();
        }
    }

    /* ------------------------------------------------------------ menus/modules */

    /** Joomla's language filter needs a home page per content language. */
    private function ensureHomePages(): void
    {
        $componentId = (int) ComponentHelper::getComponent('com_content')->id;

        foreach (self::LANGUAGES as $tag) {
            $exists = $this->db->setQuery(
                $this->db->createQuery()
                    ->select('COUNT(*)')
                    ->from($this->db->quoteName('#__menu'))
                    ->where('home = 1')
                    ->where('client_id = 0')
                    ->where('published = 1')
                    ->where($this->db->quoteName('language') . ' = :tag')
                    ->bind(':tag', $tag)
            )->loadResult();

            if ($exists) {
                continue;
            }

            $sef      = strtolower(explode('-', $tag)[0]);
            $menutype = 'mainmenu-' . $sef;

            $type = new MenuType($this->db);

            if (!$type->load(['menutype' => $menutype])) {
                $type->bind(['menutype' => $menutype, 'title' => 'Main Menu (' . $tag . ')', 'description' => 'Home page for ' . $tag, 'client_id' => 0]);
                $type->store();
            }

            $item = new Menu($this->db);
            $item->setLocation(1, 'last-child');
            $item->bind([
                'menutype'     => $menutype,
                'title'        => 'Home (' . $tag . ')',
                'alias'        => 'home-' . $sef,
                'path'         => 'home-' . $sef,
                'link'         => 'index.php?option=com_content&view=featured',
                'type'         => 'component',
                'published'    => 1,
                'parent_id'    => 1,
                'level'        => 1,
                'component_id' => $componentId,
                'access'       => 1,
                'params'       => '{}',
                'home'         => 1,
                'language'     => $tag,
                'client_id'    => 0,
                'img'          => '',
                'template_style_id' => 0,
            ]);

            if (!$item->check() || !$item->store()) {
                $this->io->warning("Could not create the home page for {$tag}: " . $item->getError());

                continue;
            }

            $this->io->writeln("Home page for {$tag} created.");
        }
    }

    private function ensureLanguageSwitcher(): void
    {
        $exists = $this->db->setQuery(
            $this->db->createQuery()->select('COUNT(*)')->from('#__modules')
                ->where($this->db->quoteName('module') . ' = ' . $this->db->quote('mod_languages'))
                ->where('client_id = 0')
        )->loadResult();

        if ($exists) {
            return;
        }

        $module = new Module($this->db);
        $module->bind([
            'title'     => 'Languages',
            'module'    => 'mod_languages',
            'position'  => 'sidebar-right',
            'published' => 1,
            'access'    => 1,
            'showtitle' => 1,
            'params'    => json_encode(['dropdown' => '0', 'image' => '1', 'full_name' => '1', 'inline' => '1']),
            'language'  => '*',
            'client_id' => 0,
            'ordering'  => 1,
        ]);

        if (!$module->store()) {
            $this->io->warning('Could not create the language switcher: ' . $module->getError());

            return;
        }

        $this->db->setQuery(
            $this->db->createQuery()->insert('#__modules_menu')->columns(['moduleid', 'menuid'])->values((int) $module->id . ', 0')
        )->execute();
    }

    /* ------------------------------------------------------------- sample data */

    private function ensureSampleContent(): void
    {
        $samples = [
            [
                'alias'     => 'swiss-chocolate-shipped-worldwide',
                'title'     => 'Swiss chocolate, shipped worldwide',
                'introtext' => '<p>How a small family business in Bern brings handmade pralines to <strong>40 countries</strong>.</p>',
                'fulltext'  => '<h2>From Bern to the world</h2>'
                    . '<p>Every praline is made by hand in our <strong>Bern</strong> workshop. Read more on <a href="https://www.supertext.com">our website</a>.</p>'
                    . '<ul><li>Fresh ingredients from local farmers</li><li>Climate-neutral delivery within 48 hours</li></ul>',
                'metadesc'  => 'Handmade pralines from Bern, delivered fresh to 40 countries.',
                'metakey'   => 'chocolate, pralines, Bern',
                'images'    => [
                    'image_intro'         => 'images/headers/maple.jpg',
                    'image_intro_alt'     => 'Autumn leaves on a maple tree',
                    'image_intro_caption' => 'Our workshop in autumn',
                    'float_intro'         => '',
                    'image_fulltext'      => '',
                    'image_fulltext_alt'  => '',
                    'image_fulltext_caption' => '',
                    'float_fulltext'      => '',
                ],
                'featured'  => 1,
            ],
            [
                'alias'     => 'opening-hours-and-contact',
                'title'     => 'Opening hours and contact',
                'introtext' => '<p>Visit our shop in the old town or order online. We are happy to <em>help you choose</em>.</p>',
                'fulltext'  => '<p>Monday to Friday, 9 am to 6 pm. Saturday, 9 am to 4 pm.</p>',
                'metadesc'  => 'Opening hours of our chocolate shop in Bern.',
                'metakey'   => '',
                'images'    => [],
                'featured'  => 1,
            ],
        ];

        $userId = (int) $this->db->setQuery(
            $this->db->createQuery()->select('MIN(user_id)')->from('#__user_usergroup_map')->where('group_id = 8')
        )->loadResult();

        foreach ($samples as $sample) {
            $article = new Content($this->db);

            if ($article->load(['alias' => $sample['alias'], 'catid' => 2, 'language' => 'en-GB'])) {
                continue;
            }

            $now = Factory::getDate()->toSql();
            $article->bind([
                'title'      => $sample['title'],
                'alias'      => $sample['alias'],
                'introtext'  => $sample['introtext'],
                'fulltext'   => $sample['fulltext'],
                'state'      => 1,
                'catid'      => 2,
                'created'    => $now,
                'modified'   => $now,
                'publish_up' => $now,
                'created_by' => $userId,
                'images'     => json_encode($sample['images'] ?: new \stdClass()),
                'urls'       => '{}',
                'attribs'    => '{}',
                'metadata'   => '{"robots":"","author":"","rights":""}',
                'metadesc'   => $sample['metadesc'],
                'metakey'    => $sample['metakey'],
                'access'     => 1,
                'language'   => 'en-GB',
                'featured'   => $sample['featured'],
                'version'    => 1,
            ]);

            if (!$article->check() || !$article->store()) {
                $this->io->warning('Could not add the sample article: ' . $article->getError());

                continue;
            }

            if ($sample['featured']) {
                $this->db->setQuery(
                    $this->db->createQuery()->insert('#__content_frontpage')->columns(['content_id', 'ordering'])->values((int) $article->id . ', 0')
                )->execute();
            }

            $this->setWorkflowStage((int) $article->id);
            $this->io->writeln('Sample article added: ' . $sample['title']);
        }
    }

    /** Articles need a workflow association even when workflows are off. */
    private function setWorkflowStage(int $articleId): void
    {
        $stage = (int) $this->db->setQuery(
            $this->db->createQuery()->select('s.id')->from($this->db->quoteName('#__workflow_stages', 's'))
                ->join('INNER', $this->db->quoteName('#__workflows', 'w'), 'w.id = s.workflow_id')
                ->where('w.' . $this->db->quoteName('default') . ' = 1')
                ->where('s.' . $this->db->quoteName('default') . ' = 1')
        )->loadResult();

        if ($stage) {
            $this->db->setQuery(
                $this->db->createQuery()->insert('#__workflow_associations')->columns(['item_id', 'stage_id', 'extension'])
                    ->values($articleId . ', ' . $stage . ', ' . $this->db->quote('com_content.article'))
            )->execute();
        }
    }
}
