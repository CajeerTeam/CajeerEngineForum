<?php
// Trusted one-shot compatibility preload, mounted read-only by the update operator.
// The historical 0.9.9 code cannot be changed or resigned. Its postcheck was
// written before Redis was probed under a maintenance lock, so a healthy Redis
// falsely reports redis_required=false during signed rollback. Preserve the
// full postcheck, including the mandatory Redis ping, by loading the audited
// current monitor implementation only in update/recovery subprocesses.
declare(strict_types=1);
require_once '/opt/ce-update/ForumSearchMaintenance.php';
require_once '/opt/ce-update/OperationsMonitor.php';
