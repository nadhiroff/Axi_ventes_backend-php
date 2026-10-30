<?php
declare(strict_types=1);

namespace Axi\Controllers;

use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Core\Logger;
use Axi\Core\Request;
use Axi\Core\Response;
use Axi\Core\Url;
use Axi\Services\Payment\Escrow;
use Axi\Services\Payment\Gateways;
use Axi\Services\Payment\KkiapayGateway;
use Axi\Services\SignedUrl;

/**
 * Pages de paiement ouvertes dans l'application (WebView), retour de paiement et webhooks des passerelles.
 * Le statut « payé » n'est JAMAIS accordé d'après le retour de la page : il est toujours vérifié
 * serveur à serveur auprès de la passerelle (Escrow::refresh).
 */
final class PaymentController
{
    /** Page KkiaPay : ouvre le widget pour CE paiement (lien signé, montant lu en base) */
    public function kkiapay(Request $req): never
    {
        $ref = (string) SignedUrl::verify('/v1/payments/kkiapay', $req->queryAll())['ref'];
        $payment = Database::one('SELECT p.*, u.name, u.email FROM payments p JOIN users u ON u.id = p.user_id WHERE p.reference = ?', [$ref]);
        if ($payment === null || $payment['status'] !== 'pending') {
            $this->page('Paiement déjà traité', 'Ce paiement n\'est plus en attente. Revenez dans l\'application Axi.', $ref);
        }
        $gateway = Gateways::get('kkiapay');
        assert($gateway instanceof KkiapayGateway);
        $config = json_encode([
            'amount' => (int) $payment['amount'],
            'key' => $gateway->publicKey(),
            'sandbox' => $gateway->isSandbox(),
            'partnerId' => $payment['reference'],
            'name' => $payment['name'],
            'email' => $payment['email'],
            'position' => 'center',
            'theme' => '#C2410C',
            'paymentmethod' => ['momo'],
            'countries' => ['BJ'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        $return = json_encode(Url::to('/v1/payments/return?ref=' . rawurlencode($ref)), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        $amount = Escrow::fcfa((int) $payment['amount']);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header("Content-Security-Policy: default-src 'self' https://*.kkiapay.me; script-src 'self' 'unsafe-inline' https://cdn.kkiapay.me https://*.kkiapay.me; frame-src https://*.kkiapay.me; style-src 'self' 'unsafe-inline'; img-src * data:; connect-src *");
        echo <<<HTML
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Paiement Axi</title>
<style>body{margin:0;font-family:Arial,sans-serif;background:#FAF7F2;color:#1C1917;display:flex;min-height:100vh;align-items:center;justify-content:center}
.box{background:#fff;border-radius:16px;padding:28px;max-width:420px;width:90%;text-align:center}.logo{color:#C2410C;font-size:32px;font-weight:bold}
button{background:#C2410C;color:#fff;border:0;border-radius:12px;padding:14px 20px;font-size:16px;font-weight:bold;width:100%;margin-top:16px}</style></head>
<body><div class="box"><div class="logo">axi</div><p>Montant à payer : <strong>$amount</strong></p>
<p>Validez avec votre compte Mobile Money. Vous ne serez débité qu'une seule fois.</p>
<button onclick="pay()">Payer maintenant</button><p id="msg"></p></div>
<script src="https://cdn.kkiapay.me/k.js"></script>
<script>
var cfg = $config, back = $return;
function pay(){ openKkiapayWidget(cfg); }
addSuccessListener(function(r){ var id = r && (r.transactionId || r.transaction_id || r.id); location.href = back + '&transaction_id=' + encodeURIComponent(id || ''); });
addFailedListener(function(){ document.getElementById('msg').textContent = 'Paiement non abouti. Vous pouvez réessayer.'; });
setTimeout(pay, 400);
</script></body></html>
HTML;
        exit;
    }

    /** Retour après paiement (FedaPay : ?id=&status= ; KkiaPay : &transaction_id=) : vérification serveur puis message */
    public function return(Request $req): never
    {
        $ref = (string) $req->query('ref', '');
        $payment = Database::one('SELECT * FROM payments WHERE reference = ?', [$ref]);
        if ($payment === null) {
            $this->page('Paiement introuvable', 'Revenez dans l\'application Axi.', null);
        }
        $tx = $req->query('transaction_id');
        if ($payment['status'] === 'pending') {
            $payment = Escrow::refresh((int) $payment['id'], 'gateway', null, $payment['provider'] === 'kkiapay' && is_string($tx) && $tx !== '' ? $tx : null);
        }
        [$title, $text] = match ($payment['status']) {
            'paid' => ['Paiement reçu', 'Merci ! L\'argent reste chez Axi jusqu\'à ce que vous confirmiez la réception. Revenez dans l\'application.'],
            'pending' => ['Paiement en cours de vérification', 'Revenez dans l\'application : le statut se mettra à jour dans quelques instants.'],
            default => ['Paiement non abouti', 'Aucun montant n\'a été prélevé. Revenez dans l\'application pour réessayer.'],
        };
        $this->page($title, $text, $payment['order_id'] !== null ? 'cmd_' . $payment['order_id'] : null);
    }

    public function fedapayWebhook(Request $req): never
    {
        $this->webhook('fedapay', $req);
    }

    public function kkiapayWebhook(Request $req): never
    {
        $this->webhook('kkiapay', $req);
    }

    /**
     * Webhook : signature vérifiée, événement dédoublonné (webhook_events), puis statut vérifié auprès de la
     * passerelle. Un même événement reçu deux fois ne crée jamais deux paiements.
     */
    private function webhook(string $provider, Request $req): never
    {
        $raw = (string) file_get_contents('php://input');
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
            }
        }
        $event = Gateways::get($provider)->parseWebhook($headers, $raw);
        if ($event === null) {
            Logger::write('payments', "WEBHOOK $provider REFUSÉ (signature invalide)");
            Response::error('INVALID_SIGNATURE', 'Signature invalide.', 401);
        }
        try {
            Database::run('INSERT INTO webhook_events (provider, event_key, payload, received_at) VALUES (?, ?, ?, ?)', [$provider, mb_substr($event['key'], 0, 190), $raw, Clock::now()]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                Response::ok(['duplicate' => true]);
            }
            throw $e;
        }
        $payment = $event['provider_ref'] !== null ? Database::one('SELECT * FROM payments WHERE provider = ? AND provider_ref = ?', [$provider, $event['provider_ref']]) : null;
        $payment ??= $event['reference'] !== null ? Database::one('SELECT * FROM payments WHERE reference = ?', [$event['reference']]) : null;
        $result = 'unknown_payment';
        if ($payment !== null) {
            $after = Escrow::refresh((int) $payment['id'], 'gateway', null, $provider === 'kkiapay' ? $event['provider_ref'] : null);
            $result = 'status:' . $after['status'];
        }
        Database::run('UPDATE webhook_events SET processed_at = ?, result = ? WHERE provider = ? AND event_key = ?', [Clock::now(), $result, $provider, mb_substr($event['key'], 0, 190)]);
        Response::ok(['received' => true]);
    }

    private function page(string $title, string $text, ?string $orderId): never
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $link = $orderId === null ? 'axi://' : 'axi://commande/' . rawurlencode($orderId);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e($title) . '</title>'
            . '<style>body{margin:0;font-family:Arial,sans-serif;background:#FAF7F2;color:#1C1917;display:flex;min-height:100vh;align-items:center;justify-content:center}'
            . '.box{background:#fff;border-radius:16px;padding:28px;max-width:420px;width:90%;text-align:center}.logo{color:#C2410C;font-size:32px;font-weight:bold}'
            . 'a{display:block;background:#C2410C;color:#fff;text-decoration:none;border-radius:12px;padding:14px;font-weight:bold;margin-top:16px}</style></head>'
            . '<body><div class="box"><div class="logo">axi</div><h1 style="font-size:22px">' . $e($title) . '</h1><p>' . $e($text) . '</p>'
            . '<a href="' . $e($link) . '">Revenir dans l\'application</a></div></body></html>';
        exit;
    }
}
