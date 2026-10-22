<?php
/**
 * Subscribers list table.
 *
 * Required lazily by `Admin_Subscribers::load_list_screen()`:
 * `WP_List_Table` lives in `wp-admin/includes/` and isn't loaded on
 * every admin request.
 *
 * @package Heads_Up_Mailer
 * @since 1.8.0
 */

namespace Heads_Up_Mailer;

defined( 'ABSPATH' ) || die();

/**
 * Subscribers screen on `WP_List_Table`: search, status views, group
 * filter, sortable columns, Screen Options and bulk actions.
 *
 * @since 1.8.0
 */
class Subscribers_List_Table extends \WP_List_Table {

	/**
	 * Current list state from `Admin_Subscribers::list_state()`.
	 *
	 * @since 1.8.0
	 * @var array<string, string|int>
	 */
	private array $state;

	/**
	 * Every group, keyed by ID.
	 *
	 * @since 1.8.0
	 * @var array<int, object>
	 */
	private array $groups_by_id = array();

	/**
	 * Group IDs per subscriber on the current page.
	 *
	 * @since 1.8.0
	 * @var array<int, array<int, int>>
	 */
	private array $memberships = array();

	/**
	 * Lower-cased email => WordPress user ID, for the current page.
	 *
	 * @since 1.8.0
	 * @var array<string, int>
	 */
	private array $linked_users = array();

	/**
	 * Read the list state and bind to the current screen.
	 *
	 * @since 1.8.0
	 * @param array<string, string|int> $state List state from `Admin_Subscribers::list_state()`.
	 */
	public function __construct( array $state ) {
		parent::__construct(
			array(
				'singular' => 'subscriber',
				'plural'   => 'subscribers',
				'ajax'     => false,
			)
		);

		$this->state = $state;
	}

	/**
	 * Translated label for each subscriber status.
	 *
	 * @since 1.8.0
	 * @return array<string, string>
	 */
	public static function status_labels(): array {
		return array(
			STATUS_SUBSCRIBED    => _x( 'Subscribed', 'subscriber status', 'heads-up-mailer' ),
			STATUS_UNSUBSCRIBED  => _x( 'Unsubscribed', 'subscriber status', 'heads-up-mailer' ),
			STATUS_BOUNCED       => _x( 'Bounced', 'subscriber status', 'heads-up-mailer' ),
			STATUS_COMPLAINED    => _x( 'Complained', 'subscriber status; marked as spam', 'heads-up-mailer' ),
			STATUS_NEVER_CONTACT => _x( 'Never contact', 'subscriber status', 'heads-up-mailer' ),
		);
	}

	/**
	 * Column headings.
	 *
	 * @since 1.8.0
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'cb'         => '<input type="checkbox" />',
			'email'      => _x( 'Email', 'column heading', 'heads-up-mailer' ),
			'name'       => _x( 'Name', 'column heading; person name', 'heads-up-mailer' ),
			'status'     => _x( 'Status', 'column heading; subscription status', 'heads-up-mailer' ),
			'groups'     => _x( 'Groups', 'column heading; mailing groups', 'heads-up-mailer' ),
			'source'     => _x( 'Source', 'column heading; where the subscriber signed up', 'heads-up-mailer' ),
			'consent_at' => _x( 'Consented', 'column heading; date consent was given', 'heads-up-mailer' ),
			'created_at' => _x( 'Added', 'column heading; date added to the list', 'heads-up-mailer' ),
		);
	}

	/**
	 * Sortable columns: column => [ orderby value, starts descending ].
	 *
	 * @since 1.8.0
	 * @return array<string, array{0:string, 1:bool}>
	 */
	protected function get_sortable_columns(): array {
		return array(
			'email'      => array( 'email', false ),
			'name'       => array( 'name', false ),
			'status'     => array( 'status', false ),
			'consent_at' => array( 'consent_at', true ),
			'created_at' => array( 'created_at', true ),
		);
	}

	/**
	 * The column that carries row actions and stays visible on narrow screens.
	 *
	 * @since 1.8.0
	 * @return string
	 */
	protected function get_default_primary_column_name(): string {
		return 'email';
	}

	/**
	 * Bulk actions.
	 *
	 * @since 1.8.0
	 * @return array<string, string>
	 */
	protected function get_bulk_actions(): array {
		return array(
			'never_contact' => __( 'Mark as never contact', 'heads-up-mailer' ),
			'delete'        => _x( 'Delete', 'verb; bulk action', 'heads-up-mailer' ),
		);
	}

