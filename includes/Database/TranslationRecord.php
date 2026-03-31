<?php
/**
 * Immutable value object representing a single row in the translations cache table.
 *
 * All properties are readonly so the object can never be mutated after construction.
 * Create a fresh instance via the named constructor or the public constructor directly.
 *
 * @package OpenToungeTranslations\Database
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Database;

/**
 * Class TranslationRecord
 *
 * Carries a complete translation row between the repository, cache drivers,
 * and the interception layer. No setters — callers that need a modified copy
 * must construct a new instance.
 */
final class TranslationRecord {

	/**
	 * @param string $hash           MD5(source_text + '|' + context) — the natural lookup key.
	 * @param string $sourceLang     BCP-47 source language code or 'auto'.
	 * @param string $targetLang     BCP-47 target language code.
	 * @param string $sourceText     The original untranslated string.
	 * @param string $translatedText The machine- or human-translated string.
	 * @param string $context        Gettext domain or 'output_buffer'. Defaults to ''.
	 * @param bool   $isManual       True when the translation was entered by a human editor
	 *                               and must not be overwritten by the API.
	 */
	public function __construct(
		public readonly string $hash,
		public readonly string $sourceLang,
		public readonly string $targetLang,
		public readonly string $sourceText,
		public readonly string $translatedText,
		public readonly string $context,
		public readonly bool $isManual,
	) {}

	/**
	 * Compute the canonical hash for a source text + context pair.
	 *
	 * This is the single authoritative hash function used across the entire
	 * plugin. Every class that needs a lookup key must call this method rather
	 * than computing its own MD5.
	 *
	 * @param string $sourceText The original string to hash.
	 * @param string $context    Gettext domain or 'output_buffer'. Defaults to ''.
	 *
	 * @return string 32-character lowercase hex MD5 digest.
	 */
	public static function computeHash( string $sourceText, string $context = '' ): string {
		return md5( $sourceText . '|' . $context );
	}

	/**
	 * Hydrate a TranslationRecord from a raw associative DB row.
	 *
	 * Keeps row-to-object mapping in one place so changes to column names
	 * only need to be updated here.
	 *
	 * @param array<string, mixed> $row Associative row from $wpdb->get_row() / get_results().
	 *
	 * @return self
	 */
	public static function fromRow( array $row ): self {
		return new self(
			hash: (string) $row['string_hash'],
			sourceLang: (string) $row['source_lang'],
			targetLang: (string) $row['target_lang'],
			sourceText: (string) $row['source_text'],
			translatedText: (string) $row['translated_text'],
			context: (string) $row['context'],
			isManual: (bool) $row['is_manual'],
		);
	}
}
