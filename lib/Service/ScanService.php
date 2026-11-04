<?php
namespace OCA\Coordination\Service;

use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IDBConnection;

/**
 * Scans PDF des pièces. Les fichiers sont dans l'espace privé de l'application
 * (appdata_<instance>/coordination/scans-d<ID>/), jamais dans les Fichiers d'un
 * utilisateur : seuls les contrôleurs Coordination, après contrôle des droits,
 * peuvent les servir. Les métadonnées sont dans la table coord_scans.
 */
class ScanService {
    public const MAX_BYTES = 20 * 1024 * 1024;
    public const MAX_PER_PIECE = 10;

    public function __construct(private IDBConnection $db, private IAppDataFactory $appDataFactory) {}

    /** Contrôle minimal sans dépendance : en-tête %PDF-, marqueur de fin et taille. */
    public static function validatePdf(string $path, int $size): ?string {
        if ($size <= 0) return 'Fichier vide : recommencez le scan.';
        if ($size > self::MAX_BYTES) return 'Scan trop volumineux (20 Mo maximum). Réduisez le nombre de pages.';
        $h = @fopen($path, 'rb');
        if (!$h) return 'Fichier illisible : recommencez le scan.';
        $head = (string)fread($h, 1024);
        fseek($h, max(0, $size - 2048));
        $tail = (string)fread($h, 2048);
        fclose($h);
        if (!str_starts_with($head, '%PDF-')) return 'Le fichier transmis doit être un PDF.';
        if (!str_contains($tail, '%%EOF')) return 'PDF incomplet : recommencez le scan.';
        return null;
    }

    /** Estimation : 0 si les pages sont dans des flux d'objets compressés. */
    public static function countPages(string $content): int {
        return (int)preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $content);
    }

    public static function cleanName(string $name): string {
        $name = preg_replace('/[\x00-\x1F\x7F"\\\\\/]+/u', '_', basename($name)) ?? 'scan.pdf';
        $name = trim($name) === '' ? 'scan.pdf' : trim($name);
        return mb_substr($name, 0, 120);
    }

    private function folder(int $dossierId, bool $create): ?ISimpleFolder {
        $root = $this->appDataFactory->get('coordination');
        try {
            return $root->getFolder('scans-d' . $dossierId);
        } catch (NotFoundException $e) {
            return $create ? $root->newFolder('scans-d' . $dossierId) : null;
        }
    }

    /** Pièce de la checklist, si elle appartient bien au dossier. */
    public function piece(int $dossierId, int $itemId): ?array {
        $q = $this->db->getQueryBuilder();
        $q->select('id', 'document_code', 'document_label_fr')->from('coord_checklists')
          ->where($q->expr()->eq('id', $q->createNamedParameter($itemId)))
          ->andWhere($q->expr()->eq('dossier_id', $q->createNamedParameter($dossierId)));
        $r = $q->executeQuery(); $row = $r->fetch(); $r->closeCursor();
        return $row ?: null;
    }

    public function writeFile(int $dossierId, int $itemId, string $content): string {
        $name = 'p' . $itemId . '-' . bin2hex(random_bytes(8)) . '.pdf';
        $this->folder($dossierId, true)->newFile($name, $content);
        return $name;
    }

    public function removeFile(int $dossierId, string $name): void {
        $folder = $this->folder($dossierId, false);
        if ($folder && $folder->fileExists($name)) $folder->getFile($name)->delete();
    }

    public function removeFolder(int $dossierId): void {
        $folder = $this->folder($dossierId, false);
        if ($folder) $folder->delete();
    }

    public function content(int $dossierId, string $name): string {
        $folder = $this->folder($dossierId, false);
        if (!$folder) throw new NotFoundException('scan folder');
        return $folder->getFile($name)->getContent();
    }

    public function insertRow(int $dossierId, int $itemId, string $name, string $original, int $size, int $pages, string $uid): array {
        $now = time();
        $q = $this->db->getQueryBuilder();
        $q->insert('coord_scans')->values([
            'dossier_id' => $q->createNamedParameter($dossierId),
            'checklist_id' => $q->createNamedParameter($itemId),
            'file_name' => $q->createNamedParameter($name),
            'original_name' => $q->createNamedParameter(self::cleanName($original)),
            'size' => $q->createNamedParameter($size),
            'pages' => $q->createNamedParameter($pages),
            'uploaded_by' => $q->createNamedParameter($uid),
            'uploaded_at' => $q->createNamedParameter($now),
        ]);
        $q->executeStatement();
        $id = (int)$q->getLastInsertId();
        return self::format(['id' => $id, 'checklist_id' => $itemId, 'original_name' => self::cleanName($original), 'size' => $size, 'pages' => $pages, 'uploaded_by' => $uid, 'uploaded_at' => $now]);
    }

    public function countForPiece(int $itemId): int {
        $q = $this->db->getQueryBuilder();
        $q->select('id')->from('coord_scans')->where($q->expr()->eq('checklist_id', $q->createNamedParameter($itemId)));
        $r = $q->executeQuery(); $rows = $r->fetchAll(); $r->closeCursor();
        return count($rows);
    }

    /** Ligne interne (avec file_name) d'un scan du dossier. */
    public function find(int $dossierId, int $scanId): ?array {
        $q = $this->db->getQueryBuilder();
        $q->select('*')->from('coord_scans')
          ->where($q->expr()->eq('id', $q->createNamedParameter($scanId)))
          ->andWhere($q->expr()->eq('dossier_id', $q->createNamedParameter($dossierId)));
        $r = $q->executeQuery(); $row = $r->fetch(); $r->closeCursor();
        return $row ?: null;
    }

    public function deleteRow(int $scanId): void {
        $q = $this->db->getQueryBuilder();
        $q->delete('coord_scans')->where($q->expr()->eq('id', $q->createNamedParameter($scanId)))->executeStatement();
    }

    public function deleteRowsForDossier(int $dossierId): void {
        $q = $this->db->getQueryBuilder();
        $q->delete('coord_scans')->where($q->expr()->eq('dossier_id', $q->createNamedParameter($dossierId)))->executeStatement();
    }

    /** Ajoute scans[] et scan_count à chaque pièce de la checklist. */
    public function attach(int $dossierId, array $checklist): array {
        $q = $this->db->getQueryBuilder();
        $q->select('*')->from('coord_scans')->where($q->expr()->eq('dossier_id', $q->createNamedParameter($dossierId)))->orderBy('id', 'ASC');
        $r = $q->executeQuery(); $rows = $r->fetchAll(); $r->closeCursor();
        $by = [];
        foreach ($rows as $row) $by[(int)$row['checklist_id']][] = self::format($row);
        foreach ($checklist as &$item) {
            $item['scans'] = $by[(int)$item['id']] ?? [];
            $item['scan_count'] = count($item['scans']);
        }
        return $checklist;
    }

    public static function format(array $row): array {
        return [
            'id' => (int)$row['id'],
            'checklist_id' => (int)$row['checklist_id'],
            'original_name' => (string)($row['original_name'] ?? ''),
            'size' => (int)$row['size'],
            'pages' => (int)$row['pages'],
            'uploaded_by' => (string)$row['uploaded_by'],
            'uploaded_at' => (int)$row['uploaded_at'],
        ];
    }
}
