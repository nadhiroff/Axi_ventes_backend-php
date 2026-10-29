<?php
declare(strict_types=1);

// Applique, dans l'ordre, les fichiers database/migrations/*.sql pas encore exécutés.
// Usage : php database/migrate.php
// La table schema_migrations garde la liste des fichiers déjà appliqués.

use Axi\Core\Database;

require dirname(__DIR__) . '/src/bootstrap.php';

$pdo = Database::pdo();
$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
  file VARCHAR(190) PRIMARY KEY,
  applied_at DATETIME NOT NULL
) ENGINE=InnoDB');

$applied = array_flip(array_column(Database::all('SELECT file FROM schema_migrations'), 'file'));

// Base créée avant ce script avec 001_auth.sql : on le note comme appliqué
if (!isset($applied['001_auth.sql']) && Database::value("SHOW TABLES LIKE 'auth_tokens'") !== null) {
    Database::run('INSERT INTO schema_migrations (file, applied_at) VALUES (?, UTC_TIMESTAMP())', ['001_auth.sql']);
    $applied['001_auth.sql'] = true;
}

$files = glob(__DIR__ . '/migrations/*.sql');
sort($files);
$count = 0;
foreach ($files as $path) {
    $file = basename($path);
    if (isset($applied[$file])) {
        continue;
    }
    echo "→ $file\n";
    $sql = (string) file_get_contents($path);
    // Les fichiers peuvent aussi être importés à la main (mysql, phpMyAdmin) : on ignore leurs USE / CREATE DATABASE
    $sql = (string) preg_replace('/^\s*(USE|CREATE DATABASE)\b[^;]*;/mi', '', $sql);
    $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (preg_split('/;\s*$/m', $sql) as $statement) {
        if (trim($statement) !== '') {
            $pdo->exec($statement);
        }
    }
    Database::run('INSERT INTO schema_migrations (file, applied_at) VALUES (?, UTC_TIMESTAMP())', [$file]);
    $count++;
}
echo $count === 0 ? "Base à jour.\n" : "$count migration(s) appliquée(s).\n";
