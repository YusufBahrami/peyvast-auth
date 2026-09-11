<?php
namespace Peyvast\Auth\Presentation\Admin;

use Peyvast\Auth\Core\Config\Settings;
use Peyvast\Auth\Presentation\Frontend\Assets;

defined( 'ABSPATH' ) || exit;

final class Logs {
	private static bool $booted = false;
	private const LEVELS        = array(
		'debug'    => 10,
		'info'     => 20,
		'notice'   => 30,
		'warning'  => 40,
		'error'    => 50,
		'critical' => 60,
	);

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_action( 'admin_post_peyvast_auth_clear_logs', array( self::class, 'clear' ) );
		add_action(
			'admin_enqueue_scripts',
			static function (): void {
				if ( isset( $_GET['page'] ) && sanitize_key( (string) $_GET['page'] ) === 'peyvast-auth-logs' ) {
					Assets::enqueue_admin();
				}
			}
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'peyvast-auth' ) );
		}

		global $wpdb;
		$table    = PEYVAST_AUTH_LOG_TABLE;
		$level    = sanitize_key( $_GET['level'] ?? '' );
		$channel  = sanitize_key( $_GET['channel'] ?? '' );
		$provider = sanitize_key( $_GET['provider'] ?? '' );
		$search   = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$page     = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per_page = 40;
		$where    = array( '1=1' );
		$args     = array();

		if ( isset( self::LEVELS[ $level ] ) ) {
			$where[] = 'level = %s';
			$args[]  = $level; }
		if ( $channel !== '' ) {
			$where[] = 'channel = %s';
			$args[]  = $channel; }
		if ( $provider !== '' ) {
			$where[] = 'provider = %s';
			$args[]  = $provider; }
		if ( $search !== '' ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(message LIKE %s OR event LIKE %s OR provider LIKE %s OR request_id LIKE %s OR context LIKE %s)';
			array_push( $args, $like, $like, $like, $like, $like );
		}
		$where_sql = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) $wpdb->get_var( $args ? $wpdb->prepare( $count_sql, ...$args ) : $count_sql );
		$pages     = max( 1, (int) ceil( $total / $per_page ) );
		$page      = min( $page, $pages );
		$offset    = ( $page - 1 ) * $per_page;
		$query     = "SELECT id,level,channel,event,provider,user_id,request_id,message,context,created_at FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$rows      = $wpdb->get_results( $wpdb->prepare( $query, ...array_merge( $args, array( $per_page, $offset ) ) ) );

		$channels        = $wpdb->get_col( "SELECT DISTINCT channel FROM {$table} WHERE channel <> '' ORDER BY channel ASC" );
		$providers       = $wpdb->get_col( "SELECT DISTINCT provider FROM {$table} WHERE provider <> '' ORDER BY provider ASC" );
		$stats           = self::stats();
		$logging_enabled = (bool) Settings::get( 'logging.enabled', false );
		?>
		<div class="wrap peyvast-auth-admin peyvast-auth-admin--logs" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>" data-peyvast-auth-admin data-peyvast-auth-log-page>
			<header class="peyvast-auth-admin__header">
				<div class="peyvast-auth-admin__brand">
					<div class="peyvast-auth-admin__brand-mark"><span class="dashicons dashicons-list-view" aria-hidden="true"></span></div>
					<div class="peyvast-auth-admin__brand-copy">
						<span class="peyvast-auth-eyebrow"><?php echo esc_html__( 'Peyvast Authentication', 'peyvast-auth' ); ?></span>
						<h1><?php echo esc_html__( 'Operational Logs', 'peyvast-auth' ); ?></h1>
						<p><?php echo esc_html__( 'Diagnostic records for authentication, OTP delivery, providers and security. Authentication secrets are redacted; diagnostic request/response data remains available.', 'peyvast-auth' ); ?></p>
					</div>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Delete all logs?', 'peyvast-auth' ) ); ?>');">
					<?php wp_nonce_field( 'peyvast_auth_clear_logs' ); ?>
					<input type="hidden" name="action" value="peyvast_auth_clear_logs">
					<button class="peyvast-auth-button" type="submit"><span class="dashicons dashicons-trash" aria-hidden="true"></span><?php echo esc_html__( 'Clear logs', 'peyvast-auth' ); ?></button>
				</form>
			</header>

			<?php if ( ! $logging_enabled ) : ?>
				<div class="peyvast-auth-integration-banner is-warning" role="status">
					<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
					<div><strong><?php echo esc_html__( 'Logging is disabled', 'peyvast-auth' ); ?></strong><p><?php echo esc_html__( 'New diagnostic records will not be stored until logging is enabled in Settings.', 'peyvast-auth' ); ?></p></div>
				</div>
			<?php endif; ?>

			<div class="peyvast-auth-log-stats">
				<?php
				foreach ( array(
					array(
						'label' => __( 'Total', 'peyvast-auth' ),
						'value' => $stats['total'],
						'icon'  => 'dashicons-list-view',
					),
					array(
						'label' => __( 'Errors', 'peyvast-auth' ),
						'value' => $stats['error'],
						'icon'  => 'dashicons-warning',
					),
					array(
						'label' => __( 'Provider errors', 'peyvast-auth' ),
						'value' => $stats['provider'],
						'icon'  => 'dashicons-cloud',
					),
					array(
						'label' => __( 'Authentication events', 'peyvast-auth' ),
						'value' => $stats['auth'],
						'icon'  => 'dashicons-lock',
					),
				) as $stat ) :
					?>
					<div class="peyvast-auth-log-stat"><span class="peyvast-auth-log-stat__icon dashicons <?php echo esc_attr( $stat['icon'] ); ?>" aria-hidden="true"></span><div class="peyvast-auth-log-stat__meta"><span><?php echo esc_html( $stat['label'] ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) $stat['value'] ) ); ?></strong></div></div>
				<?php endforeach; ?>
			</div>

			<section class="peyvast-auth-card peyvast-auth-log-filter-card">
				<form method="get" class="peyvast-auth-log-filters">
					<input type="hidden" name="page" value="peyvast-auth-logs">
					<label><span><?php echo esc_html__( 'Level', 'peyvast-auth' ); ?></span><select class="peyvast-auth-select" name="level"><option value=""><?php echo esc_html__( 'All levels', 'peyvast-auth' ); ?></option>
					<?php
					foreach ( self::LEVELS as $key => $unused ) :
						?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $level, $key ); ?>><?php echo esc_html( ucfirst( $key ) ); ?></option><?php endforeach; ?></select></label>
					<label><span><?php echo esc_html__( 'Channel', 'peyvast-auth' ); ?></span><select class="peyvast-auth-select" name="channel"><option value=""><?php echo esc_html__( 'All channels', 'peyvast-auth' ); ?></option>
					<?php
					foreach ( $channels as $item ) :
						?>
						<option value="<?php echo esc_attr( $item ); ?>" <?php selected( $channel, $item ); ?>><?php echo esc_html( $item ); ?></option><?php endforeach; ?></select></label>
					<label><span><?php echo esc_html__( 'Provider', 'peyvast-auth' ); ?></span><select class="peyvast-auth-select" name="provider"><option value=""><?php echo esc_html__( 'All providers', 'peyvast-auth' ); ?></option>
					<?php
					foreach ( $providers as $item ) :
						?>
						<option value="<?php echo esc_attr( $item ); ?>" <?php selected( $provider, $item ); ?>><?php echo esc_html( $item ); ?></option><?php endforeach; ?></select></label>
					<label class="peyvast-auth-log-filter-search"><span><?php echo esc_html__( 'Search', 'peyvast-auth' ); ?></span><input class="peyvast-auth-input" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Event, message, provider or request ID', 'peyvast-auth' ); ?>"></label>
					<button class="peyvast-auth-button peyvast-auth-button--primary" type="submit"><?php echo esc_html__( 'Filter logs', 'peyvast-auth' ); ?></button>
				</form>
			</section>

			<section class="peyvast-auth-card peyvast-auth-log-table-card">
				<div class="peyvast-auth-log-table-wrap">
					<table class="peyvast-auth-log-table">
						<thead><tr><th><?php echo esc_html__( 'Time', 'peyvast-auth' ); ?></th><th><?php echo esc_html__( 'Severity', 'peyvast-auth' ); ?></th><th><?php echo esc_html__( 'Channel', 'peyvast-auth' ); ?></th><th><?php echo esc_html__( 'Event', 'peyvast-auth' ); ?></th><th><?php echo esc_html__( 'Provider', 'peyvast-auth' ); ?></th><th><?php echo esc_html__( 'Details', 'peyvast-auth' ); ?></th></tr></thead>
						<tbody>
						<?php if ( ! $rows ) : ?>
							<tr><td colspan="6"><div class="peyvast-auth-log-empty"><span class="dashicons dashicons-search" aria-hidden="true"></span><strong><?php echo esc_html__( 'No matching logs were found.', 'peyvast-auth' ); ?></strong><p><?php echo esc_html__( 'Try a different filter or search term.', 'peyvast-auth' ); ?></p></div></td></tr>
							<?php
						else :
							foreach ( $rows as $row ) :
								$context = json_decode( (string) $row->context, true );
								if ( ! is_array( $context ) ) {
									$context = array();
								}
								$uid         = absint( $row->user_id );
								$log_payload = array(
									'id'         => (int) $row->id,
									'time'       => get_date_from_gmt( $row->created_at, 'Y-m-d H:i:s' ),
									'level'      => (string) $row->level,
									'channel'    => (string) $row->channel,
									'event'      => (string) $row->event,
									'provider'   => (string) $row->provider,
									'user_id'    => $uid,
									'request_id' => (string) $row->request_id,
									'message'    => (string) $row->message,
									'context'    => $context,
								);
								?>
							<tr>
								<td><time datetime="<?php echo esc_attr( gmdate( 'c', strtotime( $row->created_at ) ) ); ?>"><?php echo esc_html( get_date_from_gmt( $row->created_at, 'Y-m-d H:i:s' ) ); ?></time><span class="peyvast-auth-log-id">#<?php echo esc_html( $row->id ); ?></span></td>
								<td><span class="peyvast-auth-badge peyvast-auth-badge--<?php echo esc_attr( $row->level ); ?>"><?php echo esc_html( $row->level ); ?></span></td>
								<td><?php echo esc_html( $row->channel ?: '—' ); ?></td>
								<td><code><?php echo esc_html( $row->event ?: '—' ); ?></code>
								<?php
								if ( $row->request_id ) :
									?>
									<span class="peyvast-auth-log-request"><?php echo esc_html( $row->request_id ); ?></span><?php endif; ?></td>
								<td><?php echo esc_html( $row->provider ?: '—' ); ?>
								<?php
								if ( $uid ) :
									?>
									<span class="peyvast-auth-log-user"><?php echo esc_html( sprintf( __( 'User #%d', 'peyvast-auth' ), $uid ) ); ?></span><?php endif; ?></td>
								<td><div class="peyvast-auth-log-message"><?php echo esc_html( $row->message ); ?></div><button type="button" class="peyvast-auth-button peyvast-auth-button--ghost peyvast-auth-log-view" data-peyvast-auth-log-view data-log="<?php echo esc_attr( wp_json_encode( $log_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?>"><span class="dashicons dashicons-visibility" aria-hidden="true"></span><span><?php echo esc_html__( 'View Details', 'peyvast-auth' ); ?></span></button></td>
							</tr>
								<?php
						endforeach;
