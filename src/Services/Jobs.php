<?php
declare(strict_types=1);

namespace Axi\Services;

use Axi\Core\Clock;
use Axi\Core\Config;
use Axi\Core\Database;
use Axi\Core\Logger;
use Axi\Services\Payment\WebhookProcessor;

/**
 * File des tâches lentes (table outbox_jobs) : emails et notifications du téléphone. La requête qui les crée
 * répond sans attendre le SMTP ni Expo ; cron/worker.php les envoie et les réessaie en cas d'échec.
 * Sans worker actif (développement, hébergement sans tâche planifiée), la requête les traite elle-même
 * après sa réponse : rien ne reste jamais en attente.
 */
final class Jobs
{
    /** Délais avant chaque nouvel essai (secondes) ; au-delà : abandon, journalisé */
    public const BACKOFF = [10, 60, 300, 900, 1800, 3600, 3 * 3600];

    /** @var list<int> */
    private static array $outbox = [];
    /** @var list<int> */
    private static array $webhooks = [];
    private static bool $scheduled = false;
    private static ?bool $workerAlive = null;

    public static function email(string $to, string $subject, string $html, string $text, ?string $replyTo = null, ?string $replyName = null): void
    {
        self::enqueue('email', compact('to', 'subject', 'html', 'text', 'replyTo', 'replyName'));
    }

    /** Les téléphones du compte sont lus à l'envoi : une session fermée entre-temps ne reçoit rien */
    public static function push(int $userId, string $title, string $body, array $data = []): void
    {
        self::enqueue('push', ['user_id' => $userId, 'title' => $title, 'body' => $body, 'data' => $data]);
    }

    /** Webhook enregistré par cette requête : traité après la réponse si aucun worker ne tourne */
    public static function webhookReceived(int $eventId): void
    {
        self::$webhooks[] = $eventId;
        self::schedule();
    }

    private static function enqueue(string $kind, array $payload): void
    {
        self::$outbox[] = Database::insert(
            'INSERT INTO outbox_jobs (kind, payload, next_attempt_at, created_at) VALUES (?, ?, ?, ?)',
            [$kind, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), Clock::now(), Clock::now()],
        );
        self::schedule();
    }

    private static function schedule(): void
    {
        if (!self::$scheduled) {
            self::$scheduled = true;
            register_shutdown_function([self::class, 'runInline']);
        }
    }

    public static function workerAlive(): bool
    {
        if (self::$workerAlive === null) {
            $timeout = (int) Config::get('jobs.worker_timeout_seconds', 120);
            self::$workerAlive = Database::value('SELECT 1 FROM worker_heartbeats WHERE name = ? AND beat_at > ?', ['main', Clock::now(-$timeout)]) !== null;
        }
        return self::$workerAlive;
    }

    /** Fin de requête : sans worker, ses propres tâches sont traitées ici, après l'envoi de la réponse */
    public static function runInline(): void
    {
        try {
            if ((self::$outbox === [] && self::$webhooks === []) || self::workerAlive()) {
                return;
            }
            self::finishResponse();
            foreach (WebhookProcessor::claim(count(self::$webhooks), self::$webhooks) as $row) {
                WebhookProcessor::process($row);
            }
            foreach (self::claim(count(self::$outbox), self::$outbox) as $row) {
                self::process($row);
            }
        } catch (\Throwable $e) {
            // Rien n'est perdu : les tâches restent en base et seront reprises par le worker ou le cron
            Logger::write('jobs', 'Traitement en fin de requête interrompu : ' . $e->getMessage());
        }
    }

    /** Réponse envoyée au téléphone avant le travail lent (PHP-FPM, LiteSpeed) */
    public static function finishResponse(): void
    {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
    }

    /**
     * Réserve des tâches prêtes (plusieurs workers peuvent tourner : SKIP LOCKED, aucune n'est prise deux fois).
     * @param list<int>|null $ids seulement celles-ci
     */
    public static function claim(int $limit, ?array $ids = null): array
    {
        if ($limit <= 0) {
            return [];
        }
        return Database::transaction(static function () use ($limit, $ids): array {
            $only = $ids === null ? '' : ' AND id IN (' . Database::in($ids) . ')';
            $rows = Database::all(
                "SELECT * FROM outbox_jobs WHERE status = 'pending' AND next_attempt_at <= ?$only ORDER BY id LIMIT $limit FOR UPDATE SKIP LOCKED",
                [Clock::now(), ...($ids ?? [])],
            );
            if ($rows !== []) {
                Database::run(
                    "UPDATE outbox_jobs SET status = 'processing', locked_at = ?, attempts = attempts + 1 WHERE id IN (" . Database::in($rows) . ')',
                    [Clock::now(), ...array_column($rows, 'id')],
                );
            }
            return $rows;
        });
    }

    public static function process(array $row): void
    {
        $payload = json_decode((string) $row['payload'], true) ?: [];
        try {
            $ok = match ($row['kind']) {
                'email' => Mailer::send((string) $payload['to'], (string) $payload['subject'], (string) $payload['html'], (string) $payload['text'], $payload['replyTo'] ?? null, $payload['replyName'] ?? null),
                'push' => Push::sendNow((int) $payload['user_id'], (string) $payload['title'], (string) $payload['body'], (array) ($payload['data'] ?? [])),
                default => true,
            };
            $error = $ok ? null : 'envoi refusé ou service injoignable';
        } catch (\Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 250);
        }
        if ($error === null) {
            Database::run("UPDATE outbox_jobs SET status = 'done', locked_at = NULL, last_error = NULL WHERE id = ?", [$row['id']]);
            return;
        }
        self::retryLater('outbox_jobs', 'pending', (int) $row['id'], (int) $row['attempts'] + 1, $error);
    }

    /** Nouvel essai plus tard, ou abandon (statut dead, journalisé) après le dernier délai */
    public static function retryLater(string $table, string $readyStatus, int $id, int $attempts, string $error): void
    {
        $delay = self::BACKOFF[$attempts - 1] ?? null;
        if ($delay === null) {
            Database::run("UPDATE $table SET status = 'dead', locked_at = NULL, last_error = ? WHERE id = ?", [mb_substr($error, 0, 255), $id]);
            Logger::write('jobs', "ABANDON $table #$id après $attempts essais : $error");
            return;
        }
        Database::run(
            "UPDATE $table SET status = ?, locked_at = NULL, next_attempt_at = ?, last_error = ? WHERE id = ?",
            [$readyStatus, Clock::now($delay), mb_substr($error, 0, 255), $id],
        );
    }

    /** Tâche réservée par un worker arrêté net (coupure, redémarrage) : rendue à la file après 5 minutes */
    public static function releaseStuck(): int
    {
        $n = Database::run("UPDATE outbox_jobs SET status = 'pending' WHERE status = 'processing' AND locked_at < ?", [Clock::now(-300)])->rowCount();
        return $n + Database::run("UPDATE webhook_events SET status = 'received' WHERE status = 'processing' AND locked_at < ?", [Clock::now(-300)])->rowCount();
    }
}
