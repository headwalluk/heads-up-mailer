<?php
/**
 * Contact Form 7 integration.
 *
 * Registers a new form tag `[hum_signup group:slug "Label"]`.
 * Editors drop the tag into any CF7 form; on submit, if the
 * checkbox is ticked, the form's email field becomes a subscriber
 * in the named group.
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

/**
 * Contact Form 7 integration.
 *
 * @since 0.9.0
 */
class Contact_Form_7 extends Integration {

	/**
	 * Integration slug used in option keys / admin URLs.
	 *
	 * @since 0.9.0
	 */
	public function slug(): string {
		return 'contact-form-7';
	}

	/**
	 * Display name for the integration section header.
	 *
	 * @since 0.9.0
	 */
	public function label(): string {
		return __( 'Contact Form 7', 'heads-up-mailer' );
	}

	/**
	 * Parent plugin name as the admin would recognise it.
	 *
	 * @since 0.9.0
	 */
	public function parent_label(): string {
		return __( 'Contact Form 7', 'heads-up-mailer' );
	}

	/**
	 * True when Contact Form 7 is loaded.
	 *
	 * @since 0.9.0
	 */
	public function is_active(): bool {
		return defined( 'WPCF7_VERSION' ) || function_exists( 'wpcf7' );
	}

	/**
	 * Bind CF7 hooks. Only called when CF7 is loaded.
	 *
	 * @since 0.9.0
	 */
	public function register_hooks(): void {
		// `wpcf7_add_form_tag` registers a new tag with CF7's
		// form-builder. The third arg = features supported by the
		// tag; `name-attr` makes the tag a real submitted field.
		wpcf7_add_form_tag(
			'hum_signup',
			array( $this, 'render_form_tag' ),
			array( 'name-attr' => true )
		);

		add_action( 'wpcf7_before_send_mail', array( $this, 'on_submit' ), 10, 1 );
	}

	/**
	 * Render the checkbox markup for a `[hum_signup …]` tag.
	 *
	 * Tag attribute parsing:
	 *   - `group:slug` — target Heads Up group slug (required;
	 *     unresolved slugs render the checkbox but the submit
	 *     handler will skip the row with a debug log entry).
	 *   - `checked` — render the checkbox pre-ticked. The visitor can
	 *     still untick it before submitting, and the submit handler
	 *     only enrols rows that come back ticked. Intended for a
	 *     dedicated sign-up form, not for opt-ins bolted onto another
	 *     form (a pre-ticked box is not valid consent in that context).
	 *   - Free-text values (the bit after the colon-attributes)
	 *     become the visible label. Falls back to the group's
	 *     display name, then to a generic prompt.
	 *
	 * @since 0.9.0
	 * @param \WPCF7_FormTag|array<string,mixed> $tag Form tag.
	 * @return string HTML markup.
	 */
	public function render_form_tag( $tag ): string {
		$tag = new \WPCF7_FormTag( $tag );

		$name  = (string) $tag->name;
		$slug  = (string) $tag->get_option( 'group', '', true );
		$label = trim( (string) implode( ' ', $tag->values ) );

		// `checked` is a bare option (like CF7 core's `include_blank`),
		// so it lands in the tag's options, not its values — the label
		// above stays clean.
		$checked = $tag->has_option( 'checked' );

		if ( '' === $label ) {
			$groups_controller = new Groups_Controller();
			$group             = $groups_controller->get_by_slug( $slug );

			$label = ( null !== $group )
				? (string) $group->name
				: __( 'Sign up for our newsletter', 'heads-up-mailer' );
		}

		$id = 'hum-signup-' . sanitize_html_class( $name );

		return sprintf(
			'<span class="wpcf7-form-control-wrap %1$s"><label for="%2$s"><input type="checkbox" name="%3$s" id="%2$s" value="1"%5$s /> %4$s</label></span>',
			esc_attr( $name ),
			esc_attr( $id ),
			esc_attr( $name ),
			esc_html( $label ),
			$checked ? ' checked="checked"' : ''
		);
	}

	/**
	 * `wpcf7_before_send_mail` — process ticked hum_signup
	 * checkboxes.
	 *
	 * Walks every `hum_signup` form tag on the submitted form,
	 * checks whether its checkbox came back ticked, resolves the
	 * tag's `group:slug` attribute to a real group, locates the
	 * form's email field, and ensures the resulting subscriber
	 * exists + is a member of the target group.
	 *
	 * Failures (unknown slug, no email field, invalid email,
	 * never-contact existing row) are swallowed silently — a form
	 * submission shouldn't surface a Heads Up error to the
	 * recipient.
	 *
	 * @since 0.9.0
	 * @param \WPCF7_ContactForm $contact_form Submitted form.
	 */
	public function on_submit( \WPCF7_ContactForm $contact_form ): void {
		$submission = \WPCF7_Submission::get_instance();

		if ( ! $submission instanceof \WPCF7_Submission ) {
			return;
		}

		$posted_data = $submission->get_posted_data();
		$tags        = $contact_form->scan_form_tags();
		$email       = $this->find_email( $tags, $posted_data );

		if ( '' === $email ) {
			return;
		}

		$name = $this->find_name( $tags, $posted_data );

		foreach ( $tags as $raw_tag ) {
			if ( 'hum_signup' !== $raw_tag->type ) {
				continue;
			}

			$tag       = new \WPCF7_FormTag( $raw_tag );
			$field     = (string) $tag->name;
			$is_ticked = ! empty( $posted_data[ $field ] );

			if ( ! $is_ticked ) {
				continue;
			}

			$this->signup_for_tag( $tag, $email, $name, $contact_form );
		}
	}

