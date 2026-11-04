<?php
namespace OCA\Coordination\Service;

/** Conflit d'état ou d'écriture concurrente : HTTP 409, message public. */
class ConflictException extends \RuntimeException implements PublicException {}
