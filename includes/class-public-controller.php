<?php
/**
 * Public `/manage-comms/` endpoint.
 *
 * @package Heads_Up_Mailer
 * @since 0.5.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

/**
 * Owns the public preference / unsubscribe surface.
 *
 * Registers a rewrite rule for the configurable manage-comms slug
 * (stored in `OPTION_MANAGE_SLUG`), parses the bearer token via the
 * shared `Tokens` helper, applies a per-token rate limit, and
 * dispatches:
 *
 *   - GET  → preference page (this chunk)
 *   - POST `action=unsubscribe` → one-click unsubscribe (chunk B)
 *   - POST form         → save group memberships (chunk B)
 *
 * Permalinks must be enabled — the rewrite rule requires pretty
 * URLs. With plain permalinks, hit `/?hum_manage=1&token=…`
 * directly.
 *
 * @since 0.5.0
 */
class Public_Controller {

	/**
	 * Register hooks.
	 *
	 * @since 0.5.0
	 */
	public function run(): void {
		add_action( 'init', array( $this, 'register_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'dispatch' ) );
	}

	/**
	 * Register the slug → query-var rewrite rule.
	 *
	 * Runs on `init` so every request — including ones that flush
	 * rules — sees the rule. The slug is read from settings each
	 * time; M5's `flush_rewrites_on_slug_change` hook re-flushes
	 * when the admin changes it.
	 *
	 * @since 0.5.0
	 */
	public function register_rewrite(): void {
		$slug = trim( (string) get_option( OPTION_MANAGE_SLUG, DEF_MANAGE_SLUG ), '/' );

		if ( '' === $slug ) {
			return;
		}

		add_rewrite_rule(
			'^' . preg_quote( $slug, '/' ) . '/?$',
			'index.php?' . QUERY_VAR_MANAGE . '=1',
			'top'
		);
	}

	/**
	 * Whitelist the query var so WP_Query keeps it through dispatch.
	 *
	 * @since 0.5.0
	 * @param array<int, string> $vars Existing public query vars.
	 * @return array<int, string>
	 */
	public function register_query_vars( array $vars ): array {
		$vars[] = QUERY_VAR_MANAGE;

		return $vars;
	}

	/**
	 * `template_redirect` handler. Take over the response when our
	 * query var is present; otherwise no-op.
	 *
	 * @since 0.5.0
	 */
	public function dispatch(): void {
		if ( '1' !== (string) get_query_var( QUERY_VAR_MANAGE ) ) {
			return;
		}

		// nocache_headers() blocks intermediate CDN / browser cache
		// from sharing one user's preferences page with another.
		nocache_headers();

		$this->handle();

		exit;
	}

	/**
	 * Core dispatch — token verify, throttle, route by method.
	 *
	 * @since 0.5.0
	 */
	private function handle(): void {
		$token = $this->incoming_token();

		if ( '' === $token ) {
			$this->render_invalid_token();
			return;
		}

		if ( ! $this->within_rate_limit( $token ) ) {
			$this->render_rate_limited();
			return;
		}

		$subscriber_id = ( new Tokens() )->verify( $token );

		if ( null === $subscriber_id ) {
			$this->render_invalid_token();
			return;
		}

		$subscriber = ( new Subscribers_Controller() )->get( $subscriber_id );

		if ( null === $subscriber ) {
			$this->render_invalid_token();
			return;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';

		if ( 'POST' === $method ) {
			$this->handle_post( $subscriber, $token );
			return;
		}

		$this->render_preferences( $subscriber, $token );
	}

	/**
	 * Route a POST request between the three supported flows.
	 *
	 * Signals:
	 *   - `_hum_unsubscribe_all_nonce` field present → "Unsubscribe
	 *     from everything" button — flips to never-contact.
	 *   - `_hum_nonce` field present → groups-only form save.
	 *   - Per RFC 8058: body contains `List-Unsubscribe=One-Click`
	 *     OR query string carries `action=unsubscribe` → one-click
	 *     (mail-client triggered, no CSRF nonce possible).
	 *   - Anything else → 400. Don't silently treat unknown POSTs as
	 *     no-ops; an attacker could otherwise hammer the endpoint
	 *     under the rate-limit budget without any feedback loop.
	 *
	 * @since 0.5.0
	 * @param object $subscriber Authenticated subscriber row.
	 * @param string $token      Verified bearer token.
	 */
	private function handle_post( object $subscriber, string $token ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce-presence is the router signal; verified below in the handler.
		$has_unsub_all_nonce = isset( $_POST['_hum_unsubscribe_all_nonce'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce-presence is the router signal; verified below in handle_preferences_save().
		$has_nonce_field = isset( $_POST['_hum_nonce'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- One-click body is compared as an exact string; bearer token is the auth.
		$is_one_click_body = isset( $_POST['List-Unsubscribe'] ) && 'One-Click' === $_POST['List-Unsubscribe'];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Bearer-token auth on a public endpoint; query string read.
		$is_unsub_action = isset( $_GET['action'] ) && 'unsubscribe' === sanitize_key( wp_unslash( $_GET['action'] ) );

		if ( $has_unsub_all_nonce ) {
			$this->handle_unsubscribe_everything( $subscriber, $token );
			return;
		}

		if ( $has_nonce_field ) {
			$this->handle_preferences_save( $subscriber, $token );
			return;
		}

		if ( $is_one_click_body || $is_unsub_action ) {
			$this->handle_one_click_unsubscribe( $subscriber );
			return;
		}

		status_header( 400 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html__( 'Bad request.', 'heads-up-mailer' ) . "\n";
	}

	/**
	 * "Unsubscribe from everything" button — flips to
	 * `never_contact` (deliberately stickier than the one-click
	 * RFC 8058 path, which is `unsubscribed`).
	 *
	 * Browser-side flow with a CSRF nonce, separate from the
	 * groups-save form so an admin can spot it in network logs.
	 * PRG redirect to the same page so the never-contact lockdown
	 * view renders on refresh.
	 *
	 * @since 0.8.0
	 * @param object $subscriber Authenticated subscriber row.
	 * @param string $token      Verified bearer token (round-tripped into the redirect).
	 */
	private function handle_unsubscribe_everything( object $subscriber, string $token ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce value passed verbatim to wp_verify_nonce() on the next line.
		$nonce = isset( $_POST['_hum_unsubscribe_all_nonce'] ) ? (string) $_POST['_hum_unsubscribe_all_nonce'] : '';

		if ( ! wp_verify_nonce( $nonce, 'hum_manage_unsub_all_' . (int) $subscriber->id ) ) {
			status_header( 403 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo esc_html__( 'Security check failed. Please reload the page and try again.', 'heads-up-mailer' ) . "\n";
			return;
		}

		$subs_controller = new Subscribers_Controller();
		$subs_controller->set_groups( (int) $subscriber->id, array() );
		$subs_controller->mark_never_contact( (int) $subscriber->id );

		$redirect_url = add_query_arg(
			array( 'token' => $token ),
			home_url( '/' . trim( (string) get_option( OPTION_MANAGE_SLUG, DEF_MANAGE_SLUG ), '/' ) . '/' )
		);

		wp_safe_redirect( $redirect_url, 303 );
	}

	/**
	 * RFC 8058 one-click unsubscribe target.
	 *
	 * No CSRF nonce — the bearer token is the authentication. Mail
	 * clients (e.g. Gmail) POST without ever loading the page, so
	 * nonce issuance isn't possible. The response is plain text
	 * because mail clients don't render the body.
	 *
	 * Lands the row in `unsubscribed`, not `never_contact`:
	 * mail-client buttons are easy to hit by accident, and the
	 * resubscribe-via-groups path on `/manage-comms/` is the
	 * kindest recovery. The explicit "Unsubscribe from everything"
	 * button on the page IS the never-contact trigger.
	 *
	 * Idempotent: re-POSTing on an already-unsubscribed row is a
	 * no-op that still returns 200.
	 *
	 * @since 0.5.0
	 * @param object $subscriber Authenticated subscriber row.
	 */
	private function handle_one_click_unsubscribe( object $subscriber ): void {
		( new Subscribers_Controller() )->unsubscribe( (int) $subscriber->id );

		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html__( 'Thanks. You have been unsubscribed.', 'heads-up-mailer' ) . "\n";
	}

	/**
	 * Form-save handler — update group memberships and optionally
	 * flip subscribe / unsubscribe status.
	 *
	 * CSRF-protected by the form's `_hum_nonce` field bound to the
	 * subscriber ID, separate from the access token. PRG redirect
	 * (303 → GET) so a refresh doesn't re-POST.
	 *
	 * @since 0.5.0
	 * @param object $subscriber Authenticated subscriber row.
	 * @param string $token      Verified bearer token (for the redirect URL).
	 */
	private function handle_preferences_save( object $subscriber, string $token ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce value passed verbatim to wp_verify_nonce() on the next line.
		$nonce = isset( $_POST['_hum_nonce'] ) ? (string) $_POST['_hum_nonce'] : '';

		if ( ! wp_verify_nonce( $nonce, 'hum_manage_prefs_' . (int) $subscriber->id ) ) {
			status_header( 403 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo esc_html__( 'Security check failed. Please reload the page and try again.', 'heads-up-mailer' ) . "\n";
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$posted_groups = ( isset( $_POST['groups'] ) && is_array( $_POST['groups'] ) )
			? array_map( 'intval', wp_unslash( $_POST['groups'] ) )
			: array();

		$subs_controller   = new Subscribers_Controller();
		$groups_controller = new Groups_Controller();

		// Intersect posted IDs with the groups the subscriber was
		// actually allowed to SEE on render. Computed against the
		// EXISTING memberships (pre-save) so a tampered POST can't
		// add the subscriber to a private group by pretending to
		// already be a member.
		$existing_ids   = $subs_controller->get_groups( (int) $subscriber->id );
		$visible_groups = $groups_controller->get_visible_for( $existing_ids );

		$valid_ids = array();

		foreach ( $visible_groups as $group ) {
			$valid_ids[] = (int) $group->id;
		}

		$kept_ids = array_values( array_intersect( $posted_groups, $valid_ids ) );

		// Preserve any private-group memberships the subscriber
		// already had that weren't visible on the form. If
		// `get_visible_for` is working correctly this is a no-op
		// — every existing membership IS visible — but the
		// belt-and-braces merge keeps us safe against future
		// visibility-rule changes.
		$hidden_existing_ids = array_diff( $existing_ids, $valid_ids );
		$selected_ids        = array_values( array_unique( array_merge( $kept_ids, $hidden_existing_ids ) ) );

		$subs_controller->set_groups( (int) $subscriber->id, $selected_ids );

		if ( ! empty( $selected_ids ) ) {
			// Ticking groups while unsubscribed implies resubscribe.
			// `resubscribe()` is a no-op for already-subscribed rows
			// and (per its contract) won't touch never-contact rows.
			$subs_controller->resubscribe( (int) $subscriber->id );
		} else {
			// Untick-everything via the groups form = soft
			// unsubscribe, NOT never-contact. The page's separate
			// button is the never-contact trigger.
			$subs_controller->unsubscribe( (int) $subscriber->id );
		}

		$redirect_url = add_query_arg(
			array(
				'token' => $token,
				'saved' => '1',
			),
			home_url( '/' . trim( (string) get_option( OPTION_MANAGE_SLUG, DEF_MANAGE_SLUG ), '/' ) . '/' )
		);

		wp_safe_redirect( $redirect_url, 303 );
	}

	/**
	 * Read the token from `$_GET['token']` or `$_POST['token']`.
	 *
	 * @since 0.5.0
	 */
	private function incoming_token(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Public endpoint authenticated by bearer token (not a nonce); strict pattern match below is the sanitisation.
		$raw = isset( $_REQUEST['token'] ) ? (string) wp_unslash( $_REQUEST['token'] ) : '';

		// Strict shape — `<digits>.<64 hex chars>`. Anything else is
		// treated as invalid. Do NOT silently strip stray characters:
		// that would let an attacker turn a tampered token back into
		// a valid one by appending non-hex noise.
		return preg_match( '/^[0-9]+\.[0-9a-fA-F]{64}$/', $raw ) ? $raw : '';
	}

	/**
	 * Check + increment the per-token rate-limit counter.
	 *
	 * 20 requests/hour/token. Sliding window — each request bumps
	 * the TTL — which is generous on purpose: legitimate users
	 * never approach the limit, and an attacker hammering the
	 * endpoint stays bottled.
	 *
	 * @since 0.5.0
	 * @param string $token Raw token (already alphabet-restricted).
	 * @return bool True if the request is within the limit.
	 */
	private function within_rate_limit( string $token ): bool {
		$key   = TRANSIENT_RATE_LIMIT . md5( $token );
		$count = (int) get_transient( $key );

		if ( $count >= RATE_LIMIT_MANAGE_PER_HOUR ) {
			return false;
		}

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		return true;
	}

	/**
	 * Render the preference page for an authenticated subscriber.
	 *
	 * Three rendering branches, switched in the template:
	 *
	 *   - `$is_never_contact` — lockdown view, no controls. Anyone
	 *     holding a stale token still gets a friendly response but
	 *     no information leak about group memberships.
	 *   - `$is_already_unsubscribed` — render the form but show a
	 *     "you're currently unsubscribed; tick a group to resubscribe"
	 *     notice.
	 *   - Default — render the form normally.
	 *
	 * @since 0.5.0
	 * @param object $subscriber Subscriber row.
	 * @param string $token      Verified bearer token (round-tripped into the form).
	 */
	private function render_preferences( object $subscriber, string $token ): void {
		$groups_controller = new Groups_Controller();
		$subs_controller   = new Subscribers_Controller();

		$attached_ids = $subs_controller->get_groups( (int) $subscriber->id );
		// Private groups they're not in are hidden — no sign-up
		// checkbox, no information leak. Member-of-private rows
		// stay visible so they can leave.
		$all_groups = $groups_controller->get_visible_for( $attached_ids );

		$is_never_contact        = ( STATUS_NEVER_CONTACT === (string) $subscriber->status );
		$is_already_unsubscribed = ( STATUS_UNSUBSCRIBED === (string) $subscriber->status );

		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );

		require HUM_PATH . 'public-templates/manage-comms.php';
	}

	/**
	 * 404 response for invalid / unknown tokens.
	 *
	 * Same body for "missing token", "bad format", "unknown
	 * subscriber", and "MAC mismatch" — distinguishing them would
	 * leak which subscribers exist.
	 *
	 * @since 0.5.0
	 */
	private function render_invalid_token(): void {
		status_header( 404 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html__( 'This unsubscribe link is invalid or has expired. Please contact the sender for a fresh link.', 'heads-up-mailer' ) . "\n";
	}

	/**
	 * 429 response when the per-token rate limit fires.
	 *
	 * @since 0.5.0
	 */
	private function render_rate_limited(): void {
		status_header( 429 );
		header( 'Retry-After: 3600' );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html__( 'Too many requests. Please try again later.', 'heads-up-mailer' ) . "\n";
	}
}