	/**
	 * Resolve a single `[hum_signup …]` tag's target group and
	 * ensure the subscriber is in it.
	 *
	 * @since 0.9.0
	 * @param \WPCF7_FormTag     $tag          Resolved tag instance.
	 * @param string             $email        Subscriber email (already validated).
	 * @param string             $name         Subscriber name (empty for unknown).
	 * @param \WPCF7_ContactForm $contact_form Submitted form, used to derive `consent_source`.
	 */
	private function signup_for_tag( \WPCF7_FormTag $tag, string $email, string $name, \WPCF7_ContactForm $contact_form ): void {
		$slug = (string) $tag->get_option( 'group', '', true );

		if ( '' === $slug ) {
			return;
		}

		$group = ( new Groups_Controller() )->get_by_slug( $slug );

		if ( null === $group ) {
			return;
		}

		$consent_source = 'cf7-form:' . (int) $contact_form->id();

		( new Subscribers_Controller() )->ensure_in_group(
			$email,
			$name,
			(int) $group->id,
			$consent_source
		);
	}

	/**
	 * Locate the form's email value. Uses the first tag whose
	 * basetype is `email`.
	 *
	 * @since 0.9.0
	 * @param array<int, \WPCF7_FormTag> $tags        All form tags.
	 * @param array<string, mixed>       $posted_data Posted form data.
	 * @return string Email (lowercased + trimmed) or `''` if not found / invalid.
	 */
	private function find_email( array $tags, array $posted_data ): string {
		$email = '';

		foreach ( $tags as $raw_tag ) {
			$basetype = isset( $raw_tag->basetype ) ? (string) $raw_tag->basetype : '';

			if ( 'email' !== $basetype ) {
				continue;
			}

			$value = isset( $posted_data[ $raw_tag->name ] ) ? $posted_data[ $raw_tag->name ] : '';

			if ( is_array( $value ) ) {
				$value = reset( $value );
			}

			$candidate = strtolower( trim( (string) $value ) );

			if ( '' !== $candidate && is_email( $candidate ) ) {
				$email = $candidate;
				break;
			}
		}

		return $email;
	}

	/**
	 * Best-effort name extraction. Looks for a text tag named
	 * `your-name` or `name`; falls back to `''`.
	 *
	 * @since 0.9.0
	 * @param array<int, \WPCF7_FormTag> $tags        All form tags.
	 * @param array<string, mixed>       $posted_data Posted form data.
	 * @return string
	 */
	private function find_name( array $tags, array $posted_data ): string {
		$name = '';

		foreach ( $tags as $raw_tag ) {
			$tag_name = isset( $raw_tag->name ) ? (string) $raw_tag->name : '';

			if ( 'your-name' !== $tag_name && 'name' !== $tag_name ) {
				continue;
			}

			$value = isset( $posted_data[ $tag_name ] ) ? $posted_data[ $tag_name ] : '';

			if ( is_array( $value ) ) {
				$value = reset( $value );
			}

			$candidate = trim( (string) $value );

			if ( '' !== $candidate ) {
				$name = $candidate;
				break;
			}
		}

		return $name;
	}

	/**
	 * Render the CF7 section inside the Integrations tab.
	 *
	 * @since 0.9.0
	 */
	public function render_settings_section(): void {
		printf(
			'<p>%s</p>',
			esc_html__( 'Add a sign-up checkbox to any Contact Form 7 form by inserting the following tag in the form editor:', 'heads-up-mailer' )
		);

		printf(
			'<p><code>[hum_signup signup-newsletter group:%s "%s"]</code></p>',
			esc_html( $this->example_slug() ),
			esc_html__( 'Sign me up for the newsletter', 'heads-up-mailer' )
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Replace the `group:` attribute with the slug of the group you want sign-ups to land in. The quoted text becomes the visible checkbox label. The form must include an email field — that\'s where the subscriber\'s address comes from. Add `checked` to render the checkbox pre-ticked (e.g. on a dedicated sign-up form); the visitor can still untick it before submitting.', 'heads-up-mailer' )
		);
	}

	/**
	 * Pick a real group slug to use in the example, falling back
	 * to a placeholder if no groups exist yet.
	 *
	 * @since 0.9.0
	 */
	private function example_slug(): string {
		$groups = ( new Groups_Controller() )->get_all();

		if ( empty( $groups ) ) {
			return 'your-group-slug';
		}

		return (string) $groups[0]->slug;
	}
}

add_filter(
	'hum_integrations',
	static function ( Registry $registry ): Registry {
		$registry->register( new Contact_Form_7() );
		return $registry;
	}
);
