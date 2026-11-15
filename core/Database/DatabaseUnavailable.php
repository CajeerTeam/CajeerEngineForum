<?php

declare(strict_types=1);
namespace CajeerEngine\Database;

final class DatabaseUnavailable extends \RuntimeException
{
    /** Connection loss, server shutdown or exhausted connection slots; never classify integrity errors as outages. */
    public static function causedBy(\PDOException $error): bool
    {
        $state=(string)($error->errorInfo[0]??$error->getCode());
        $native=(int)($error->errorInfo[1]??0);
        return str_starts_with($state,'08') || in_array($state,['57P01','57P02','57P03','53300'],true)
            || ($state==='HY000' && in_array($native,[1040,2002,2003,2006,2013,2055],true))
            // libpq can lose the SQLSTATE when no server response is available.
            || ($state==='HY000' && $native===7 && preg_match('/server closed the connection unexpectedly|connection (?:is not|not) open|no connection to the server|connection to server .* failed|SSL connection has been closed unexpectedly/i',(string)($error->errorInfo[2]??$error->getMessage()))===1);
    }
}
