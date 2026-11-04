<?php

namespace OCA\Coordination\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\FeaturePolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\Util;

class PageController extends Controller {
    public function __construct(string $appName, IRequest $request) {
        parent::__construct($appName, $request);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(): TemplateResponse {
        Util::addScript('coordination', 'communes');
        Util::addScript('coordination', 'scanpdf');
        Util::addScript('coordination', 'docscan');
        Util::addScript('coordination', 'app');
        Util::addStyle('coordination', 'app');

        $response = new TemplateResponse('coordination', 'main', []);
        // Scanner en direct : la page doit pouvoir utiliser la caméra (Permissions-Policy).
        $policy = new FeaturePolicy();
        $policy->addAllowedCameraDomain("'self'");
        $response->setFeaturePolicy($policy);
        // Option B : OpenCV.js (js/vendor) compile son WASM et crée des fonctions (embind,
        // « new Function ») ; ces deux autorisations ne valent que pour cette page.
        // Méthodes testées avant appel : sans elles, le scanner retombe sur la détection rapide.
        $csp = new ContentSecurityPolicy();
        if (method_exists($csp, 'allowEvalScript')) $csp->allowEvalScript(true);
        if (method_exists($csp, 'allowEvalWasm')) $csp->allowEvalWasm(true);
        $response->setContentSecurityPolicy($csp);
        return $response;
    }
}
