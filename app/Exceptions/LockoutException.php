<?php

namespace App\Exceptions;

use DomainException;

class LockoutException extends DomainException
{
    public static function lastRolesManager(): self
    {
        return new self('Operazione non consentita: non resterebbe nessun utente attivo con il permesso roles.manage.');
    }

    public static function lastAdminAssigner(): self
    {
        return new self('Operazione non consentita: non resterebbe nessun utente attivo con il permesso admin.assign.');
    }
}
