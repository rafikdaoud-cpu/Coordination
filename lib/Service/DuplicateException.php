<?php
namespace OCA\Coordination\Service;

/** Référence déjà utilisée : HTTP 409, message public. */
class DuplicateException extends \DomainException implements PublicException {}
