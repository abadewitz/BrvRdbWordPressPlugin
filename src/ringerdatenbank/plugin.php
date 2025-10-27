<?php
/**
 * Plugin Name:       Ringerdatenbank (RDB)
 * Description:       Bindet Inhalte einer Subdomain/Standalone-RDB-Instanz via Shortcode in WordPress ein und leitet Parameter weiter.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Alexander Badewitz, Oliver Stach
 * Text Domain:       rdb
 * Domain Path:       /languages
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'RDB_Plugin' ) ) :

final class RDB_Plugin {

	/**
	 * Basisverzeichnis der Ziel-Subdomain. Kann via Konstante RDB_STACH_BASE oder Filter 'rdb/base_dir' überschrieben werden.
	 * @var string
	 */
	private string $base_dir;

	/**
	 * Erlaubte Dateiendungen (nur PHP-Dateien ausführen).
	 * @var string[]
	 */
	private array $allowed_extensions = array( 'php' );

	public static function instance(): self {
		static $inst = null;
		if ( null === $inst ) {
			$inst = new self();
		}
		return $inst;
	}

	private function __construct() {
		// Standard: eine Ebene über ABSPATH liegt die Schwester-Docroot "rdb.ringen-bayern.de".
		$default_base = realpath( dirname( rtrim( ABSPATH, DIRECTORY_SEPARATOR ) ) . DIRECTORY_SEPARATOR . 'rdb.ringen-bayern.de' ) ?: '';
		$base         = defined( 'RDB_STACH_BASE' ) ? (string) RDB_STACH_BASE : $default_base;

		/**
		 * Filter: Basisverzeichnis anpassen.
		 *
		 * @param string $base
		 */
		$base = (string) apply_filters( 'rdb/base_dir', $base );

		$this->base_dir = rtrim( $base, DIRECTORY_SEPARATOR );

		add_action( 'init', array( $this, 'register_shortcode' ) );
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'rdb', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	public function register_shortcode(): void {
		add_shortcode( 'rdb', array( $this, 'shortcode' ) );
	}

	/**
	 * Shortcode:
	 *   [rdb]                         -> {BASE}/index.php
	 *   [rdb path="pfad/unterhalb"]   -> {BASE}/{path}
	 *   [rdb fullpath="/abs/zu.php"]  -> exakte Datei (muss innerhalb {BASE} liegen)
	 *
	 * @param array<string,mixed> $atts
	 * @return string
	 */
	public function shortcode( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'path'     => 'index.php', // relativ zu $base_dir
				'fullpath' => '',          // absoluter Pfad (wird auf $base_dir begrenzt)
			),
			$atts,
			'rdb'
		);

		// Basispfad prüfen.
		if ( '' === $this->base_dir || ! is_dir( $this->base_dir ) || ! is_readable( $this->base_dir ) ) {
			return $this->error_box( __( '[rdb] Basisverzeichnis nicht lesbar/gefunden.', 'rdb' ) );
		}

		// Ziel-Datei bestimmen.
		$target = '';
		if ( is_string( $atts['fullpath'] ) && '' !== $atts['fullpath'] ) {
			$target = (string) $atts['fullpath'];
		} else {
			$rel = ltrim( (string) $atts['path'], DIRECTORY_SEPARATOR );
			// Normiere relative Pfade (kein URL-Encoding etc.).
			$rel    = wp_normalize_path( $rel );
			$target = $this->base_dir . DIRECTORY_SEPARATOR . $rel;
		}

		// Nur Datei-Endungen erlauben, die explizit freigegeben sind.
		$ext = strtolower( pathinfo( $target, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, $this->allowed_extensions, true ) ) {
			/**
			 * Filter: Zusätzliche erlaubte Endungen ergänzen (Risikobewertung beachten!).
			 *
			 * @param string[] $allowed
			 */
			$allowed = (array) apply_filters( 'rdb/allowed_extensions', $this->allowed_extensions );
			if ( ! in_array( $ext, array_map( 'strtolower', $allowed ), true ) ) {
				return $this->error_box( __( '[rdb] Dateityp nicht erlaubt.', 'rdb' ) );
			}
		}

		// Auflösen & Validieren.
		$resolved = realpath( $target );
		if ( false === $resolved ) {
			return $this->error_box( __( '[rdb] Zieldatei nicht gefunden.', 'rdb' ) );
		}

		$base_real = realpath( $this->base_dir );
		if ( false === $base_real ) {
			return $this->error_box( __( '[rdb] Ungültige Basis-Konfiguration.', 'rdb' ) );
		}

		// Path Traversal verhindern: Datei muss innerhalb base_dir liegen.
		$resolved_norm  = wp_normalize_path( $resolved );
		$base_real_norm = rtrim( wp_normalize_path( $base_real ), '/\\' ) . '/';
		if ( 0 !== strpos( $resolved_norm, $base_real_norm ) ) {
			return $this->error_box( __( '[rdb] Zugriff außerhalb des erlaubten Verzeichnisses verweigert.', 'rdb' ) );
		}

		if ( ! is_file( $resolved ) || ! is_readable( $resolved ) ) {
			return $this->error_box( __( '[rdb] Zieldatei ist nicht lesbar.', 'rdb' ) );
		}

		/**
		 * Filter: finale Freigabe des anvisierten Targets (kann zur zusätzlichen Policy-Prüfung genutzt werden).
		 *
		 * @param string $resolved
		 * @return bool
		 */
		$allowed_target = (bool) apply_filters( 'rdb/allow_target', true, $resolved );
		if ( ! $allowed_target ) {
			return $this->error_box( __( '[rdb] Zugriff auf Zieldatei untersagt.', 'rdb' ) );
		}

		// Datei ausführen (isolierter Scope).
		return $this->include_isolated( $resolved );
	}

	/**
	 * Führt die Datei in isoliertem Kontext aus, wechselt temporär in ihr Verzeichnis,
	 * fängt die Ausgabe ab und stellt das vorherige Arbeitsverzeichnis wieder her.
	 */
	private function include_isolated( string $file ): string {
		$old_cwd = getcwd();
		$dir     = dirname( $file );

		// Arbeitsverzeichnis wechseln, damit relative Includes/Assets der Zielapp funktionieren.
		if ( $dir && is_dir( $dir ) ) {
			// Mutationen des CWD so kurz wie möglich halten.
			@chdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$runner = static function ( string $__file__ ): string {
			ob_start();

			// Minimale Server-Variablen anpassen (falls benötigt).
			if ( ! isset( $_SERVER['DOCUMENT_ROOT'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$_SERVER['DOCUMENT_ROOT'] = getcwd(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			}

			// Zielanwendung einbinden (kein require_once, um wiederholte Aufrufe zu ermöglichen).
			include $__file__;

			return (string) ob_get_clean();
		};

		try {
			$output = $runner( $file );
		} catch ( \Throwable $e ) {
			$msg    = esc_html( $e->getMessage() );
			$output = '<div class="rdb-stach-error">[rdb] ' . sprintf(
				/* translators: %s: Fehlermeldung */
				esc_html__( 'Fehler bei der Ausführung: %s', 'rdb' ),
				$msg
			) . '</div>';
		} finally {
			if ( false !== $old_cwd && is_dir( (string) $old_cwd ) ) {
				@chdir( (string) $old_cwd ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		// In Container kapseln, um Styles minimal zu isolieren (kein Shadow DOM, aber Konflikte reduzieren).
		return '<div class="rdb-stach-inline">' . $this->sanitize_embed_output( $output ) . '</div>';
	}

	private function error_box( string $message ): string {
		$message = wp_kses_post( $message );
		return '<div class="rdb-stach-error">' . $message . '</div>';
	}

	/**
	 * Erlaubt nur sicheres HTML aus der eingebundenen App. Kann via Filter erweitert werden.
	 */
	private function sanitize_embed_output( string $html ): string {
		$allowed = array(
			'a'          => array(
				'href'   => true,
				'title'  => true,
				'target' => true,
				'rel'    => true,
				'class'  => true,
				'id'     => true,
			),
			'abbr'       => array( 'title' => true ),
			'acronym'    => array( 'title' => true ),
			'b'          => array(),
			'blockquote' => array( 'cite' => true ),
			'br'         => array(),
			'code'       => array( 'class' => true ),
			'pre'        => array( 'class' => true ),
			'del'        => array( 'datetime' => true ),
			'div'        => array( 'class' => true, 'id' => true, 'style' => true, 'data-*' => true ),
			'em'         => array(),
			'h1'         => array( 'class' => true, 'id' => true ),
			'h2'         => array( 'class' => true, 'id' => true ),
			'h3'         => array( 'class' => true, 'id' => true ),
			'h4'         => array( 'class' => true, 'id' => true ),
			'h5'         => array( 'class' => true, 'id' => true ),
			'h6'         => array( 'class' => true, 'id' => true ),
			'hr'         => array(),
			'i'          => array(),
			'img'        => array(
				'src'    => true,
				'srcset' => true,
				'sizes'  => true,
				'alt'    => true,
				'class'  => true,
				'id'     => true,
				'width'  => true,
				'height' => true,
				'loading'=> true,
				'decoding'=> true,
			),
			'li'         => array( 'class' => true ),
			'ol'         => array( 'class' => true ),
			'p'          => array( 'class' => true ),
			'span'       => array( 'class' => true, 'id' => true, 'style' => true, 'data-*' => true ),
			'strong'     => array(),
			'table'      => array( 'class' => true ),
			'tbody'      => array(),
			'td'         => array( 'class' => true, 'colspan' => true, 'rowspan' => true ),
			'th'         => array( 'class' => true, 'colspan' => true, 'rowspan' => true, 'scope' => true ),
			'thead'      => array(),
			'tr'         => array( 'class' => true ),
			'ul'         => array( 'class' => true ),
			'iframe'     => array(
				'src'             => true,
				'width'           => true,
				'height'          => true,
				'allow'           => true,
				'allowfullscreen' => true,
				'loading'         => true,
				'referrerpolicy'  => true,
				'title'           => true,
			),
			// Scripts/Styles bewusst nicht zugelassen. Falls erforderlich, via Filter freigeben.
		);

		/**
		 * Filter: erlaubte HTML-Tags/Attribute für die eingebettete Ausgabe.
		 *
		 * @param array  $allowed
		 * @param string $raw_html
		 */
		$allowed = (array) apply_filters( 'rdb/allowed_html', $allowed, $html );

		return wp_kses( $html, $allowed );
	}
}

endif;

RDB_Plugin::instance();
