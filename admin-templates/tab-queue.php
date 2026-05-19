<?php
/**
 * Admin: Queue settings tab.
 *
 * `require`d from `settings-page.php`. The enclosing form posts to
 * `options.php` under the `hum_settings` group, so all this file
 * does is render the input rows with their current stored values.
 *
 * @package Heads_Up_Mailer
 * @since 0.2.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

$batch_size   = (int) get_option( OPTION_BATCH_SIZE, DEF_BATCH_SIZE );
$tick_minutes = (int) get_option( OPTION_TICK_MINUTES, DEF_TICK_MINUTES );

printf( '<h2>%s</h2>', esc_html__( 'Send queue', 'heads-up-mailer' ) );
printf(
	'<p>%s</p>',
	esc_html__(
		'How often the WP-Cron worker drains pending sends, and how many recipients it processes per tick.',
		'heads-up-mailer'
	)
);

printf( '<table class="form-table" role="presentation"><tbody>' );

printf(
	'<tr><th scope="row"><label for="hum-batch-size">%s</label></th><td><input name="%s" id="hum-batch-size" type="number" min="1" max="100" value="%d" class="small-text" /><p class="description">%s</p></td></tr>',
	esc_html__( 'Batch size', 'heads-up-mailer' ),
	esc_attr( OPTION_BATCH_SIZE ),
	(int) $batch_size,
	esc_html__( 'Recipients processed per cron tick. Default 10, range 1–100.', 'heads-up-mailer' )
);

printf(
	'<tr><th scope="row"><label for="hum-tick-minutes">%s</label></th><td><input name="%s" id="hum-tick-minutes" type="number" min="1" max="60" value="%d" class="small-text" /><p class="description">%s</p></td></tr>',
	esc_html__( 'Tick interval (minutes)', 'heads-up-mailer' ),
	esc_attr( OPTION_TICK_MINUTES ),
	(int) $tick_minutes,
	esc_html__( 'How often the queue drains. Default every 5 minutes, range 1–60. Rescheduling happens once the send pipeline (M5) lands.', 'heads-up-mailer' )
);

printf( '</tbody></table>' );
