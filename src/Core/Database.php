<?php
declare(strict_types=1);

namespace Axi\Core;

use PDO;

/** Connexion PDO unique, ouverte seulement quand une requête en a besoin. */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $db = Config::get('db');
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $db['host'], $db['port'], $db['name'], $db['charset']);
            self::$pdo = new PDO($dsn, $db['user'], $db['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
            // Toutes les dates sont stockées en UTC
            self::$pdo->exec("SET time_zone = '+00:00'");
        }
        return self::$pdo;
    }

    /** Exécute une requête préparée et renvoie le statement. */
    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute(array_values($params));
        return $stmt;
    }

    public static function one(string $sql, array $params = []): ?array
    {
        return self::run($sql, $params)->fetch() ?: null;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    public static function insert(string $sql, array $params = []): int
    {
        self::run($sql, $params);
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * UPDATE table SET col = ? ... WHERE id = ?
     * Les noms de colonnes viennent toujours du code (listes blanches), jamais de l'utilisateur.
     */
    public static function updateById(string $table, int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $set = implode(', ', array_map(static fn (string $col): string => "$col = ?", array_keys($fields)));
        self::run("UPDATE $table SET $set WHERE id = ?", [...array_values($fields), $id]);
    }

    /** Insertion groupée par paquets de 300 lignes */
    public static function insertMany(string $table, array $columns, array $rows): void
    {
        $placeholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        foreach (array_chunk($rows, 300) as $chunk) {
            $sql = "INSERT INTO $table (" . implode(', ', $columns) . ') VALUES ' . implode(', ', array_fill(0, count($chunk), $placeholder));
            self::run($sql, array_merge(...array_map('array_values', $chunk)));
        }
    }

    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Verrou MySQL nommé (GET_LOCK) : deux requêtes simultanées du même client attendent leur tour.
     * Le verrou est libéré même en cas d'erreur.
     */
    public static function withLock(string $name, int $timeoutSeconds, callable $fn): mixed
    {
        if ((int) self::value('SELECT GET_LOCK(?, ?)', [$name, $timeoutSeconds]) !== 1) {
            throw new HttpException(409, 'REQUEST_IN_PROGRESS', 'Une demande est déjà en cours. Patientez quelques secondes.');
        }
        try {
            return $fn();
        } finally {
            self::value('SELECT RELEASE_LOCK(?)', [$name]);
        }
    }

    /** Liste de « ? » pour une clause IN */
    public static function in(array $values): string
    {
        return implode(', ', array_fill(0, max(1, count($values)), '?'));
    }
}
