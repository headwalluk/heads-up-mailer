<?php
/**
 * WooCommerce integration.
 *
 * Two checkout-driven sign-up flows:
 *
 *   1. Customers group — admin selects an existing group in
 *      Settings → Integrations → WooCommerce. Every order's
 *      billing customer is auto-added to that group (T&C
 *      acceptance is the consent record). Leave the slug empty
 *      to disable.
 *   2. Per-group opt-in checkboxes — admin marks individual
 *      groups as "show at checkout" and supplies the label text.
 *      Checkboxes render in the billing form; ticked boxes
 *      enrol the customer in the corresponding group on order
 *      submit.
 *
 * @package Heads_Up_Mailer
 * @since 0.9.0
 */

namespace Heads_Up_Mailer\Integrations;

defined( 'ABSPATH' ) || die();

use Heads_Up_Mailer\Integration;
use Heads_Up_Mailer\Integrations as Registry;
use Heads_Up_Mailer\Groups_Controller;
use Heads_Up_Mailer\Subscribers_Controller;
use const Heads_Up_Mailer\OPTION_WC_CUSTOMERS_GROUP_SLUG;
use const Heads_Up_Mailer\OPTION_WC_CHECKOUT_INTRO;
use const Heads_Up_Mailer\OPTION_WC_CHECKOUT_GROUPS_JSON;

/**
 * WooCommerce integration.
 *
 * @since 0.9.0
 */
class WooCommerce extends Integration {

	/**
	 * Posted-field name for a single opt-in checkbox. Suffixed
	 * with the group slug per row.
	 *
	 * @since 0.9.0
	 */
	private const CHECKOUT_FIELD_PREFIX = 'hum_signup_';

	/**
	 * Integration slug used in option keys / admin URLs.
	 *
	 * @since 0.9.0
	 */
	public function slug(): string {
		return 'woocommerce';
	}

	/**
	 * Display name for the integration section header.
	 *
	 * @since 0.9.0
	 */
	public function label(): string {
		return __( 'WooCommerce', 'heads-up-mailer' );
	}

	/**
	 * Parent plugin name as the admin would recognise it.
	 *
	 * @since 0.9.0
	 */
	public function parent_label(): string {
		return __( 'WooCommerce', 'heads-up-mailer' );
	}

