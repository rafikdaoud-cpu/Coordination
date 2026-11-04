<?php

namespace OCA\Coordination\Controller;

use OCP\AppFramework\Controller;
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
        Util::addScript('coordination', 'app');
        Util::addStyle('coordination', 'app');

        return new TemplateResponse('coordination', 'main', []);
    }
}
