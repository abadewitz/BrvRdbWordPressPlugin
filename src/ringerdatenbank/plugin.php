<?php
/**
 * Plugin Name: Ringerdatenbank (RDB)
 * Description: Bindet Inhalte einer Subdomain/Standalone-RDB-Instanz via Shortcode in WordPress ein und leitet Parameter an die WBW-Core-Routine weiter.
 * Version: 0.0.1
 * Author: Badewitz, Alexander
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Plugin
{
    /**
     * Basisverzeichnis der Ziel-Subdomain. Per Konstante überschreibbar:
     * define('RDB_STACH_BASE', '/ABSOLUTER/PFAD/zu/rdb.brv-ringen.de');
     */
    private string $baseDir;

    public function __construct()
    {
        // Standard: eine Ebene über ABSPATH liegt der Schwester-Docroot "rdb.brv-ringen.de"
        $defaultBase = realpath(dirname(rtrim(ABSPATH, DIRECTORY_SEPARATOR)) . DIRECTORY_SEPARATOR . 'rdb.ringen-bayern.de') ?: '';
        $this->baseDir = rtrim(defined('RDB_STACH_BASE') ? (string) RDB_STACH_BASE : $defaultBase, DIRECTORY_SEPARATOR);

        add_shortcode('rdb', [$this, 'shortcode']);
    }

    /**
     * Shortcode-Nutzung:
     *   [rdb]                           -> {BASE}/index.php
     *   [rdb path="pfad/unterhalb"]     -> {BASE}/{path}
     *   [rdb fullpath="/abs/zu/datei"]  -> exakte Datei, muss innerhalb {BASE} liegen
     */
    public function shortcode($atts = []): string
    {
        $atts = shortcode_atts([
            'path'     => 'index.php', // relativ zu $baseDir
            'fullpath' => '',          // absoluter Pfad, wird aus Sicherheitsgründen auf $baseDir begrenzt
        ], $atts, 'rdb');

        // Basispfad prüfen
        if ($this->baseDir === '' || !is_dir($this->baseDir) || !is_readable($this->baseDir)) {
            return '<div class="rdb-stach-error">[rdb] Basisverzeichnis nicht lesbar/gefunden.</div>';
        }

        // Ziel-Datei bestimmen
        $target = '';
        if (is_string($atts['fullpath']) && $atts['fullpath'] !== '') {
            $target = (string) $atts['fullpath'];
        } else {
            $rel = ltrim((string) $atts['path'], DIRECTORY_SEPARATOR);
            $target = $this->baseDir . DIRECTORY_SEPARATOR . $rel;
        }

        $resolved = realpath($target);
        if ($resolved === false) {
            return '<div class="rdb-stach-error">[rdb] Zieldatei nicht gefunden.</div>';
        }

        // Path Traversal verhindern: Datei muss innerhalb baseDir liegen
        $baseReal = realpath($this->baseDir);
        if ($baseReal === false || strpos($resolved, $baseReal) !== 0) {
            return '<div class="rdb-stach-error">[rdb] Zugriff außerhalb des erlaubten Verzeichnisses verweigert.</div>';
        }

        if (!is_file($resolved) || !is_readable($resolved)) {
            return '<div class="rdb-stach-error">[rdb] Zieldatei ist nicht lesbar.</div>';
        }

        return $this->include_isolated($resolved);
    }

    /**
     * Führt die Datei in isoliertem Kontext aus, wechselt ins Verzeichnis der Datei,
     * fängt die Ausgabe ab und stellt das vorherige Arbeitsverzeichnis wieder her.
     */
    private function include_isolated(string $file): string
    {
        $oldCwd = getcwd();
        $dir = dirname($file);

        // Arbeitsverzeichnis wechseln, damit relative Includes/Assets der Zielapp funktionieren
        if ($dir && is_dir($dir)) {
            @chdir($dir);
        }

        // Isolierter Scope via Closure
        $runner = static function (string $__file__) {
            ob_start();
            // Minimale Server-Variablen anpassen (optional, falls benötigt)
            if (!isset($_SERVER['DOCUMENT_ROOT'])) {
                $_SERVER['DOCUMENT_ROOT'] = getcwd();
            }
            // Include der Zielanwendung
            include $__file__;
            return ob_get_clean();
        };

        try {
            $output = $runner($file);
        } catch (\Throwable $e) {
            $output = '<div class="rdb-stach-error">[rdb] Fehler bei der Ausführung: '
                . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</div>';
        } finally {
            if ($oldCwd !== false && is_dir($oldCwd)) {
                @chdir($oldCwd);
            }
        }

        // In Container kapseln, um Styles zu isolieren (kein Shadow DOM, aber minimiert Konflikte)
        return '<div class="rdb-stach-inline">'.$output.'</div>';
    }
}

new Plugin();
