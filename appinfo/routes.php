<?php

return [
    'routes' => [
        // Page principale
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

        // API Dossiers (Supporte à la fois les routes plates et avec paramètres)
        ['name' => 'dossier_api#index', 'url' => '/api/dossiers', 'verb' => 'GET'],
        ['name' => 'dossier_api#getDetail', 'url' => '/api/dossier-detail', 'verb' => 'GET'],
        ['name' => 'dossier_api#show', 'url' => '/api/dossiers/{id}', 'verb' => 'GET'],
        ['name' => 'dossier_api#create', 'url' => '/api/dossiers', 'verb' => 'POST'],
        ['name' => 'dossier_api#update', 'url' => '/api/dossiers/{id}', 'verb' => 'PUT'],
        ['name' => 'dossier_api#updatePost', 'url' => '/api/dossiers/{id}/update', 'verb' => 'POST'],
        ['name' => 'dossier_api#saveCoordinatorFields', 'url' => '/api/dossiers/{id}/coordinator-fields', 'verb' => 'POST'],
        ['name' => 'dossier_api#destroy', 'url' => '/api/dossiers/{id}', 'verb' => 'DELETE'],
        ['name' => 'dossier_api#destroyPost', 'url' => '/api/dossiers/{id}/delete', 'verb' => 'POST'],
        
        ['name' => 'dossier_api#assign', 'url' => '/api/dossiers/{id}/assign', 'verb' => 'POST'],
        ['name' => 'dossier_api#batchAssign', 'url' => '/api/dossiers/batch-assign', 'verb' => 'POST'],
        ['name' => 'dossier_api#setAppointment', 'url' => '/api/dossiers/{id}/appointment', 'verb' => 'POST'],
        ['name' => 'dossier_api#updateChecklist', 'url' => '/api/dossiers/{id}/checklist', 'verb' => 'POST'],
        ['name' => 'dossier_api#changeStatus', 'url' => '/api/dossiers/{id}/status', 'verb' => 'POST'],

        // API Scans PDF des pièces
        ['name' => 'scan_api#upload', 'url' => '/api/dossiers/{id}/checklist/{itemId}/scans', 'verb' => 'POST'],
        ['name' => 'scan_api#file', 'url' => '/api/dossiers/{id}/scans/{scanId}/file', 'verb' => 'GET'],
        ['name' => 'scan_api#destroy', 'url' => '/api/dossiers/{id}/scans/{scanId}', 'verb' => 'DELETE'],
        ['name' => 'scan_api#destroyPost', 'url' => '/api/dossiers/{id}/scans/{scanId}/delete', 'verb' => 'POST'],

        // API Import XLSX
        ['name' => 'import_api#uploadXlsx', 'url' => '/api/import/xlsx', 'verb' => 'POST'],

        // API Configuration & Utilisateurs
        ['name' => 'config_api#getConfig', 'url' => '/api/config', 'verb' => 'GET'],
        ['name' => 'config_api#saveCustomFields', 'url' => '/api/config/custom-fields', 'verb' => 'POST'],
        ['name' => 'config_api#saveDocumentTemplates', 'url' => '/api/config/document-templates', 'verb' => 'POST'],
        ['name' => 'config_api#saveBanks', 'url' => '/api/config/banks', 'verb' => 'POST'],
        ['name' => 'config_api#getUsersAndGroups', 'url' => '/api/users-groups', 'verb' => 'GET'],
    ]
];