	/**
	 * Load one page of rows plus the lookups the columns need. Call before output.
	 *
	 * @since 1.8.0
	 */
	public function prepare_items(): void {
		$subscribers_controller = new Subscribers_Controller();
		$groups_controller      = new Groups_Controller();

		foreach ( $groups_controller->get_all() as $group ) {
			$this->groups_by_id[ (int) $group->id ] = $group;
		}

		$per_page = min( DEF_SUBSCRIBERS_PER_PAGE_MAX, max( 1, $this->get_items_per_page( META_SUBSCRIBERS_PER_PAGE, DEF_SUBSCRIBERS_PER_PAGE ) ) );
		$query    = array(
			'search'   => (string) ( $this->state['s'] ?? '' ),
			'status'   => (string) ( $this->state['status'] ?? '' ),
			'group'    => (int) ( $this->state['group'] ?? 0 ),
			'orderby'  => (string) ( $this->state['orderby'] ?? SUBSCRIBER_DEFAULT_ORDERBY ),
			'order'    => (string) ( $this->state['order'] ?? 'desc' ),
			'per_page' => $per_page,
			'offset'   => ( $this->get_pagenum() - 1 ) * $per_page,
		);

		$result      = $subscribers_controller->query( $query );
		$total_pages = max( 1, (int) ceil( $result['total'] / $per_page ) );

		$this->items = $result['rows'];

		$subscriber_ids = array();
		$emails         = array();

		foreach ( $this->items as $subscriber ) {
			$subscriber_ids[] = (int) $subscriber->id;
			$emails[]         = (string) $subscriber->email;
		}

		$this->memberships  = $subscribers_controller->group_ids_for_subscribers( $subscriber_ids );
		$this->linked_users = $subscribers_controller->user_ids_for_emails( $emails );

		// Runs before output, so an out-of-range `paged` makes core redirect to
		// the last page (filters kept) rather than render an empty view.
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
				'total_pages' => $total_pages,
			)
		);
	}

	/**
	 * Status views (All | Subscribed | …) with counts; empty statuses are hidden.
	 *
	 * @since 1.8.0
	 * @return array<string, string>
	 */
	protected function get_views(): array {
		$subscribers_controller = new Subscribers_Controller();
		$counts                 = $subscribers_controller->count_by_status();
		$current_status         = (string) ( $this->state['status'] ?? '' );
		$base_state             = array_diff_key( $this->state, array_flip( array( 'status', 'paged' ) ) );

		$views = array(
			'all' => $this->view_link(
				Admin_Subscribers::list_url( $base_state ),
				/* translators: %s: number of subscribers. */
				_nx( 'All <span class="count">(%s)</span>', 'All <span class="count">(%s)</span>', array_sum( $counts ), 'subscriber status view', 'heads-up-mailer' ),
				array_sum( $counts ),
				'' === $current_status
			),
		);

		foreach ( self::status_labels() as $status => $label ) {
			if ( ( $counts[ $status ] ?? 0 ) > 0 ) {
				$views[ $status ] = $this->view_link(
					Admin_Subscribers::list_url( array_merge( $base_state, array( 'status' => $status ) ) ),
					esc_html( $label ) . ' <span class="count">(%s)</span>',
					$counts[ $status ],
					$status === $current_status
				);
			}
		}

		return $views;
	}

	/**
	 * One status view link.
	 *
	 * @since 1.8.0
	 * @param string $url        Link target.
	 * @param string $label_html Label HTML with a `%s` for the formatted count; escaped or from a translation.
	 * @param int    $count      Number of subscribers in the view.
	 * @param bool   $is_current Whether this is the active view.
	 * @return string
	 */
	private function view_link( string $url, string $label_html, int $count, bool $is_current ): string {
		return sprintf(
			'<a href="%s"%s>%s</a>',
			esc_url( $url ),
			$is_current ? ' class="current" aria-current="page"' : '',
			sprintf( $label_html, esc_html( number_format_i18n( $count ) ) )
		);
	}

	/**
	 * Group filter above the table.
	 *
	 * @since 1.8.0
	 * @param string $which `top` or `bottom`.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' === $which && ! empty( $this->groups_by_id ) ) {
			$groups_controller = new Groups_Controller();
			$member_counts     = $groups_controller->member_counts();
			$current_group     = (int) ( $this->state['group'] ?? 0 );

			$options = sprintf(
				'<option value="0">%s</option><option value="%d"%s>%s</option>',
				esc_html__( 'All groups', 'heads-up-mailer' ),
				(int) GROUP_FILTER_NONE,
				selected( $current_group, GROUP_FILTER_NONE, false ),
				esc_html__( 'In no group', 'heads-up-mailer' )
			);

			foreach ( $this->groups_by_id as $group_id => $group ) {
				$options .= sprintf(
					'<option value="%d"%s>%s</option>',
					(int) $group_id,
					selected( $current_group, $group_id, false ),
					esc_html(
						sprintf(
							/* translators: 1: group name, 2: number of members. */
							__( '%1$s (%2$s)', 'heads-up-mailer' ),
							(string) $group->name,
							number_format_i18n( (int) ( $member_counts[ $group_id ]['members'] ?? 0 ) )
						)
					)
				);
			}

			printf(
				'<div class="alignleft actions"><label for="hum-filter-group" class="screen-reader-text">%s</label><select name="group" id="hum-filter-group">%s</select>',
				esc_html__( 'Filter by group', 'heads-up-mailer' ),
				$options // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from esc_html'd values and selected() above.
			);
			submit_button( _x( 'Filter', 'verb; button label', 'heads-up-mailer' ), '', 'filter_action', false, array( 'id' => 'hum-filter-submit' ) );
			printf( '</div>' );
		}
	}

	/**
	 * Message for an empty result.
	 *
	 * @since 1.8.0
	 */
	public function no_items(): void {
		$is_filtered = '' !== (string) ( $this->state['s'] ?? '' ) || '' !== (string) ( $this->state['status'] ?? '' ) || 0 !== (int) ( $this->state['group'] ?? 0 );

		if ( $is_filtered ) {
			esc_html_e( 'No subscribers match these filters.', 'heads-up-mailer' );
		} else {
			esc_html_e( 'No subscribers yet. Add one or import from CSV.', 'heads-up-mailer' );
		}
	}

	/**
	 * Row checkbox.
	 *
	 * @since 1.8.0
	 * @param object $item Subscriber row.
	 * @return string
	 */
	protected function column_cb( $item ): string {
		return sprintf(
			'<label class="screen-reader-text" for="hum-cb-%1$d">%2$s</label><input id="hum-cb-%1$d" type="checkbox" name="subscriber_ids[]" value="%1$d" />',
			(int) $item->id,
			esc_html(
				sprintf(
					/* translators: %s: Subscriber email used as accessible checkbox label. */
					__( 'Select %s', 'heads-up-mailer' ),
					(string) $item->email
				)
			)
		);
	}

	/**
	 * Email, linked to the edit screen, with the linked-WP-user badge.
	 *
	 * @since 1.8.0
	 * @param object $item Subscriber row.
	 * @return string
	 */
	protected function column_email( $item ): string {
		$user_badge = '';
		$user_id    = $this->linked_users[ strtolower( (string) $item->email ) ] ?? 0;

		// Absent for imported and public sign-ups — no account with this email.
		if ( $user_id > 0 ) {
			$badge_icon = sprintf(
				'<span class="dashicons dashicons-admin-users" aria-hidden="true"></span><span class="screen-reader-text">%s</span>',
				esc_html__( 'Has a linked WordPress user account', 'heads-up-mailer' )
			);

			// get_edit_user_link() returns '' when the viewer can't edit that account.
			$user_edit_link = get_edit_user_link( (int) $user_id );

			if ( '' === $user_edit_link ) {
				$user_badge = sprintf(
					' <span class="hum-user-badge" title="%s">%s</span>',
					esc_attr__( 'Linked to a WordPress user account', 'heads-up-mailer' ),
					$badge_icon
				);
			} else {
				$user_badge = sprintf(
					' <a class="hum-user-badge" href="%s" title="%s">%s</a>',
					esc_url( $user_edit_link ),
					esc_attr__( 'Linked to a WordPress user account — view profile', 'heads-up-mailer' ),
					$badge_icon
				);
			}
		}

		return sprintf(
			'<strong><a class="row-title" href="%s">%s</a></strong>%s',
			esc_url( $this->edit_url( $item ) ),
			esc_html( (string) $item->email ),
			$user_badge
		);
	}

	/**
	 * Name.
	 *
	 * @since 1.8.0
	 * @param object $item Subscriber row.
	 * @return string
	 */
	protected function column_name( $item ): string {
		return esc_html( (string) $item->name );
	}

	/**
	 * Status badge.
	 *
	 * @since 1.8.0
	 * @param object $item Subscriber row.
	 * @return string
	 */
	protected function column_status( $item ): string {
		$status = (string) $item->status;

		return sprintf(
			'<span class="hum-status hum-status-%s">%s</span>',
			esc_attr( $status ),
			esc_html( self::status_labels()[ $status ] ?? $status )
		);
	}

	/**
	 * Group pills, each linking to the list filtered by that group.
	 *
	 * @since 1.8.0
	 * @param object $item Subscriber row.
	 * @return string
	 */
	protected function column_groups( $item ): string {
		$pills      = '';
		$base_state = array_diff_key( $this->state, array_flip( array( 'group', 'paged' ) ) );

		foreach ( $this->memberships[ (int) $item->id ] ?? array() as $group_id ) {
			if ( isset( $this->groups_by_id[ $group_id ] ) ) {
				$pills .= sprintf(
					'<a class="hum-group-pill" href="%s">%s</a>',
					esc_url( Admin_Subscribers::list_url( array_merge( $base_state, array( 'group' => $group_id ) ) ) ),
					esc_html( (string) $this->groups_by_id[ $group_id ]->name )
				);
			}
		}

		if ( '' === $pills ) {
			$pills = sprintf( '<span class="hum-group-none" aria-hidden="true">—</span><span class="screen-reader-text">%s</span>', esc_html_x( 'No groups', 'subscriber belongs to no mailing group', 'heads-up-mailer' ) );
		}

		return $pills;
	}

	/**
	 * Consent source, e.g. `woocommerce-checkout` or `cf7-form:53`.
	 *
	 * @since 1.8.0
	 * @param object $item Subscriber row.
	 * @return string
	 */
	protected function column_source( $item ): string {
		return sprintf( '<code>%s</code>', esc_html( (string) $item->consent_source ) );
	}

	/**
	 * Consent date, in the site timezone.
	 *
	 * @since 1.8.0
	 * @param object $item Subscriber row.
	 * @return string
	 */
	protected function column_consent_at( $item ): string {
		return esc_html( format_stored_datetime( (string) $item->consent_at ) );
	}

	/**
	 * Date added, in the site timezone.
	 *
	 * @since 1.8.0
	 * @param object $item Subscriber row.
	 * @return string
	 */
	protected function column_created_at( $item ): string {
		return esc_html( format_stored_datetime( (string) $item->created_at ) );
	}

	/**
	 * Fallback for any column without its own method.
	 *
	 * @since 1.8.0
	 * @param object $item        Subscriber row.
	 * @param string $column_name Column key.
	 * @return string
	 */
	protected function column_default( $item, $column_name ): string {
		return esc_html( (string) ( $item->$column_name ?? '' ) );
	}

	/**
	 * Hover actions under the email: Edit | Mark never-contact | Delete.
	 *
	 * @since 1.8.0
	 * @param object $item        Subscriber row.
	 * @param string $column_name Current column.
	 * @param string $primary     Primary column.
	 * @return string
	 */
	protected function handle_row_actions( $item, $column_name, $primary ): string {
		$html = '';

		if ( $column_name === $primary ) {
			$subscriber_id = (int) $item->id;
			$email         = (string) $item->email;

			$actions = array(
				'edit' => sprintf( '<a href="%s">%s</a>', esc_url( $this->edit_url( $item ) ), esc_html_x( 'Edit', 'verb; row action', 'heads-up-mailer' ) ),
			);

			// Hidden on never-contact rows so it doesn't read as an undo lever.
			if ( STATUS_NEVER_CONTACT !== (string) $item->status ) {
				$actions['never_contact'] = sprintf(
					'<a href="%s" data-hum-confirm="%s">%s</a>',
					esc_url( $this->admin_post_url( 'hum_mark_never_contact', $subscriber_id ) ),
					/* translators: %s: Subscriber email. */
					esc_attr( sprintf( __( 'Flag %s as "never contact"? This is sticky — future imports will skip them, and the only way back is to edit the status manually.', 'heads-up-mailer' ), $email ) ),
					esc_html__( 'Mark never-contact', 'heads-up-mailer' )
				);
			}

			$actions['delete'] = sprintf(
				'<a href="%s" class="submitdelete" data-hum-confirm="%s">%s</a>',
				esc_url( $this->admin_post_url( 'hum_delete_subscriber', $subscriber_id ) ),
				/* translators: %s: Subscriber email. */
				esc_attr( sprintf( __( 'Delete the subscriber "%s"?', 'heads-up-mailer' ), $email ) ),
				esc_html_x( 'Delete', 'verb; row action', 'heads-up-mailer' )
			);

			$html = $this->row_actions( $actions );
		}

		return $html;
	}

	/**
	 * Edit screen URL for a subscriber.
	 *
	 * @since 1.8.0
	 * @param object $item Subscriber row.
	 * @return string
	 */
	private function edit_url( object $item ): string {
		return add_query_arg(
			array(
				'page'          => SUBSCRIBERS_PAGE_SLUG,
				'action'        => 'edit',
				'subscriber_id' => (int) $item->id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Nonced admin-post URL for a single-subscriber action.
	 *
	 * @since 1.8.0
	 * @param string $action        admin-post action name; also the nonce action prefix.
	 * @param int    $subscriber_id Subscriber ID.
	 * @return string
	 */
	private function admin_post_url( string $action, int $subscriber_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'        => $action,
					'subscriber_id' => $subscriber_id,
				),
				admin_url( 'admin-post.php' )
			),
			$action . '_' . $subscriber_id
		);
	}
}
