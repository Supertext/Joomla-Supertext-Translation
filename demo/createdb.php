<?php
// Creates the Joomla database on the PostgreSQL server from DATABASE_URL if it does not exist.
// Prints "host port user dbname" for the entrypoint (the password stays in the environment).
$url = parse_url((string) getenv('DATABASE_URL'));
if (!$url || ($url['scheme'] ?? '') === '' || !str_starts_with($url['scheme'], 'postgres')) {
    fwrite(STDERR, "DATABASE_URL must be a postgresql:// URL\n");
    exit(1);
}
$host = $url['host'];
$port = (int) ($url['port'] ?? 5432);
$user = rawurldecode($url['user'] ?? '');
$pass = rawurldecode($url['pass'] ?? '');
$name = getenv('JOOMLA_DB_NAME') ?: 'joomla';
if (!preg_match('/^[a-z_][a-z0-9_]*$/', $name)) {
    fwrite(STDERR, "JOOMLA_DB_NAME may only contain a-z, 0-9 and _\n");
    exit(1);
}
$admin = ltrim($url['path'] ?? '/postgres', '/') ?: 'postgres';
for ($i = 0; ; $i++) {
    try {
        $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$admin", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        break;
    } catch (PDOException $e) {
        if ($i >= 20) {
            fwrite(STDERR, "Cannot reach PostgreSQL: {$e->getMessage()}\n");
            exit(1);
        }
        sleep(3);
    }
}
$exists = $pdo->query("SELECT 1 FROM pg_database WHERE datname = " . $pdo->quote($name))->fetchColumn();
if (!$exists) {
    $pdo->exec("CREATE DATABASE \"$name\"");
    fwrite(STDERR, "Created database $name\n");
}
echo "$host $port $user $name\n";
