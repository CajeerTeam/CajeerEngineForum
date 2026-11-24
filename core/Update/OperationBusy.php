<?php
declare(strict_types=1);
namespace CajeerEngine\Update;

/** No exclusive activity lease was acquired; no update mutation has begun. */
final class OperationBusy extends \RuntimeException {}
