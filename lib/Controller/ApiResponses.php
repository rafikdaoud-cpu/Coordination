<?php
namespace OCA\Coordination\Controller;

use OCA\Coordination\Service\ConflictException;
use OCA\Coordination\Service\DuplicateException;
use OCA\Coordination\Service\PublicException;
use OCP\AppFramework\Http\JSONResponse;
use Throwable;

/**
 * Réponses d'erreur homogènes : code HTTP significatif, message public maîtrisé,
 * détail technique uniquement dans le journal Nextcloud. Requiert $this->logger.
 */
trait ApiResponses {
    private function error(string $message, int $status): JSONResponse {
        return new JSONResponse(['success' => false, 'error' => $message], $status);
    }

    private function failure(Throwable $e, string $action): JSONResponse {
        if ($e instanceof PublicException) {
            $status = ($e instanceof ConflictException || $e instanceof DuplicateException) ? 409 : 400;
            return $this->error($e->getMessage(), $status);
        }
        $this->logger->error('Coordination : échec de « ' . $action . ' »', ['app' => 'coordination', 'exception' => $e]);
        return $this->error('Erreur technique pendant « ' . $action . ' ». L’incident est journalisé ; vérifiez le dossier puis réessayez ou contactez l’administrateur.', 500);
    }
}
