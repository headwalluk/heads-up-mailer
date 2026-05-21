<?php
/**
 * Subscriber-bearer tokens.
 *
 * @package Heads_Up_Mailer
 * @since 0.4.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

/**
 * Builds and verifies `{subscriber_id}.{hmac_hex}` tokens.
 *
 * The HMAC key is the subscriber's stored `token_salt` (64-char
 * hex string, set at insert via `random_bytes(32)`). Rotating that
 * salt invalidates every outstanding token for the subscriber.
 *
 * Consumers:
 *   - M5 worker emits tokens into `List-Unsubscribe` headers and
 *     the footer's `{{unsubscribe_url}}` placeholder.
 *   - M6 `/manage-comms/` handler verifies tokens on the inbound
 *     side before mutating preferences.
 *   - M7 mailbox poller pulls the token out of the subject line
 *     `^unsubscribe-([A-Za-z0-9._-]+)$` and verifies it the same
 *     way.
 *
 * @since 0.4.0
 */
class Tokens {

	/**
	 * Build a token for a subscriber.
	 *
	 * Returns an empty string on any failure (subscriber missing or
	 * stored salt empty). Empty-string lets the caller log + skip a
	 * recipient without exception handling — the row's `last_error`
	 * column carries the diagnosis.
	 *
	 * @since 0.4.0
	 * @param int $subscriber_id Subscriber ID.
	 * @return string Token or empty string on failure.
	 */
	public function generate( int $subscriber_id ): string {
		$result = '';

		if ( $subscriber_id > 0 ) {
			$controller = new Subscribers_Controller();
			$subscriber = $controller->get( $subscriber_id );

			if ( null !== $subscriber && '' !== (string) $subscriber->token_salt ) {
				$hmac   = hash_hmac( 'sha256', (string) $subscriber_id, (string) $subscriber->token_salt );
				$result = $subscriber_id . '.' . $hmac;
			}
		}

		return $result;
	}

	/**
	 * Verify a token and return the subscriber ID it carries.
	 *
	 * Constant-time comparison (`hash_equals`) so a malformed or
	 * tampered MAC doesn't leak the salt via timing. All failure
	 * modes — bad format, unknown subscriber, missing salt, MAC
	 * mismatch — collapse to `null` so the caller can't distinguish
	 * between them.
	 *
	 * @since 0.4.0
	 * @param string $token Raw token string from the URL or subject.
	 * @return ?int Subscriber ID on match, null on any failure.
	 */
	public function verify( string $token ): ?int {
		$result = null;

		if ( '' !== $token ) {
			$parts = explode( '.', $token, 2 );

			if ( 2 === count( $parts ) && ctype_digit( $parts[0] ) && '' !== $parts[1] ) {
				$subscriber_id = (int) $parts[0];
				$provided_hmac = $parts[1];
				$controller    = new Subscribers_Controller();
				$subscriber    = $subscriber_id > 0 ? $controller->get( $subscriber_id ) : null;

				if ( null !== $subscriber && '' !== (string) $subscriber->token_salt ) {
					$expected_hmac = hash_hmac( 'sha256', (string) $subscriber_id, (string) $subscriber->token_salt );

					if ( hash_equals( $expected_hmac, $provided_hmac ) ) {
						$result = $subscriber_id;
					}
				}
			}
		}

		return $result;
	}
}
