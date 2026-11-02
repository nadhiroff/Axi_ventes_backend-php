<?php
declare(strict_types=1);

namespace Axi\Web\Controllers;

use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Core\Request;
use Axi\Services\AccountEraser;
use Axi\Services\AdminLog;
use Axi\Services\Jobs;
use Axi\Services\Notifier;
use Axi\Web\Html;
use Axi\Web\View;
use Axi\Web\WebAuth;

/**
 * Agent Axi : demandes de suppression de compte envoyées depuis l'application (mot de passe + raison).
 * « Effacer définitivement » supprime le compte et toutes ses informations, fichiers compris ; « Annuler » garde le
 * compte. La personne est prévenue par email dans les deux cas.
 */
final class Deletions
{
    private const STATUS = [
        'pending' => ['En attente', 'warn'],
        'erased' => ['Compte effacé', 'danger'],
        'cancelled' => ['Annulée par Axi', 'muted'],
        'withdrawn' => ['Retirée par la personne', 'muted'],
    ];

    public function index(Request $req): never
    {
        WebAuth::require(['axi']);
        $status = array_key_exists($_GET['status'] ?? 'pending', self::STATUS) ? ($_GET['status'] ?? 'pending') : 'pending';
        $rows = Database::all(
            'SELECT d.*, u.name, u.email, u.role, u.created_at AS user_created_at, a.name AS agent
             FROM account_deletion_requests d LEFT JOIN users u ON u.id = d.user_id LEFT JOIN users a ON a.id = d.decided_by
             WHERE d.status = ? ORDER BY d.id ' . ($status === 'pending' ? 'ASC' : 'DESC') . ' LIMIT 200',
            [$status],
        );
        $tabs = '<nav class="tabs">';
        foreach (self::STATUS as $k => [$label]) {
            $n = (int) Database::value('SELECT COUNT(*) FROM account_deletion_requests WHERE status = ?', [$k]);
            $tabs .= '<a href="' . View::url('/pro/suppressions', ['status' => $k]) . '" class="' . ($k === $status ? 'on' : '') . '">' . View::e($label) . " ($n)</a>";
        }
        $tabs .= '</nav>';

        $body = $tabs . Html::table(['Demandée le', 'Compte', 'Raison', $status === 'pending' ? 'Décision' : 'Traitée'], array_map(static function (array $d) use ($status): array {
            $who = $d['user_id'] === null || $d['status'] === 'erased'
                ? '<span class="muted">Compte n° ' . (int) ($d['user_id'] ?? 0) . ' (effacé)</span>'
                : '<a href="' . View::url('/pro/clients/' . $d['user_id']) . '"><strong>' . View::e($d['name']) . '</strong></a><br><span class="muted small">'
                    . View::e(($d['role'] === 'merchant' ? 'Commerçant' : 'Client') . ' · ' . $d['email'] . ' · inscrit le ' . date('d/m/Y', strtotime($d['user_created_at']))) . '</span>';
            if ($status === 'pending') {
                $blocking = AccountEraser::blockingReason((int) $d['user_id']);
                $decision = '<div class="actions">'
                    . ($blocking !== null
                        ? View::badge('À régler d\'abord', 'warn') . '<br><span class="small">' . View::e($blocking) . '</span>'
                        : View::action('/pro/suppressions/' . $d['id'] . '/effacer', 'Effacer définitivement', [], 'Le compte, ses informations, ses pièces, ses messages et son étal seront effacés pour toujours. Seules les commandes et paiements restent, anonymes, pour la comptabilité. Cette action est irréversible.', 'btn danger sm'))
                    . self::cancelPopup((int) $d['id'], (string) $d['name'])
                    . '</div>';
            } else {
                $decision = View::badge(self::STATUS[$d['status']][0], self::STATUS[$d['status']][1]) . '<br><span class="muted small">'
                    . View::date($d['decided_at']) . ($d['agent'] ? ' · ' . View::e($d['agent']) : '') . '</span>'
                    . ($d['agent_note'] ? '<br><span class="small">' . View::e($d['agent_note']) . '</span>' : '');
            }
            return [
                View::date($d['created_at']),
                $who,
                $d['reason'] !== null ? View::e($d['reason']) : '<span class="muted">Effacée avec le compte</span>',
                $decision,
            ];
        }, $rows), $status === 'pending' ? 'Aucune demande de suppression en attente.' : 'Aucune demande.');

        Html::page('Suppressions de compte', $body, 'Demandes envoyées depuis l\'application (mot de passe et raison). Effacez le compte et toutes ses informations, ou annulez la demande : la personne est prévenue par email.');
    }

