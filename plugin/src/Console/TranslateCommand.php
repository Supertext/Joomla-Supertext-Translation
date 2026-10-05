<?php

/**
 * @package     Supertext Translation for Joomla
 * @copyright   (C) Supertext AG
 * @license     GNU General Public License version 2 or later
 */

namespace Supertext\Plugin\System\Supertext\Console;

use Joomla\CMS\User\User;
use Joomla\CMS\User\UserHelper;
use Joomla\Console\Command\AbstractCommand;
use Joomla\Database\DatabaseInterface;
use Supertext\Plugin\System\Supertext\Service\ArticleTranslator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * php cli/joomla.php supertext:translate <article-id>... --to=de-CH --to=fr-FR [--overwrite] [--user=name]
 */
final class TranslateCommand extends AbstractCommand
{
    protected static $defaultName = 'supertext:translate';

    /** @param \Closure(): ArticleTranslator $translator */
    public function __construct(private readonly \Closure $translator, private readonly DatabaseInterface $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Translate articles with Supertext into other content languages');
        $this->addArgument('ids', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Article IDs');
        $this->addOption('to', 't', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Target language tag, e.g. de-CH (repeat for more). Default: all other content languages.');
        $this->addOption('overwrite', null, InputOption::VALUE_NONE, 'Update translations that already exist');
        $this->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Act as this user (permissions, author of new translations). Default: the first Super User.');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $user = $this->user((string) $input->getOption('user'));

        if (!$user) {
            $io->error('User not found.');

            return 1;
        }

        // Joomla's article model expects to run inside com_content; the CLI has no component.
        foreach (['JPATH_COMPONENT' => JPATH_ADMINISTRATOR, 'JPATH_COMPONENT_ADMINISTRATOR' => JPATH_ADMINISTRATOR, 'JPATH_COMPONENT_SITE' => JPATH_SITE] as $name => $base) {
            if (!\defined($name)) {
                \define($name, $base . '/components/com_content');
            }
        }

        $translator = ($this->translator)();
        $failed     = false;

        foreach ((array) $input->getArgument('ids') as $id) {
            $id      = (int) $id;
            $targets = (array) $input->getOption('to');

            try {
                if (!$targets) {
                    $targets = array_column($translator->describe($id, $user)['languages'], 'tag');
                }

                foreach ($translator->translate($id, $targets, (bool) $input->getOption('overwrite'), $user) as $result) {
                    $line = sprintf('Article %d → %s: %s', $id, $result['language'], $result['status']);

                    if (isset($result['id'])) {
                        $line .= sprintf(' (#%d "%s")', $result['id'], $result['title'] ?? '');
                    }

                    if ($result['status'] === 'error') {
                        $failed = true;
                        $io->writeln('<error>' . $line . ': ' . ($result['message'] ?? '') . '</error>');
                    } else {
                        $io->writeln($line);
                    }
                }
            } catch (\Throwable $e) {
                $failed = true;
                $io->error(sprintf('Article %d: %s', $id, $e->getMessage()));
            }
        }

        return $failed ? 1 : 0;
    }

    private function user(string $username): ?User
    {
        if ($username !== '') {
            $id = (int) UserHelper::getUserId($username);
        } else {
            $query = $this->db->createQuery()
                ->select('MIN(m.user_id)')
                ->from($this->db->quoteName('#__user_usergroup_map', 'm'))
                ->join('INNER', $this->db->quoteName('#__users', 'u'), 'u.id = m.user_id')
                ->where('m.group_id = 8')
                ->where('u.block = 0');
            $id = (int) $this->db->setQuery($query)->loadResult();
        }

        return $id ? User::getInstance($id) : null;
    }
}
