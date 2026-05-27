<?php
/**
 * Admin: Integrations settings tab.
 *
 * Walks the integrations registry. For each active integration,
 * renders its section. If no integration is active, renders an
 * "Available integrations" card listing every registered one so
 * the admin knows what plugins / themes unlock which features.
 *
 * @package Heads_Up_Mailer
 * @since 0.9.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

$integrations = get_plugin()->get_integrations();
$active       = $integrations->get_active();
$available    = $integrations->get_available();

printf( '<h2>%s</h2>', esc_html__( 'Integrations', 'heads-up-mailer' ) );

if ( empty( $active ) ) {
	$parent_names = array_map(
		static fn( Integration $i ): string => $i->parent_label(),
		$integrations->get_all()
	);

	$list = empty( $parent_names )
		? __( 'No integrations are registered yet.', 'heads-up-mailer' )
		: sprintf(
			/* translators: %s: comma-separated list of supported parent plugins / themes. */
			__( 'No integrations available. Heads Up Mailer has integrations for %s — install one to enable.', 'heads-up-mailer' ),
			implode( ', ', $parent_names )
		);

	printf( '<p>%s</p>', esc_html( $list ) );

	return;
}

foreach ( $active as $integration ) {
	printf( '<h3>%s</h3>', esc_html( $integration->label() ) );

	$integration->render_settings_section();
}

if ( ! empty( $available ) ) {
	$parent_names = array_map(
		static fn( Integration $i ): string => $i->parent_label(),
		$available
	);

	printf( '<hr />' );
	printf(
		'<p class="description">%s</p>',
		esc_html(
			sprintf(
				/* translators: %s: comma-separated list of inactive integrations' parent plugins. */
				__( 'Other available integrations: %s. Install one of these to unlock additional sign-up paths.', 'heads-up-mailer' ),
				implode( ', ', $parent_names )
			)
		)
	);
}