    /** Effacement définitif du compte et de ses informations */
    public function erase(Request $req): never
    {
        $agent = WebAuth::require(['axi']);
        WebAuth::checkCsrf();
        $d = $this->pending((int) ($req->params['id'] ?? 0));
        $userId = (int) $d['user_id'];
        $user = Database::one('SELECT first_name, email FROM users WHERE id = ?', [$userId]) ?? throw new HttpException(404, 'NOT_FOUND', 'Compte introuvable.');
        try {
            $result = AccountEraser::erase($userId);
        } catch (HttpException $e) {
            View::redirect('/pro/suppressions', $e->getMessage(), 'err');
        }
        // La raison était une information personnelle : elle part avec le compte. Reste : qui, quand, par quel agent
        Database::run("UPDATE account_deletion_requests SET status = 'erased', reason = NULL, decided_by = ?, decided_at = ? WHERE id = ?", [$agent['id'], Clock::now(), $d['id']]);
        Database::run("UPDATE account_deletion_requests SET reason = NULL WHERE user_id = ? OR (user_id IS NULL AND id = ?)", [$userId, $d['id']]);
        // Dernier email à l'adresse d'origine (le compte n'existe plus : envoyé directement, sans passer par lui)
        [$subject, $html, $text] = \Axi\Services\EmailTemplates::message('Votre compte Axi a été supprimé', (string) $user['first_name'], [
            'Comme vous l\'avez demandé, votre compte Axi et vos informations ont été supprimés définitivement.',
            'Les commandes et paiements passés restent enregistrés sans votre nom, comme la loi l\'exige pour la comptabilité.',
            'Merci d\'avoir utilisé Axi. Vous pouvez créer un nouveau compte à tout moment.',
        ]);
        Jobs::email((string) $user['email'], $subject, $html, $text);
        $req->user = $agent;
        AdminLog::record($req, 'account.erase', 'user', $userId, ['request_id' => (int) $d['id'], 'row_removed' => $result['removed_row'], 'files' => $result['files']]);
        View::redirect('/pro/suppressions', 'Compte effacé définitivement (' . $result['files'] . ' fichier(s) supprimé(s)). La personne a été prévenue par email.');
    }

    /** Annuler la demande : le compte est gardé, la personne reçoit le message de l'agent */
    public function cancel(Request $req): never
    {
        $agent = WebAuth::require(['axi']);
        WebAuth::checkCsrf();
        $d = $this->pending((int) ($req->params['id'] ?? 0));
        $note = trim((string) ($_POST['note'] ?? ''));
        if (mb_strlen($note) < 5) {
            View::redirect('/pro/suppressions', 'Expliquez à la personne pourquoi la demande est annulée.', 'err');
        }
        Database::run("UPDATE account_deletion_requests SET status = 'cancelled', agent_note = ?, decided_by = ?, decided_at = ? WHERE id = ? AND status = 'pending'", [mb_substr($note, 0, 500), $agent['id'], Clock::now(), $d['id']]);
        Notifier::send((int) $d['user_id'], 'account', 'Demande de suppression annulée', 'L\'équipe Axi a annulé votre demande de suppression : votre compte est conservé. Message de l\'équipe : ' . $note, [], [], true);
        $req->user = $agent;
        AdminLog::record($req, 'account.deletion_cancel', 'user', (int) $d['user_id'], ['request_id' => (int) $d['id'], 'note' => $note]);
        View::redirect('/pro/suppressions', 'Demande annulée : le compte est conservé et la personne a été prévenue.');
    }

    /** « Annuler la demande » : petite fenêtre avec le message envoyé à la personne */
    private static function cancelPopup(int $id, string $name): string
    {
        return '<details class="popup"><summary class="btn sm ghost">Annuler la demande</summary><div class="popup-wrap"><div class="popup-box">'
            . '<h3>Garder le compte de ' . View::e($name) . '</h3><p class="muted">La demande est annulée et la personne reçoit votre message par email et dans l\'application.</p>'
            . '<form method="post" action="' . View::url('/pro/suppressions/' . $id . '/annuler') . '" class="form">' . View::csrf()
            . '<div class="full"><textarea name="note" rows="3" required maxlength="500" placeholder="Ex. : des commandes sont encore en cours ; nous reprendrons votre demande après leur livraison."></textarea></div>'
            . '<div class="full"><div class="actions"><button class="btn ghost" type="button" onclick="this.closest(\'details\').open=false">Fermer</button><button class="btn" type="submit">Annuler la demande</button></div></div></form>'
            . '</div></div></details>';
    }

    private function pending(int $id): array
    {
        $d = Database::one("SELECT * FROM account_deletion_requests WHERE id = ? AND status = 'pending'", [$id]);
        if ($d === null || $d['user_id'] === null) {
            View::redirect('/pro/suppressions', 'Cette demande a déjà été traitée.', 'err');
        }
        return $d;
    }

    /** Demande en attente d'un compte (fiche utilisateur) */
    public static function pendingFor(int $userId): ?array
    {
        return Database::one("SELECT * FROM account_deletion_requests WHERE user_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1", [$userId]);
    }
}
