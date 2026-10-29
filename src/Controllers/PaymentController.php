<?php
declare(strict_types=1);

namespace Axi\Controllers;

use Axi\Core\HttpException;
use Axi\Core\Logger;
use Axi\Core\Request;
use Axi\Core\Response;
use Axi\Core\Validator;
use Axi\Models\Payment;
use Axi\Services\PaymentService;

final class PaymentController
{
    /** GET /v1/payments/{id} */
    public function show(Request $request): never
    {
        Response::ok(Payment::present($this->ownPayment($request)));
    }

    /**
     * POST /v1/payments/{id}/verify {transaction_id?}
     * Après le widget Kkiapay (transaction_id obligatoire) ou au retour de la page FedaPay.
     */
    public function verify(Request $request): never
    {
        $payment = $this->ownPayment($request);
        $in = Validator::validate($request->all(), ['transaction_id' => 'string|max:64']);
        Response::ok(Payment::present(PaymentService::verify($payment, $in['transaction_id'] ?? null)));
    }

    /** GET /v1/payments/fedapay/callback?id= : retour du client après la page de paiement FedaPay */
    public function fedapayCallback(Request $request): never
    {
        $ref = (string) $request->query('id', '');
        $payment = $ref === '' ? null : Payment::findByRef('fedapay', $ref);
        if ($payment === null) {
            throw new HttpException(404, 'NOT_FOUND', 'Paiement introuvable');
        }
        Response::ok(Payment::present(PaymentService::verify($payment)));
    }

    /** POST /v1/webhooks/fedapay : on relit toujours la transaction chez FedaPay avant d'agir */
    public function fedapayWebhook(Request $request): never
    {
        $ref = $request->input('entity')['id'] ?? null;
        $payment = $ref === null ? null : Payment::findByRef('fedapay', (string) $ref);
        $this->handleWebhook('fedapay', $payment, null);
    }

    /** POST /v1/webhooks/kkiapay {transactionId, stateData?} */
    public function kkiapayWebhook(Request $request): never
    {
        $ref = (string) ($request->input('transactionId') ?? '');
        $payment = $ref === '' ? null : Payment::findByRef('kkiapay', $ref);
        // Première notification : on retrouve le paiement grâce au champ data transmis au widget
        $data = $request->input('stateData') ?? $request->input('data');
        if ($payment === null && $ref !== '' && is_scalar($data) && ctype_digit((string) $data)) {
            $candidate = Payment::find((int) $data);
            $payment = $candidate !== null && $candidate['provider'] === 'kkiapay' ? $candidate : null;
        }
        $this->handleWebhook('kkiapay', $payment, $ref === '' ? null : $ref);
    }

    private function handleWebhook(string $provider, ?array $payment, ?string $ref): never
    {
        if ($payment !== null) {
            try {
                PaymentService::verify($payment, $ref);
            } catch (HttpException $e) {
                Logger::write('payments', "Webhook $provider paiement #{$payment['id']} : {$e->errorCode}");
            }
        }
        // Toujours 200 : le fournisseur n'a pas à connaître notre état interne
        Response::ok(['received' => true]);
    }

    private function ownPayment(Request $request): array
    {
        $payment = Payment::find($request->id());
        if ($payment === null || (int) $payment['user_id'] !== $request->userId()) {
            throw new HttpException(404, 'NOT_FOUND', 'Paiement introuvable');
        }
        return $payment;
    }
}
