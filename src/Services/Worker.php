<?php
declare(strict_types=1);

namespace Axi\Services;

use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Core\Logger;
use Axi\Core\RateLimiter;
use Axi\Services\Payment\Escrow;
use Axi\Services\Payment\WebhookProcessor;

/**
 * Worker (cron/worker.php) : webhooks de paiement d'abord, puis emails et notifications du téléphone, et chaque
 * minute la réconciliation des paiements en attente. Plusieurs workers peuvent tourner en même temps.
 */
final class Worker
{
    /** @return array<string, int> ce qui a été fait */
    public static function run(int $maxSeconds, ?callable $log = null): array
    {
        $stats = ['webhooks' => 0, 'jobs' => 0, 'reconciled' => 0];
        $end = time() + $maxSeconds;
        $nextMinute = 0;
        $nextBeat = 0;
        while (time() < $end) {
            if (time() >= $nextBeat) {
                self::beat();
                $nextBeat = time() + 10;
            }
            $done = 0;
            foreach (WebhookProcessor::claim(20) as $row) {
                WebhookProcessor::process($row);
                $done++;
                $stats['webhooks']++;
            }
            foreach (Jobs::claim(20) as $row) {
                Jobs::process($row);
                $done++;
                $stats['jobs']++;
            }
            if (time() >= $nextMinute) {
                $nextMinute = time() + 60;
                $stats['reconciled'] += Escrow::reconcile(50);
                Jobs::releaseStuck();
                RateLimiter::purge();
            }
            if ($done > 0 && $log !== null) {
                $log("$done tâche(s) traitée(s)");
            }
            if ($done === 0) {
                usleep(500_000);
            }
        }
        return $stats;
    }

    /** Signe de vie : tant qu'il est récent, les requêtes laissent leurs tâches au worker */
    public static function beat(): void
    {
        Database::run('INSERT INTO worker_heartbeats (name, beat_at) VALUES (?, ?) ON DUPLICATE KEY UPDATE beat_at = VALUES(beat_at)', ['main', Clock::now()]);
    }

    /** Arrêt propre : les requêtes reprennent elles-mêmes leurs tâches dès maintenant */
    public static function stop(): void
    {
        try {
            Database::run('DELETE FROM worker_heartbeats WHERE name = ?', ['main']);
        } catch (\Throwable $e) {
            Logger::write('jobs', 'Arrêt du worker : ' . $e->getMessage());
        }
    }
}
