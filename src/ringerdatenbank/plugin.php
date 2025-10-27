<?php
/**
 * Plugin Name:       Ringerdatenbank (RDB)
 * Description:       Bindet Inhalte einer Subdomain/Standalone-RDB-Instanz via Shortcode ein und verlinkt CSS/JS cross-site auf rdb.ringen-bayern.de.
 * Version:           0.2.1
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Alexander Badewitz, Oliver Stach
 * Text Domain:       rdb
 */

if (!defined('ABSPATH')) exit;

final class RDB_Plugin {

	private string $base_dir;
	private string $base_url;

	private array $enqueued_styles = [];
	private array $scripts_to_enqueue = [];
	private array $inline_scripts = [];
	private array $inline_styles = [];
	private array $script_attrs = [];

	public static function instance(): self {
		static $inst = null;
		if ($inst === null) $inst = new self();
		return $inst;
	}

	private function __construct() {
		$default_base_dir = realpath(dirname(rtrim(ABSPATH, DIRECTORY_SEPARATOR)) . DIRECTORY_SEPARATOR . 'rdb.ringen-bayern.de') ?: '';
		$this->base_dir   = rtrim(defined('RDB_STACH_BASE') ? (string) RDB_STACH_BASE : $default_base_dir, DIRECTORY_SEPARATOR);

		// Hier ist die entscheidende Änderung:
		$this->base_url = rtrim(
			defined('RDB_BASE_URL')
				? (string) RDB_BASE_URL
				: (string) apply_filters('rdb/base_url', 'https://rdb.ringen-bayern.de'),
			'/'
		);

		add_shortcode('rdb', [$this, 'shortcode']);
		add_action('wp_enqueue_scripts', [$this, 'print_collected_assets']);
		add_filter('script_loader_tag', [$this, 'add_async_defer_attributes'], 10, 3);
	}

	public function shortcode($atts = []): string {
		$atts = shortcode_atts([
			'path' => 'index.php',
			'fullpath' => '',
		], $atts, 'rdb');

		if ($this->base_dir === '' || !is_dir($this->base_dir)) {
			return $this->err('[rdb] Basisverzeichnis nicht gefunden.');
		}

		$target = $atts['fullpath'] ?: ($this->base_dir . DIRECTORY_SEPARATOR . ltrim($atts['path'], DIRECTORY_SEPARATOR));
		$resolved = realpath($target);
		if (!$resolved || !is_file($resolved)) {
			return $this->err('[rdb] Datei nicht gefunden.');
		}

		$html = $this->include_isolated($resolved);
		$parsed = $this->parse_and_collect_assets($html, $resolved);
		return '<div class="rdb-stach-inline">' . $parsed['body_html'] . '</div>';
	}

	private function include_isolated(string $file): string {
		$old = getcwd();
		chdir(dirname($file));
		ob_start();
		include $file;
		$out = ob_get_clean();
		chdir($old);
		return $out;
	}

	private function parse_and_collect_assets(string $html, string $executed_file): array {
		$dom = new \DOMDocument('1.0', 'UTF-8');
		libxml_use_internal_errors(true);
		$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
		libxml_clear_errors();

		$base_href = $this->base_url . '/';

		// CSS
		foreach ($dom->getElementsByTagName('link') as $link) {
			if (strtolower($link->getAttribute('rel')) === 'stylesheet') {
				$href = $this->resolve_url($link->getAttribute('href'), $base_href);
				$this->enqueue_style_url($href);
			}
		}

		// JS
		foreach ($dom->getElementsByTagName('script') as $script) {
			$src = $script->getAttribute('src');
			if ($src) {
				$src = $this->resolve_url($src, $base_href);
				$this->enqueue_script_url($src, $script->hasAttribute('async'), $script->hasAttribute('defer'), null, true);
			} elseif (trim($script->textContent)) {
				$handle = 'rdb-inline-js';
				$this->inline_scripts[$handle][] = $script->textContent;
			}
		}

		$body = $dom->getElementsByTagName('body')->item(0);
		$body_html = '';
		if ($body) {
			foreach ($body->childNodes as $child) {
				$body_html .= $dom->saveHTML($child);
			}
		}

		return ['body_html' => $body_html];
	}

	private function resolve_url(string $url, string $base): string {
		$url = trim($url);
		if ($url === '') return '';
		if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $url)) return $url; // absolute
		if (str_starts_with($url, '//')) return (is_ssl() ? 'https:' : 'http:') . $url;
		// Relativ oder Root: immer auf base_url mappen
		return rtrim($this->base_url, '/') . '/' . ltrim($url, '/');
	}

	private function enqueue_style_url(string $href): void {
		$handle = 'rdb-style-' . substr(md5($href), 0, 10);
		if (!isset($this->enqueued_styles[$handle])) {
			wp_enqueue_style($handle, $href, [], null);
			$this->enqueued_styles[$handle] = true;
		}
	}

	private function enqueue_script_url(string $src, bool $async, bool $defer, ?string $ver, bool $in_footer): string {
		$handle = 'rdb-script-' . substr(md5($src), 0, 10);
		wp_register_script($handle, $src, [], $ver, $in_footer);
		wp_enqueue_script($handle);
		$this->script_attrs[$handle] = ['async' => $async, 'defer' => $defer];
		return $handle;
	}

	public function add_async_defer_attributes(string $tag, string $handle, string $src): string {
		if (!isset($this->script_attrs[$handle])) return $tag;
		$a = $this->script_attrs[$handle];
		if ($a['async']) $tag = str_replace('<script ', '<script async ', $tag);
		if ($a['defer']) $tag = str_replace('<script ', '<script defer ', $tag);
		return $tag;
	}

	public function print_collected_assets(): void {
		foreach ($this->inline_scripts as $handle => $blocks) {
			foreach ($blocks as $js) {
				wp_add_inline_script($handle, $js);
			}
		}
	}

	private function err(string $msg): string {
		return '<div class="rdb-stach-error">' . esc_html($msg) . '</div>';
	}
}

RDB_Plugin::instance();
