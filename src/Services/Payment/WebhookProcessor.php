<?php
declare(strict_types=1);

namespace Axi\Services\Payment;

use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Services\Jobs;

/**
 * Traitement des webhooks enregistrés (webhook_events) : le statut est TOUJOURS revérifié auprès de la passerelle.
 * Passerelle injoignable ou erreur : l'événement est rejoué plus tard (10 s, 1 min, 5 min…), jamais abandonné
 * en silence. Rejouer un événement est sans risque : chaque changement d'état est conditionnel.
 */
final class WebhookProcessor
{
    /** @param list<int>|null $ids seulement ces événements */
    public static function claim(int $limit, ?array $ids = null): array
    {
        if ($limit <= 0) {
            return [];
        }
        return Database::transaction(static function () use ($limit, $ids): array {
            $only = $ids === null ? '' : ' AND id IN (' . Database::in($ids) . ')';
            $rows = Database::all(
                "SELECT * FROM webhook_events WHERE status = 'received' AND next_attempt_at <= ?$only ORDER BY id LIMIT $limit FOR UPDATE SKIP LOCKED",
                [Clock::now(), ...($ids ?? [])],
            );
            if ($rows !== []) {
                Database::run(
                    "UPDATE webhook_events SET status = 'processing', locked_at = ?, attempts = attempts + 1 WHERE id IN (" . Database::in($rows) . ')',
                    [Clock::now(), ...array_column($rows, 'id')],
                );
            }
            return $rows;
        });
    }

    public static function process(array $row): void
    {
        try {
            $result = self::handle($row);
        } catch (\Throwable $e) {
            Jobs::retryLater('webhook_events', 'received', (int) $row['id'], (int) $row['attempts'] + 1, $e->getMessage());
            return;
        }
        Database::run(
            "UPDATE webhook_events SET status = 'done', locked_at = NULL, last_error = NULL, processed_at = ?, result = ? WHERE id = ?",
            [Clock::now(), mb_substr($result, 0, 80), $row['id']],
        );
    }

    private static function handle(array $row): string
    {
        $provider = (string) $row['provider'];
        $txRef = $row['provider_ref'] !== null ? (string) $row['provider_ref'] : null;
        $payment = $txRef !== null ? Database::one('SELECT * FROM payments WHERE provider = ? AND provider_ref = ?', [$provider, $txRef]) : null;
        $payment ??= $row['reference'] !== null ? Database::one('SELECT * FROM payments WHERE reference = ?', [$row['reference']]) : null;
        if ($payment === null) {
            return 'unknown_payment';
        }
        // KkiaPay : l'identifiant de transaction vient d'un événement signé, il peut servir à la vérification
        $check = Escrow::check((int) $payment['id'], 'gateway', null, $provider === 'kkiapay' ? $txRef : null, true);
        if (!$check['reached']) {
            throw new GatewayException('passerelle injoignable, nouvel essai prévu');
        }
        $after = $check['payment'];
        // Deuxième transaction réussie pour une référence déjà payée : le client a payé deux fois
        if ($provider === 'kkiapay' && $txRef !== null && $row['claims_paid'] !== 0 && $after['provider_ref'] !== null && $after['provider_ref'] !== $txRef
            && in_array($after['status'], ['paid', 'partially_refunded', 'refunded'], true)) {
            return 'duplicate:' . Escrow::handleExtraTransaction($after, $txRef);
        }
        return 'status:' . $after['status'];
    }
}