	/**
	 * True when WooCommerce is loaded.
	 *
	 * @since 0.9.0
	 */
	public function is_active(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Bind WooCommerce hooks. Only called when WC is loaded.
	 *
	 * @since 0.9.0
	 */
	public function register_hooks(): void {
		add_action( 'woocommerce_after_checkout_billing_form', array( $this, 'render_checkout_opt_ins' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'capture_opt_ins_on_order' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_order_processed' ), 10, 3 );
	}

	/**
	 * Render the per-group opt-in checkboxes under the billing
	 * form on the checkout page.
	 *
	 * Only fires if at least one group is configured with
	 * `at_checkout = true`.
	 *
	 * @since 0.9.0
	 */
	public function render_checkout_opt_ins(): void {
		$entries = $this->checkout_opt_in_entries();

		if ( empty( $entries ) ) {
			return;
		}

		$intro = (string) get_option( OPTION_WC_CHECKOUT_INTRO, '' );

		printf( '<div class="hum-wc-opt-ins">' );

		if ( '' !== $intro ) {
			printf( '<p>%s</p>', esc_html( $intro ) );
		}

		foreach ( $entries as $entry ) {
			$field = self::CHECKOUT_FIELD_PREFIX . sanitize_html_class( $entry['slug'] );

			printf(
				'<p class="form-row form-row-wide"><label><input type="checkbox" name="%1$s" value="1" /> %2$s</label></p>',
				esc_attr( $field ),
				esc_html( $entry['label'] )
			);
		}

		printf( '</div>' );
	}

	/**
	 * Persist which opt-in checkboxes were ticked onto the WC
	 * order meta before the order is committed. We can't read
	 * `$_POST` later in `woocommerce_checkout_order_processed`
	 * because WC has finished sanitising / discarding extra
	 * fields by then.
	 *
	 * Stored as a comma-separated slug list under
	 * `_hum_opt_in_slugs`.
	 *
	 * @since 0.9.0
	 * @param \WC_Order            $order WC order object (mutated).
	 * @param array<string, mixed> $data  Posted checkout data.
	 */
	public function capture_opt_ins_on_order( \WC_Order $order, array $data ): void {
		$entries = $this->checkout_opt_in_entries();

		if ( empty( $entries ) ) {
			return;
		}

		$ticked = array();

		foreach ( $entries as $entry ) {
			$field = self::CHECKOUT_FIELD_PREFIX . sanitize_html_class( $entry['slug'] );

			// $data is the WC-sanitised posted array; WC discards
			// fields it doesn't know, but the raw $_POST still
			// has our custom field. Honour both for safety.
			$from_data = ! empty( $data[ $field ] );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC handles the checkout-form nonce upstream.
			$from_post = ! empty( $_POST[ $field ] );

			if ( $from_data || $from_post ) {
				$ticked[] = $entry['slug'];
			}
		}

		if ( ! empty( $ticked ) ) {
			$order->update_meta_data( '_hum_opt_in_slugs', implode( ',', $ticked ) );
		}
	}

	/**
	 * `woocommerce_checkout_order_processed` — the order is
	 * committed; enrol the customer in every applicable group.
	 *
	 * Two enrolment paths:
	 *
	 *   1. Customers group — admin-configured slug; every
	 *      successfully-processed order enrols the customer.
	 *      Disabled when the slug is empty.
	 *   2. Per-group opt-ins — slugs captured in
	 *      `capture_opt_ins_on_order()` get applied here.
	 *
	 * @since 0.9.0
	 * @param int                  $order_id    Order ID.
	 * @param array<string, mixed> $posted_data Posted checkout data.
	 * @param \WC_Order|false      $order       The order, when WC supplies it (newer versions).
	 */
	public function on_order_processed( int $order_id, array $posted_data, $order = false ): void {
		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$email = strtolower( trim( (string) $order->get_billing_email() ) );

		if ( '' === $email || ! is_email( $email ) ) {
			return;
		}

		$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

		$subs              = new Subscribers_Controller();
		$groups_controller = new Groups_Controller();

		// (1) Customers group.
		$customers_slug = (string) get_option( OPTION_WC_CUSTOMERS_GROUP_SLUG, '' );

		if ( '' !== $customers_slug ) {
			$group = $groups_controller->get_by_slug( $customers_slug );

			if ( null !== $group ) {
				$subs->ensure_in_group( $email, $name, (int) $group->id, 'woocommerce-checkout' );
			}
		}

		// (2) Per-group opt-ins.
		$ticked_raw = (string) $order->get_meta( '_hum_opt_in_slugs', true );
		$ticked     = '' === $ticked_raw ? array() : array_filter( array_map( 'trim', explode( ',', $ticked_raw ) ) );

		foreach ( $ticked as $slug ) {
			$group = $groups_controller->get_by_slug( $slug );

			if ( null === $group ) {
				continue;
			}

			$subs->ensure_in_group( $email, $name, (int) $group->id, 'woocommerce-checkout-opt-in' );
		}
	}

	/**
	 * Resolve the decoded checkout opt-in entries, filtered to
	 * `at_checkout = true` AND groups that still exist.
	 *
	 * @since 0.9.0
	 * @return array<int, array{slug: string, label: string}>
	 */
	private function checkout_opt_in_entries(): array {
		$json    = (string) get_option( OPTION_WC_CHECKOUT_GROUPS_JSON, '{}' );
		$decoded = json_decode( $json, true );

		if ( ! is_array( $decoded ) || empty( $decoded ) ) {
			return array();
		}

		$groups_controller = new Groups_Controller();
		$entries           = array();

		foreach ( $decoded as $slug => $config ) {
			if ( empty( $config['at_checkout'] ) ) {
				continue;
			}

			$group = $groups_controller->get_by_slug( (string) $slug );

			if ( null === $group ) {
				continue;
			}

			$label = isset( $config['label'] ) ? (string) $config['label'] : '';

			if ( '' === $label ) {
				$label = (string) $group->name;
			}

			$entries[] = array(
				'slug'  => (string) $slug,
				'label' => $label,
			);
		}

		return $entries;
	}

	/**
	 * Render the WooCommerce section inside the Integrations
	 * settings tab.
	 *
	 * @since 0.9.0
	 */
	public function render_settings_section(): void {
		$groups          = ( new Groups_Controller() )->get_all();
		$customers_slug  = (string) get_option( OPTION_WC_CUSTOMERS_GROUP_SLUG, '' );
		$intro           = (string) get_option( OPTION_WC_CHECKOUT_INTRO, '' );
		$checkout_json   = (string) get_option( OPTION_WC_CHECKOUT_GROUPS_JSON, '{}' );
		$checkout_config = json_decode( $checkout_json, true );
		$checkout_config = is_array( $checkout_config ) ? $checkout_config : array();

		// Customers group dropdown.
		printf( '<h4>%s</h4>', esc_html__( 'Customers group', 'heads-up-mailer' ) );
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Every checkout adds the customer to this group. T&C acceptance at checkout is the consent record. Leave unset to disable.', 'heads-up-mailer' )
		);

		$options_html = sprintf(
			'<option value=""%s>%s</option>',
			selected( '', $customers_slug, false ),
			esc_html__( '— Disabled —', 'heads-up-mailer' )
		);

		foreach ( $groups as $group ) {
			$options_html .= sprintf(
				'<option value="%1$s"%2$s>%3$s (%1$s)</option>',
				esc_attr( (string) $group->slug ),
				selected( (string) $group->slug, $customers_slug, false ),
				esc_html( (string) $group->name )
			);
		}

		printf(
			'<p><select name="%s" id="hum-wc-customers-slug">%s</select></p>',
			esc_attr( OPTION_WC_CUSTOMERS_GROUP_SLUG ),
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Options built from esc_attr / esc_html above.
			$options_html
		);

		// Checkout opt-in intro.
		printf( '<h4>%s</h4>', esc_html__( 'Checkout opt-in checkboxes', 'heads-up-mailer' ) );
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Tick groups you want to offer at checkout as optional sign-ups, and provide the label text customers will see. The intro paragraph is rendered above all checkboxes.', 'heads-up-mailer' )
		);

		printf(
			'<p><label for="hum-wc-intro">%s</label><br /><textarea name="%s" id="hum-wc-intro" rows="3" class="large-text">%s</textarea></p>',
			esc_html__( 'Intro paragraph (1–2 sentences)', 'heads-up-mailer' ),
			esc_attr( OPTION_WC_CHECKOUT_INTRO ),
			esc_textarea( $intro )
		);

		// Per-group repeater. We store the full JSON in a single
		// hidden field; per-row form controls write to it via
		// onchange handlers below in JS — but the simpler
		// approach used here is to render one row per group with
		// named fields, then assemble the JSON in PHP on save
		// via the sanitize callback. To keep that flow
		// independent of the per-tab UI, we use a hidden field
		// whose value we rebuild on submit through inline JS.
		printf( '<table class="widefat striped"><thead><tr><th>%s</th><th>%s</th><th>%s</th></tr></thead><tbody>', esc_html__( 'Group', 'heads-up-mailer' ), esc_html__( 'Show at checkout', 'heads-up-mailer' ), esc_html__( 'Label text', 'heads-up-mailer' ) );

		if ( empty( $groups ) ) {
			printf(
				'<tr><td colspan="3"><em>%s</em></td></tr>',
				esc_html__( 'No groups defined yet. Create groups under Heads Up Mailer → Groups, then return here.', 'heads-up-mailer' )
			);
		} else {
			foreach ( $groups as $group ) {
				$slug        = (string) $group->slug;
				$row_config  = isset( $checkout_config[ $slug ] ) && is_array( $checkout_config[ $slug ] ) ? $checkout_config[ $slug ] : array();
				$row_checked = ! empty( $row_config['at_checkout'] );
				$row_label   = isset( $row_config['label'] ) ? (string) $row_config['label'] : '';
				$id_prefix   = 'hum-wc-row-' . sanitize_html_class( $slug );

				printf(
					'<tr data-slug="%1$s"><td><strong>%2$s</strong><br /><code>%1$s</code></td><td><input type="checkbox" id="%3$s-cb" class="hum-wc-cb"%4$s /></td><td><input type="text" id="%3$s-lbl" class="hum-wc-lbl regular-text" value="%5$s" /></td></tr>',
					esc_attr( $slug ),
					esc_html( (string) $group->name ),
					esc_attr( $id_prefix ),
					checked( $row_checked, true, false ),
					esc_attr( $row_label )
				);
			}
		}

		printf( '</tbody></table>' );

		// Hidden serialised JSON. JS rebuilds this on every
		// checkbox / label change so the Save settings button
		// posts the up-to-date map. Without JS, the existing
		// value is preserved.
		printf(
			'<input type="hidden" name="%s" id="hum-wc-checkout-groups-json" value="%s" />',
			esc_attr( OPTION_WC_CHECKOUT_GROUPS_JSON ),
			esc_attr( $checkout_json )
		);
	}
}

add_filter(
	'hum_integrations',
	static function ( Registry $registry ): Registry {
		$registry->register( new WooCommerce() );
		return $registry;
	}
);
