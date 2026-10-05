<?php
// Railway allows only a few volumes per project, so the demo keeps no files between
// deploys: Joomla's configuration.php is stored in the database next to the site.
//   php state.php get            prints the saved configuration.php (exit 1 if none)
//   php state.php put < file     saves it
$url  = parse_url((string) getenv('DATABASE_URL'));
$name = getenv('JOOMLA_DB_NAME') ?: 'joomla';
$pdo  = new PDO(
    sprintf('pgsql:host=%s;port=%d;dbname=%s', $url['host'], $url['port'] ?? 5432, $name),
    rawurldecode($url['user'] ?? ''),
    rawurldecode($url['pass'] ?? ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec('CREATE TABLE IF NOT EXISTS supertext_demo_state (name text PRIMARY KEY, value text NOT NULL)');

switch ($argv[1] ?? '') {
    case 'get':
        $value = $pdo->query("SELECT value FROM supertext_demo_state WHERE name = 'configuration.php'")->fetchColumn();
        if ($value === false) {
            exit(1);
        }
        echo $value;
        break;
    case 'put':
        $value = stream_get_contents(STDIN);
        if (!str_contains($value, 'class JConfig')) {
            fwrite(STDERR, "Not a Joomla configuration file\n");
            exit(1);
        }
        $stmt = $pdo->prepare("INSERT INTO supertext_demo_state (name, value) VALUES ('configuration.php', ?) ON CONFLICT (name) DO UPDATE SET value = EXCLUDED.value");
        $stmt->execute([$value]);
        break;
    default:
        fwrite(STDERR, "usage: state.php get|put\n");
        exit(2);
}
