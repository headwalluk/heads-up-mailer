<?php
/**
 * Symmetric encryption for stored credentials.
 *
 * Uses libsodium's `crypto_secretbox` (XSalsa20 + Poly1305) with a
 * 32-byte key derived from WordPress's `AUTH_KEY` constant via
 * `hash_hkdf`. The plugin-specific `info` string in the HKDF
 * binding makes the derived key tied to this plugin so it can't
 * collide with keys derived elsewhere on the same site.
 *
 * Storage format is `base64( nonce || ciphertext )` — the 24-byte
 * nonce is prepended, then the ciphertext (which includes the
 * authentication tag). Self-describing, no separate IV column
 * required.
 *
 * @package Heads_Up_Mailer
 * @since 0.2.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

/**
 * Encrypt / decrypt strings using libsodium with an AUTH_KEY-derived key.
 *
 * @since 0.2.0
 */
class Crypto {

	/**
	 * HKDF `info` parameter — versioned so the stored ciphertext shape can
	 * change in future without colliding with old values.
	 *
	 * @since 0.2.0
	 */
	private const KEY_INFO = 'hum:credentials:v1';

	/**
	 * Encrypt a plaintext string.
	 *
	 * Returns an empty string for empty input so callers can blindly
	 * pass through whatever the form supplied.
	 *
	 * @since 0.2.0
	 * @param string $plaintext Value to encrypt.
	 * @return string Base64-encoded `nonce || ciphertext` envelope.
	 */
	public static function encrypt( string $plaintext ): string {
		$result = '';

		if ( '' !== $plaintext ) {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plaintext, $nonce, self::derive_key() );
			$result = base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding for storage, not obfuscation.
		}

		return $result;
	}

	/**
	 * Decrypt a stored envelope.
	 *
	 * Returns an empty string for empty input or any kind of failure
	 * (malformed base64, truncated payload, wrong key, tampered
	 * ciphertext). Callers should treat "" as "no usable value" and
	 * surface a clear UI message rather than dereferencing it.
	 *
	 * @since 0.2.0
	 * @param string $encoded Base64-encoded envelope from `encrypt()`.
	 * @return string Decrypted plaintext, or "" on any failure.
	 */
	public static function decrypt( string $encoded ): string {
		$result = '';

		if ( '' !== $encoded ) {
			$bin = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding storage envelope.

			if ( false !== $bin && strlen( $bin ) > SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				$nonce     = substr( $bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
				$cipher    = substr( $bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
				$plaintext = sodium_crypto_secretbox_open( $cipher, $nonce, self::derive_key() );

				if ( false !== $plaintext ) {
					$result = $plaintext;
				}
			}
		}

		return $result;
	}

	/**
	 * Derive a 32-byte key from `AUTH_KEY` (or the site URL as
	 * fallback) via HKDF-SHA256 with a plugin-specific `info` binding.
	 *
	 * @since 0.2.0
	 * @return string Raw 32-byte key suitable for `sodium_crypto_secretbox`.
	 */
	private static function derive_key(): string {
		$material = defined( 'AUTH_KEY' ) && '' !== (string) AUTH_KEY
			? (string) AUTH_KEY
			: (string) get_option( 'siteurl' );

		return hash_hkdf(
			'sha256',
			$material,
			SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
			self::KEY_INFO
		);
	}
}
