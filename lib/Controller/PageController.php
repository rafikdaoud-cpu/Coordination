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
        // OpenCV.js tourne dans un Web Worker (js/docscan-worker.js) : la page n'a besoin ni
        // d'eval ni de WASM, seulement d'autoriser ce worker venant du même serveur.
        $csp = new ContentSecurityPolicy();
        if (method_exists($csp, 'addAllowedWorkerSrcDomain')) $csp->addAllowedWorkerSrcDomain("'self'");
        $response->setContentSecurityPolicy($csp);
        return $response;
    }
}
