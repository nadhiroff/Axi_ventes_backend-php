<?php
declare(strict_types=1);

namespace Axi\Controllers;

use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Core\Request;
use Axi\Core\Response;

/** « Demander la visite d'un agent » : un client qui tient un étal demande son enrôlement */
final class AgentVisitController
{
    public function create(Request $req): never
    {
        if ($req->role() === 'merchant') {
            throw new HttpException(409, 'ALREADY_MERCHANT', 'Votre étal est déjà enregistré sur Axi.');
        }
        $open = Database::value('SELECT id FROM agent_visit_requests WHERE user_id = ? AND status IN (\'new\', \'scheduled\')', [$req->userId()]);
        if ($open === null) {
            Database::run('INSERT INTO agent_visit_requests (user_id, created_at) VALUES (?, ?)', [$req->userId(), Clock::now()]);
        }
        Response::ok(['message' => 'Demande enregistrée. Un agent Axi vous appelle pour fixer sa visite à votre étal.']);
    }
}
