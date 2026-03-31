<?php
/**
 * Contract that every translation driver must satisfy.
 *
 * Keeping the interface small (two methods) lets us swap drivers without
 * touching any consumer code. Drivers are free to implement additional
 * internal helpers, but only these two methods are visible to the rest of
 * the plugin.
 *
 * @package OpenToungeTranslations\Connection\Contracts
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Connection\Contracts;

/**
 * Interface TranslationClientInterface
 *
 * Abstraction over any backend that can translate a UTF-8 string from one
 * language to another. All implementations must be side-effect free with
 * respect to the WordPress request: they may log, but must never die(),
 * redirect, or alter global state.
 */
interface TranslationClientInterface {

	/**
	 * Translate a UTF-8 string.
	 *
	 * On any error the original $text MUST be returned unchanged so that
	 * content is never silently lost or corrupted.
	 *
	 * @param string $text       The UTF-8 source text to translate.
	 * @param string $sourceLang BCP-47 source language code (e.g. "en") or "auto" for auto-detection.
	 * @param string $targetLang BCP-47 target language code (e.g. "fr").
	 *
	 * @return string The translated string, or the original $text on failure.
	 */
	public function translate( string $text, string $sourceLang, string $targetLang ): string;

	/**
	 * Perform a lightweight connectivity check against the translation backend.
	 *
	 * Suitable for admin health-check notices. Must NOT be called on every
	 * page load — only on demand.
	 *
	 * @return bool True when the backend is reachable and responding correctly.
	 */
	public function healthCheck(): bool;

	/**
	 * Fetch the list of language pairs supported by the translation backend.
	 *
	 * Returns an empty array on any network or parse failure — callers must
	 * treat an empty return as a transient error, not a permanent state.
	 *
	 * @return array<int, array{code: string, name: string}> Indexed list of
	 *         language descriptors, each containing at minimum 'code' (BCP-47)
	 *         and 'name' (human-readable label).
	 */
	public function listLanguages(): array;
}