endif;
						?>
						</tbody>
					</table>
				</div>
				<?php if ( $pages > 1 ) : ?>
					<footer class="peyvast-auth-log-pagination">
						<?php
						$base = add_query_arg(
							array(
								'page'     => 'peyvast-auth-logs',
								'level'    => $level,
								'channel'  => $channel,
								'provider' => $provider,
								's'        => $search,
								'paged'    => '%#%',
							),
							admin_url( 'admin.php' )
						);
						echo wp_kses_post(
							paginate_links(
								array(
									'base'    => $base,
									'current' => $page,
									'total'   => $pages,
									'type'    => 'plain',
								)
							)
						);
						?>
					</footer>
				<?php endif; ?>
			</section>

			<div class="peyvast-auth-log-modal" data-peyvast-auth-log-modal hidden>
				<div class="peyvast-auth-log-modal__backdrop" data-peyvast-auth-log-close></div>
				<aside class="peyvast-auth-log-modal__panel" role="dialog" aria-modal="true" aria-labelledby="peyvast-auth-log-modal-title">
					<header class="peyvast-auth-log-modal__header"><div><span class="peyvast-auth-eyebrow"><?php echo esc_html__( 'Operational log', 'peyvast-auth' ); ?></span><h2 id="peyvast-auth-log-modal-title"><?php echo esc_html__( 'Full log details', 'peyvast-auth' ); ?></h2></div><button type="button" class="peyvast-auth-log-modal__close" data-peyvast-auth-log-close aria-label="<?php echo esc_attr__( 'Close', 'peyvast-auth' ); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button></header>
					<div class="peyvast-auth-log-modal__body" data-peyvast-auth-log-modal-body></div>
				</aside>
			</div>
		</div>
		<?php
	}

	private static function stats(): array {
		global $wpdb;
		$table = PEYVAST_AUTH_LOG_TABLE;
		return array(
			'total'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ),
			'error'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE level IN (%s,%s)", 'error', 'critical' ) ),
			'provider' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE channel = %s AND level IN (%s,%s)", 'provider', 'error', 'critical' ) ),
			'auth'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE channel IN (%s,%s,%s)", 'auth', 'otp', 'google' ) ),
		);
	}

	public static function clear(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to perform this operation.', 'peyvast-auth' ) );
		}
		check_admin_referer( 'peyvast_auth_clear_logs' );
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . PEYVAST_AUTH_LOG_TABLE );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => 'peyvast-auth-logs',
					'peyvast_auth_cleared' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
