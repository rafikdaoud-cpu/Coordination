<?php
namespace OCA\Coordination\Service;

/** Donnée saisie invalide : HTTP 400, message public. */
class ValidationException extends \InvalidArgumentException implements PublicException {}
