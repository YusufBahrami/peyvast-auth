<?php
namespace Peyvast\Auth\Presentation\Admin;

use Peyvast\Auth\Presentation\Frontend\Assets;
use Peyvast\Auth\Domain\Phone\PhoneNumber;
use Peyvast\Auth\Core\Config\Settings;
use Peyvast\Auth\Application\Migration\PhoneMigrationService;
use Peyvast\Auth\Integrations\WooCommerce\Integration as WooCommerceIntegration;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {
	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_action( 'admin_menu', array( self::class, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		foreach ( self::admin_suffixes() as $suffix ) {
			add_action( 'admin_print_styles-' . $suffix, array( self::class, 'print_styles' ) );
			add_action( 'admin_print_scripts-' . $suffix, array( self::class, 'print_scripts' ) );
		}
	}
	public static function admin_menu(): void {
		add_menu_page(
			esc_html__( 'Peyvast Authentication', 'peyvast-auth' ),
			esc_html__( 'Peyvast Authentication', 'peyvast-auth' ),
			'manage_options',
			'peyvast-auth-settings',
			array( self::class, 'render' ),
			'dashicons-shield-alt',
			58
		);
		// WP adds the top-level page as its own first submenu; do not duplicate it.
		add_submenu_page( 'peyvast-auth-settings', esc_html__( 'Logs', 'peyvast-auth' ), esc_html__( 'Logs', 'peyvast-auth' ), 'manage_options', 'peyvast-auth-logs', array( 'Peyvast\Auth\Presentation\Admin\Logs', 'render' ) );
	}

	/** Every hook suffix WP can use for the plugin admin screens. */
	private static function admin_suffixes(): array {
		return array(
			'toplevel_page_peyvast-auth-settings',
			'peyvast-auth-settings_page_peyvast-auth-logs',
			'peyvast-auth-settings',
			'peyvast-auth-logs',
		);
	}

	public static function assets( string $hook ): void {
		$page        = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$screen      = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$screen_id   = $screen ? (string) $screen->id : '';
		$is_settings = $page === 'peyvast-auth-settings' || $screen_id === 'toplevel_page_peyvast-auth-settings' || $hook === 'toplevel_page_peyvast-auth-settings' || $hook === 'peyvast-auth-settings';
		$is_logs     = $page === 'peyvast-auth-logs' || $screen_id === 'peyvast-auth-settings_page_peyvast-auth-logs' || $hook === 'peyvast-auth-settings_page_peyvast-auth-logs' || $hook === 'peyvast-auth-logs';
		if ( ! $is_settings && ! $is_logs ) {
			return;
		}
		Assets::enqueue_admin();
	}

	public static function print_styles(): void {
		Assets::enqueue_admin_styles(); }

	public static function print_scripts(): void {
		Assets::enqueue_admin_scripts(); }

	private static function phone_meta_keys(): array {
		static $keys = null;
		if ( is_array( $keys ) ) {
			return $keys;
		}
		$cache_key = 'peyvast_auth_phone_meta_keys';
		$cached    = wp_cache_get( $cache_key, 'peyvast_auth' );
		if ( is_array( $cached ) ) {
			return $keys = $cached;
		}
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			wp_cache_set( $cache_key, $cached, 'peyvast_auth', HOUR_IN_SECONDS );
			return $keys = $cached;
		}
		global $wpdb;
		$rows = $wpdb->get_col( "SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE meta_key <> '' AND meta_value <> '' ORDER BY meta_key ASC LIMIT 500" );
		$keys = array();
		foreach ( (array) $rows as $row ) {
			$key = (string) $row;
			if ( $key === '' || in_array( $key, array( '_peyvast_auth_phone', '_peyvast_auth_phone_canonical' ), true ) ) {
				continue;
			}
			$keys[] = $key;
		}
		set_transient( $cache_key, $keys, HOUR_IN_SECONDS );
		wp_cache_set( $cache_key, $keys, 'peyvast_auth', HOUR_IN_SECONDS );
		return $keys;
	}

	private static function clear_phone_meta_key_cache(): void {
		delete_transient( 'peyvast_auth_phone_meta_keys' );
		wp_cache_delete( 'peyvast_auth_phone_meta_keys', 'peyvast_auth' );
	}

	private static function migration_summary( array $result ): string {
		$status    = (string) ( $result['status'] ?? 'idle' );
		$processed = (int) ( $result['processed'] ?? 0 );
		$total     = (int) ( $result['total'] ?? 0 );
		$migrated  = (int) ( $result['migrated'] ?? 0 );
		$current   = (int) ( $result['already_current'] ?? 0 );
		$conflict  = (int) ( $result['conflict'] ?? 0 );
		$failed    = (int) ( $result['failed'] ?? 0 );
		if ( $status === 'needs_review' ) {
			return esc_html__( 'The configured phone source changed or is incomplete. Review the source and run Batch Migration when a backup is available.', 'peyvast-auth' );
		}
		if ( $status === 'running' ) {
			return sprintf( esc_html__( 'Batch Migration is running: %1$d of %2$d users processed. %3$d migrated and %4$d already current.', 'peyvast-auth' ), $processed, $total, $migrated, $current );
		}
		if ( $status === 'failed' ) {
			return sprintf( esc_html__( 'Batch Migration stopped after %1$d of %2$d users. %3$d migrated and %4$d failures were recorded.', 'peyvast-auth' ), $processed, $total, $migrated, $failed );
		}
		if ( $status === 'completed' && ( $conflict > 0 || $failed > 0 ) ) {
			return sprintf( esc_html__( 'Batch Migration completed with %1$d conflicts and %2$d failed records.', 'peyvast-auth' ), $conflict, $failed );
		}
		if ( $status === 'completed' ) {
			return sprintf( esc_html__( 'Batch Migration completed: %1$d migrated, %2$d already current, with no conflicts or failures.', 'peyvast-auth' ), $migrated, $current );
		}
		return esc_html__( 'No Batch Migration has been started.', 'peyvast-auth' );
	}

	private static function country_options(): array {
		$options = array();
		foreach ( PhoneNumber::countries() as $key => $country ) {
			$options[ $key ] = sprintf( __( '%1$s (%2$s)', 'peyvast-auth' ), $country['label'], $country['dial'] );
		}
		return $options;
	}

	private static function page_options(): array {
		$options = array( '' => __( 'Disable / none', 'peyvast-auth' ) );
		foreach ( get_pages(
			array(
				'post_status' => array( 'publish', 'private', 'draft', 'pending', 'future' ),
				'sort_column' => 'menu_order,post_title',
				'sort_order'  => 'ASC',
			)
		) as $page ) {
			$options[ (string) $page->ID ] = wp_strip_all_tags( $page->post_title ?: sprintf( __( 'Page #%d', 'peyvast-auth' ), $page->ID ) );
		}
		return $options;
	}

	public static function tabs(): array {
		return array(
			'general'        => array(
				'label'       => esc_html__( 'Overview', 'peyvast-auth' ),
				'description' => esc_html__( 'Plugin status and lifecycle.', 'peyvast-auth' ),
				'icon'        => 'dashicons-admin-generic',
			),
			'registration'   => array(
				'label'       => esc_html__( 'Registration', 'peyvast-auth' ),
				'description' => esc_html__( 'Fields shown after mobile verification.', 'peyvast-auth' ),
				'icon'        => 'dashicons-id-alt',
			),
			'authentication' => array(
				'label'       => esc_html__( 'Authentication', 'peyvast-auth' ),
				'description' => esc_html__( 'OTP, password and reset methods.', 'peyvast-auth' ),
				'icon'        => 'dashicons-lock',
			),
			'otp'            => array(
				'label'       => esc_html__( 'OTP', 'peyvast-auth' ),
				'description' => esc_html__( 'Code length, expiry and cooldown.', 'peyvast-auth' ),
				'icon'        => 'dashicons-smartphone',
			),
			'phone'          => array(
				'label'       => esc_html__( 'Mobile', 'peyvast-auth' ),
				'description' => esc_html__( 'Phone normalization and identity sources.', 'peyvast-auth' ),
				'icon'        => 'dashicons-phone',
			),
			'migration'      => array(
				'label'       => esc_html__( 'Migration', 'peyvast-auth' ),
				'description' => esc_html__( 'Batch and optional lazy phone migration.', 'peyvast-auth' ),
				'icon'        => 'dashicons-update',
			),
			'woocommerce'    => array(
				'label'       => esc_html__( 'WooCommerce', 'peyvast-auth' ),
				'description' => esc_html__( 'WooCommerce synchronization and access rules.', 'peyvast-auth' ),
				'icon'        => 'dashicons-cart',
			),
			'providers'      => array(
				'label'       => esc_html__( 'Providers', 'peyvast-auth' ),
				'description' => esc_html__( 'SMS and email delivery configuration.', 'peyvast-auth' ),
				'icon'        => 'dashicons-cloud',
			),
			'security'       => array(
				'label'       => esc_html__( 'Security', 'peyvast-auth' ),
				'description' => esc_html__( 'Rate limits and abuse protection.', 'peyvast-auth' ),
				'icon'        => 'dashicons-shield',
			),
			'logging'        => array(
				'label'       => esc_html__( 'Logging', 'peyvast-auth' ),
				'description' => esc_html__( 'Diagnostics and log retention.', 'peyvast-auth' ),
				'icon'        => 'dashicons-list-view',
			),
			'integrations'   => array(
				'label'       => esc_html__( 'Integrations', 'peyvast-auth' ),
				'description' => esc_html__( 'Bricks and Block integration.', 'peyvast-auth' ),
				'icon'        => 'dashicons-admin-plugins',
			),
			'redirect'       => array(
				'label'       => esc_html__( 'Redirects', 'peyvast-auth' ),
				'description' => esc_html__( 'Post-login destinations.', 'peyvast-auth' ),
				'icon'        => 'dashicons-randomize',
			),
			'google'         => array(
				'label'       => esc_html__( 'Sign in with Google', 'peyvast-auth' ),
				'description' => esc_html__( 'Google sign-in.', 'peyvast-auth' ),
				'icon'        => 'dashicons-google',
			),
		);
	}

	private static function field( string $label, string $name, string $value, string $type = 'text', string $help = '', array $attrs = array() ): void {
		$conditionField = isset( $attrs['condition_field'] ) ? (string) $attrs['condition_field'] : '';
		$conditionValue = isset( $attrs['condition_value'] ) ? (string) $attrs['condition_value'] : '';
		unset( $attrs['condition_field'], $attrs['condition_value'] );
		$wrapper = 'peyvast-auth-field';
		if ( $conditionField !== '' ) {
			$wrapper .= ' peyvast-auth-conditional';
			echo '<div class="' . esc_attr( $wrapper ) . '" data-peyvast-auth-condition-field="' . esc_attr( $conditionField ) . '" data-peyvast-auth-condition-value="' . esc_attr( $conditionValue ) . '">';
		} else {
			echo '<div class="' . esc_attr( $wrapper ) . '">';
		}
		printf( '<div class="peyvast-auth-field__head"><label for="%1$s">%2$s</label></div><div class="peyvast-auth-field__control">', esc_attr( sanitize_title( $name ) ), esc_html( $label ) );
		if ( $type === 'select' ) {
			self::select( $name, $value, $attrs['options'] ?? array() );
		} elseif ( $type === 'textarea' ) {
			self::textarea( $name, $value );
		} else {
			self::input( $name, $value, $type, $attrs ); }
		if ( $help !== '' ) {
			printf( '<p class="peyvast-auth-help">%s</p>', esc_html( $help ) );
		}
		echo '</div></div>';
	}

	private static function input( string $name, string $value, string $type = 'text', array $attrs = array() ): void {
		$id    = sanitize_title( $name );
		$extra = '';
		foreach ( $attrs as $key => $attr ) {
			$extra .= ' ' . esc_attr( $key ) . '="' . esc_attr( (string) $attr ) . '"';
		}
		$classes = 'peyvast-auth-input';
		if ( $type === 'password' ) {
			$extra .= ' autocomplete="new-password" placeholder="' . esc_attr__( 'Leave empty to keep the saved value.', 'peyvast-auth' ) . '"';
		}
		printf( '<input class="%1$s" id="%2$s" name="%3$s" type="%4$s" value="%5$s"%6$s>', esc_attr( $classes ), esc_attr( $id ), esc_attr( $name ), esc_attr( $type ), $type === 'password' ? '' : esc_attr( $value ), $extra );
	}

	private static function select( string $name, string $value, array $options ): void {
		echo '<select class="peyvast-auth-select" name="' . esc_attr( $name ) . '">';
		foreach ( $options as $key => $label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $key ), selected( $value, $key, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	private static function toggle( string $name, bool $checked, string $title, string $help = '', string $disabled = '' ): void {
		$id            = sanitize_title( $name );
		$disabled_attr = $disabled !== '' ? ' disabled="disabled"' : '';
		echo '<label class="peyvast-auth-toggle" for="' . esc_attr( $id ) . '"><span class="peyvast-auth-toggle__copy">' . ( $title !== '' ? '<strong>' . esc_html( $title ) . '</strong>' : '' ) . ( $help !== '' ? '<small>' . esc_html( $help ) . '</small>' : '' ) . '</span><span class="peyvast-auth-switch"><input id="' . esc_attr( $id ) . '" type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( $checked, true, false ) . $disabled_attr . '><span class="peyvast-auth-switch__track"><span class="peyvast-auth-switch__thumb"></span></span></span></label>';
	}

	private static function textarea( string $name, string $value, string $help = '' ): void {
		echo '<textarea class="peyvast-auth-textarea" name="' . esc_attr( $name ) . '" rows="8">' . esc_textarea( $value ) . '</textarea>';
		if ( $help !== '' ) {
			echo '<p class="peyvast-auth-help">' . esc_html( $help ) . '</p>';
		}
	}

	private static function card_start( string $title, string $description = '' ): void {
		echo '<section class="peyvast-auth-card"><header class="peyvast-auth-card__header"><div><h3>' . esc_html( $title ) . '</h3>' . ( $description !== '' ? '<p>' . esc_html( $description ) . '</p>' : '' ) . '</div></header><div class="peyvast-auth-card__body">';
	}

	private static function card_end(): void {
		echo '</div></section>'; }

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'peyvast-auth' ) );
		}
		$settings = Settings::all();
		$tabs     = self::tabs();
		$active   = sanitize_key( $_GET['tab'] ?? 'general' );
		if ( ! isset( $tabs[ $active ] ) ) {
			$active = 'general';
		}
		?>
		<div class="wrap peyvast-auth-admin peyvast-auth-admin--settings" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>" data-peyvast-auth-admin>
			<header class="peyvast-auth-admin__header">
				<div class="peyvast-auth-admin__brand">
					<div class="peyvast-auth-admin__brand-mark"><span class="dashicons dashicons-shield-alt" aria-hidden="true"></span></div>
					<div class="peyvast-auth-admin__brand-copy"><span class="peyvast-auth-eyebrow"><?php echo esc_html__( 'Peyvast Authentication', 'peyvast-auth' ); ?></span><h1><?php echo esc_html__( 'Peyvast Authentication', 'peyvast-auth' ); ?></h1><p><?php echo esc_html__( 'Unified authentication, OTP delivery and builder integrations.', 'peyvast-auth' ); ?></p></div>
				</div>
				<div class="peyvast-auth-admin__status"><span class="peyvast-auth-status-dot <?php echo ! empty( $settings['general']['enabled'] ) ? 'is-on' : 'is-off'; ?>"></span><span><?php echo ! empty( $settings['general']['enabled'] ) ? esc_html__( 'Enabled', 'peyvast-auth' ) : esc_html__( 'Disabled', 'peyvast-auth' ); ?></span><span class="peyvast-auth-status-version">v<?php echo esc_html( PEYVAST_AUTH_VERSION ); ?></span></div>
			</header>

			<form id="peyvast-auth-settings-form" class="peyvast-auth-settings-form" data-peyvast-auth-settings-form>
				<div class="peyvast-auth-admin__layout">
					<aside class="peyvast-auth-sidebar" aria-label="<?php echo esc_attr__( 'Settings navigation', 'peyvast-auth' ); ?>">
						<div class="peyvast-auth-sidebar__section-title"><?php echo esc_html__( 'Configuration', 'peyvast-auth' ); ?></div>
						<nav class="peyvast-auth-sidebar__nav" role="tablist">
							<?php foreach ( $tabs as $key => $item ) : ?>
								<button type="button" class="peyvast-auth-nav-item <?php echo $active === $key ? 'is-active' : ''; ?>" data-peyvast-auth-tab="<?php echo esc_attr( $key ); ?>" data-description="<?php echo esc_attr( $item['description'] ); ?>" role="tab" aria-selected="<?php echo $active === $key ? 'true' : 'false'; ?>">
									<span class="peyvast-auth-nav-item__icon dashicons <?php echo esc_attr( $item['icon'] ); ?>" aria-hidden="true"></span>
									<span class="peyvast-auth-nav-item__copy"><strong><?php echo esc_html( $item['label'] ); ?></strong></span>
								</button>
							<?php endforeach; ?>
						</nav>
						<a class="peyvast-auth-sidebar__utility" href="<?php echo esc_url( admin_url( 'admin.php?page=peyvast-auth-logs' ) ); ?>"><span class="dashicons dashicons-list-view" aria-hidden="true"></span><span><?php echo esc_html__( 'Operational Logs', 'peyvast-auth' ); ?></span></a>
					</aside>

					<main class="peyvast-auth-content">
						<div class="peyvast-auth-content__topbar">
							<div><span class="peyvast-auth-content__kicker"><?php echo esc_html__( 'Settings', 'peyvast-auth' ); ?></span><h2 data-peyvast-auth-current-title><?php echo esc_html( $tabs[ $active ]['label'] ); ?></h2><p data-peyvast-auth-current-description><?php echo esc_html( $tabs[ $active ]['description'] ); ?></p></div>
							<div class="peyvast-auth-content__actions"><span class="peyvast-auth-save-state" data-peyvast-auth-save-state aria-live="polite"></span><button type="submit" form="peyvast-auth-settings-form" class="peyvast-auth-button peyvast-auth-button--primary peyvast-auth-button--save" data-peyvast-auth-save data-peyvast-auth-save-label aria-busy="false"><span class="dashicons dashicons-update peyvast-auth-save-icon peyvast-auth-save-icon--loading" aria-hidden="true"></span><span class="dashicons dashicons-saved peyvast-auth-save-icon peyvast-auth-save-icon--success" aria-hidden="true"></span><span class="peyvast-auth-save-label"><?php echo esc_html__( 'Save changes', 'peyvast-auth' ); ?></span></button></div>
						</div>

						<?php foreach ( $tabs as $key => $item ) : ?>
							<section class="peyvast-auth-tab-panel <?php echo $active === $key ? 'is-active' : ''; ?>" data-peyvast-auth-panel="<?php echo esc_attr( $key ); ?>" role="tabpanel" aria-hidden="<?php echo $active === $key ? 'false' : 'true'; ?>"<?php echo $active === $key ? '' : ' hidden'; ?>>
								<?php self::render_tab( $key, $settings ); ?>
							</section>
						<?php endforeach; ?>
						<div class="peyvast-auth-content__footer"><span class="peyvast-auth-savebar__hint"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><?php echo esc_html__( 'Changes across all sections are kept in one form and saved together.', 'peyvast-auth' ); ?></span></div>
					</main>
				</div>
			</form>
		</div>
		<?php
	}

	private static function render_tab( string $tab, array $s ): void {
		switch ( $tab ) {
			case 'general':
				self::card_start( __( 'Plugin status', 'peyvast-auth' ), __( 'Enable or disable the authentication service.', 'peyvast-auth' ) );
				self::toggle( 'peyvast_auth_settings[general][enabled]', ! empty( $s['general']['enabled'] ), __( 'Enable authentication', 'peyvast-auth' ) );
				self::toggle( 'peyvast_auth_settings[general][delete_data_on_uninstall]', ! empty( $s['general']['delete_data_on_uninstall'] ), __( 'Delete plugin data on uninstall', 'peyvast-auth' ), __( 'Permanently removes plugin tables and settings on uninstall.', 'peyvast-auth' ) );
				self::card_end();
				break;
			case 'registration':
				self::card_start( __( 'Registration fields', 'peyvast-auth' ), __( 'Shown only after OTP verification.', 'peyvast-auth' ) );
				self::toggle( 'peyvast_auth_settings[registration][enabled]', ! empty( $s['registration']['enabled'] ), __( 'Allow new user registration', 'peyvast-auth' ) );
				echo '<div class="peyvast-auth-grid peyvast-auth-grid--2">';
				self::field(
					__( 'Username generation', 'peyvast-auth' ),
					'peyvast_auth_settings[registration][username_generation]',
					(string) $s['registration']['username_generation'],
					'select',
					__( 'Random creates an independent username. Phone uses the normalized phone representation for new users only.', 'peyvast-auth' ),
					array(
						'options' => array(
							'random' => __( 'Random', 'peyvast-auth' ),
							'phone'  => __( 'Phone', 'peyvast-auth' ),
						),
					)
				);
				self::field(
					__( 'Phone username format', 'peyvast-auth' ),
					'peyvast_auth_settings[registration][username_phone_format]',
					(string) $s['registration']['username_phone_format'],
					'select',
					__( 'Used only when Phone username generation is selected.', 'peyvast-auth' ),
					array(
						'options'         => array(
							'leading_zero' => __( 'With 0', 'peyvast-auth' ),
							'country_code' => __( 'With country code', 'peyvast-auth' ),
							'raw'          => __( 'Raw', 'peyvast-auth' ),
						),
						'condition_field' => 'peyvast_auth_settings[registration][username_generation]',
						'condition_value' => 'phone',
					)
				);
				echo '</div>';
				echo '<div class="peyvast-auth-field-table"><div class="peyvast-auth-field-table__head"><span>' . esc_html__( 'Field', 'peyvast-auth' ) . '</span><span>' . esc_html__( 'Visible', 'peyvast-auth' ) . '</span><span>' . esc_html__( 'Required', 'peyvast-auth' ) . '</span></div>';
				$labels = array(
					'first_name' => __( 'First name', 'peyvast-auth' ),
					'last_name'  => __( 'Last name', 'peyvast-auth' ),
					'email'     => __( 'Email', 'peyvast-auth' ),
					'password'  => __( 'Password', 'peyvast-auth' ),
				);
				foreach ( $labels as $key => $label ) {
					echo '<div class="peyvast-auth-field-table__row"><div><strong>' . esc_html__( $label, 'peyvast-auth' ) . '</strong><span>' . esc_html__( 'Built-in registration field.', 'peyvast-auth' ) . '</span></div><div>';
					self::toggle( 'peyvast_auth_settings[registration][fields][' . $key . '][enabled]', ! empty( $s['registration']['fields'][ $key ]['enabled'] ), '' );
					echo '</div><div>';
					self::toggle( 'peyvast_auth_settings[registration][fields][' . $key . '][required]', ! empty( $s['registration']['fields'][ $key ]['required'] ), '', '' );
					echo '</div></div>'; }
				echo '</div>';
				self::card_end();
				break;
			case 'authentication':
				self::card_start( __( 'OTP sign-in', 'peyvast-auth' ), __( 'Email OTP only signs in existing accounts.', 'peyvast-auth' ) );
				self::toggle( 'peyvast_auth_settings[authentication][otp_login][email]', ! empty( $s['authentication']['otp_login']['email'] ), __( 'Allow OTP login with email', 'peyvast-auth' ) );
				self::toggle( 'peyvast_auth_settings[authentication][otp_email_for_phone]', ! empty( $s['authentication']['otp_email_for_phone'] ), __( 'Send a second OTP to the user email when phone login is used', 'peyvast-auth' ), __( "Phone OTP is always sent by SMS. When enabled, a second OTP is also sent to the user's email.", 'peyvast-auth' ) );
				self::card_end();
				self::card_start( __( 'Password sign-in', 'peyvast-auth' ), __( 'Accepts configured identifiers independently.', 'peyvast-auth' ) );
				self::toggle( 'peyvast_auth_settings[authentication][password_login][enabled]', ! empty( $s['authentication']['password_login']['enabled'] ), __( 'Enable password sign-in', 'peyvast-auth' ) );
				self::toggle( 'peyvast_auth_settings[authentication][password_login][phone]', ! empty( $s['authentication']['password_login']['phone'] ), __( 'Allow phone as password identifier', 'peyvast-auth' ) );
				self::toggle( 'peyvast_auth_settings[authentication][password_login][email]', ! empty( $s['authentication']['password_login']['email'] ), __( 'Allow email as password identifier', 'peyvast-auth' ) );
				self::toggle( 'peyvast_auth_settings[authentication][password_login][user_login]', ! empty( $s['authentication']['password_login']['user_login'] ), __( 'Allow user login as password identifier', 'peyvast-auth' ) );
				self::card_end();
				self::card_start( __( 'Password reset', 'peyvast-auth' ), __( 'Password reset identifiers are configured independently from password sign-in.', 'peyvast-auth' ) );
				self::toggle( 'peyvast_auth_settings[authentication][password_reset][enabled]', ! empty( $s['authentication']['password_reset']['enabled'] ), __( 'Enable password reset', 'peyvast-auth' ) );
				self::toggle( 'peyvast_auth_settings[authentication][password_reset][phone]', ! empty( $s['authentication']['password_reset']['phone'] ), __( 'Allow reset with phone', 'peyvast-auth' ) );
				self::toggle( 'peyvast_auth_settings[authentication][password_reset][email]', ! empty( $s['authentication']['password_reset']['email'] ), __( 'Allow reset with email', 'peyvast-auth' ) );
				self::toggle( 'peyvast_auth_settings[authentication][password_reset][email_copy_for_phone]', ! empty( $s['authentication']['password_reset']['email_copy_for_phone'] ), __( 'Also send reset OTP to email when phone reset is used', 'peyvast-auth' ) );
				self::card_end();
				break;
			case 'otp':
				self::card_start( __( 'OTP behavior', 'peyvast-auth' ), __( 'Cooldown applies per session; the first stage shows no timer.', 'peyvast-auth' ) );
				echo '<div class="peyvast-auth-grid peyvast-auth-grid--2">';
				self::field(
					__( 'OTP length', 'peyvast-auth' ),
					'peyvast_auth_settings[otp][length]',
					(string) $s['otp']['length'],
					'number',
					'',
					array(
						'min'  => 4,
						'max'  => 8,
						'step' => 1,
					)
				);
				self::field(
					__( 'Resend cooldown (seconds)', 'peyvast-auth' ),
					'peyvast_auth_settings[otp][resend_cooldown]',
					(string) $s['otp']['resend_cooldown'],
					'number',
					__( 'Returning with the same identifier restores the active session.', 'peyvast-auth' ),
					array(
						'min'  => 10,
						'max'  => 3600,
						'step' => 1,
					)
				);
				self::field(
					__( 'OTP validity (seconds)', 'peyvast-auth' ),
					'peyvast_auth_settings[otp][expiration_seconds]',
					(string) $s['otp']['expiration_seconds'],
					'number',
					__( 'Stored in seconds; the frontend shows minutes.', 'peyvast-auth' ),
					array(
						'min'  => 30,
						'max'  => 86400,
						'step' => 1,
					)
				);
				echo '</div>';
				self::toggle( 'peyvast_auth_settings[otp][web_otp_enabled]', ! empty( $s['otp']['web_otp_enabled'] ), __( 'Enable browser OTP autofill', 'peyvast-auth' ), __( 'Uses the standard one-time-code autocomplete; autofill depends on browser support.', 'peyvast-auth' ) );
				echo '<p class="peyvast-auth-help">' . esc_html__( 'For automatic one-time code filling, your message must include the code after the domain; for example: @website.com #Code.', 'peyvast-auth' ) . '</p>';
				self::card_end();
				break;
			case 'phone':
				self::card_start( __( 'Mobile number normalization', 'peyvast-auth' ), __( 'Peyvast stores its own phone value in E.164 semantics. WooCommerce may keep its own configurable display format.', 'peyvast-auth' ) );
				echo '<div class="peyvast-auth-grid peyvast-auth-grid--2">';
				self::field( __( 'Country', 'peyvast-auth' ), 'peyvast_auth_settings[phone][country]', (string) ( $s['phone']['country'] ?? 'ir' ), 'select', '', array( 'options' => self::country_options() ) );
				echo '</div>';
				echo '<p class="peyvast-auth-help">' . esc_html__( 'Persian and Arabic digits are always normalized to ASCII digits before validation and identity lookup. This is a fixed security invariant and is not configurable.', 'peyvast-auth' ) . '</p>';
				self::card_end();
				break;
			case 'migration':
				self::card_start( __( 'Legacy Phone Identity Source', 'peyvast-auth' ), __( 'Recognize users created by a previous authentication system. Peyvast phone data remains the primary identity source.', 'peyvast-auth' ) );
				$source_type           = (string) ( $s['migration']['phone_source_type'] ?? 'off' );
				$phone_source_meta_key = (string) ( $s['migration']['phone_source_meta_key'] ?? '' );
				$meta_keys             = self::phone_meta_keys();
				echo '<div class="peyvast-auth-field"><div class="peyvast-auth-field__head"><label for="peyvast-auth-phone-source-type">' . esc_html__( 'Legacy Phone Identity Source', 'peyvast-auth' ) . '</label></div><div class="peyvast-auth-field__control"><select class="peyvast-auth-select" id="peyvast-auth-phone-source-type" name="peyvast_auth_settings[migration][phone_source_type]" data-peyvast-auth-phone-source-type><option value="off" ' . selected( $source_type, 'off', false ) . '>' . esc_html__( 'Off', 'peyvast-auth' ) . '</option><option value="existing_meta" ' . selected( $source_type, 'existing_meta', false ) . '>' . esc_html__( 'Existing user meta', 'peyvast-auth' ) . '</option><option value="manual_meta" ' . selected( $source_type, 'manual_meta', false ) . '>' . esc_html__( 'Manual user meta key', 'peyvast-auth' ) . '</option></select><p class="peyvast-auth-help">' . esc_html__( 'Allows recognition of users created by a previous authentication system that stored mobile numbers in a custom user meta field.', 'peyvast-auth' ) . '</p></div></div>';
				echo '<div class="peyvast-auth-field peyvast-auth-conditional" data-peyvast-auth-phone-source-field="existing_meta"><div class="peyvast-auth-field__head"><label for="peyvast-auth-phone-source-meta-existing">' . esc_html__( 'Existing user meta', 'peyvast-auth' ) . '</label></div><div class="peyvast-auth-field__control"><select class="peyvast-auth-select" id="peyvast-auth-phone-source-meta-existing" name="peyvast_auth_settings[migration][phone_source_meta_key]" data-peyvast-auth-phone-meta-existing><option value="">' . esc_html__( 'Select a user meta key', 'peyvast-auth' ) . '</option>';
				foreach ( $meta_keys as $meta_key ) {
					printf( '<option value="%1$s" %2$s>%1$s</option>', esc_attr( $meta_key ), selected( $source_type === 'existing_meta' ? $phone_source_meta_key : '', $meta_key, false ) );
				}
				echo '</select><p class="peyvast-auth-help">' . esc_html__( 'Only populated user meta keys are listed here.', 'peyvast-auth' ) . '</p></div></div>';
				echo '<div class="peyvast-auth-field peyvast-auth-conditional" data-peyvast-auth-phone-source-field="manual_meta"><div class="peyvast-auth-field__head"><label for="peyvast-auth-phone-source-meta-manual">' . esc_html__( 'Manual user meta key', 'peyvast-auth' ) . '</label></div><div class="peyvast-auth-field__control">';
				self::input(
					'peyvast_auth_settings[migration][phone_source_meta_key]',
					$source_type === 'manual_meta' ? $phone_source_meta_key : '',
					'text',
					array(
						'data-peyvast-auth-phone-meta-manual' => '1',
						'autocomplete'                     => 'off',
					)
				);
				echo '<p class="peyvast-auth-help">' . esc_html__( 'Enter the exact meta key used by the previous authentication system.', 'peyvast-auth' ) . '</p></div></div>';
				echo '<div class="peyvast-auth-source-actions"><button type="button" class="peyvast-auth-button peyvast-auth-button--secondary" data-peyvast-auth-validate-phone-meta>' . esc_html__( 'Validate User Meta', 'peyvast-auth' ) . '</button><span class="peyvast-auth-inline-result" data-peyvast-auth-phone-meta-result role="status" aria-live="polite"></span></div>';
				self::card_end();
				$job            = PhoneMigrationService::get_status();
				$source_summary = Settings::phone_source();
				echo '<div class="peyvast-auth-source-summary peyvast-auth-source-summary--top"><span class="peyvast-auth-source-summary__icon dashicons dashicons-info-outline" aria-hidden="true"></span><div class="peyvast-auth-source-summary__content"><strong>' . esc_html__( 'Current phone source', 'peyvast-auth' ) . '</strong><p>' . esc_html( $source_summary['type'] === 'user_meta' ? ( $source_summary['key'] !== '' ? sprintf( __( 'Legacy source %s; user_login is the tertiary fallback source for users without a valid Peyvast Mobile Number.', 'peyvast-auth' ), $source_summary['key'] ) : __( 'Legacy source is configured; user_login is the tertiary fallback source for users without a valid Peyvast Mobile Number.', 'peyvast-auth' ) ) : __( 'Legacy source disabled; user_login is the fallback source for users without a valid Peyvast Mobile Number.', 'peyvast-auth' ) ) . '</p><p class="peyvast-auth-help peyvast-auth-migration-source-note">' . wp_kses_post( __( 'Migration will skip users who already have a valid phone number in <strong>Peyvast Mobile Number</strong>.', 'peyvast-auth' ) ) . '</p></div></div>';
				self::card_start( __( 'Batch Migration', 'peyvast-auth' ), __( 'Use the configured phone source to populate Peyvast phone data for existing users. Batch work runs in the background and can be resumed.', 'peyvast-auth' ) );
				echo '<div class="peyvast-auth-migration-tool" data-peyvast-auth-migration-box="default"><div><strong>' . esc_html__( 'Phone migration', 'peyvast-auth' ) . '</strong><p>' . esc_html__( 'Migrates the current phone source into the Peyvast phone field. WooCommerce migration is intentionally not part of this process. Create a database backup before starting.', 'peyvast-auth' ) . '</p></div><button type="button" class="peyvast-auth-button peyvast-auth-button--secondary" data-peyvast-auth-migrate-user>' . esc_html__( 'Run Batch Migration', 'peyvast-auth' ) . '</button><div class="peyvast-auth-migration-notice" data-peyvast-auth-migration-notice="default" hidden role="status" aria-live="polite" tabindex="-1"><span class="peyvast-auth-migration-icons" aria-hidden="true"><span class="dashicons dashicons-info-outline" data-peyvast-auth-migration-icon="info"></span><span class="dashicons dashicons-update" data-peyvast-auth-migration-icon="loading" hidden></span><span class="dashicons dashicons-yes-alt" data-peyvast-auth-migration-icon="success" hidden></span><span class="dashicons dashicons-warning" data-peyvast-auth-migration-icon="warning" hidden></span><span class="dashicons dashicons-dismiss" data-peyvast-auth-migration-icon="error" hidden></span></span><div><strong>' . esc_html__( 'Migration status', 'peyvast-auth' ) . '</strong><p data-peyvast-auth-migration-message></p></div><button type="button" class="peyvast-auth-migration-close" data-peyvast-auth-migration-close hidden aria-label="' . esc_attr__( 'Dismiss migration notice', 'peyvast-auth' ) . '"><span class="dashicons dashicons-dismiss" aria-hidden="true"></span></button></div><div class="peyvast-auth-migration-progress" data-peyvast-auth-migration-progress hidden role="progressbar" aria-label="' . esc_attr__( 'Migration progress', 'peyvast-auth' ) . '" aria-valuemin="0" aria-valuemax="0" aria-valuenow="0"></div><dl class="peyvast-auth-migration-counters" data-peyvast-auth-migration-counters hidden><div><dt>' . esc_html__( 'Processed', 'peyvast-auth' ) . '</dt><dd data-counter="processed">0</dd></div><div><dt>' . esc_html__( 'Migrated', 'peyvast-auth' ) . '</dt><dd data-counter="migrated">0</dd></div><div><dt>' . esc_html__( 'Already current', 'peyvast-auth' ) . '</dt><dd data-counter="already_current">0</dd></div><div><dt>' . esc_html__( 'Missing', 'peyvast-auth' ) . '</dt><dd data-counter="missing">0</dd></div><div><dt>' . esc_html__( 'Invalid', 'peyvast-auth' ) . '</dt><dd data-counter="invalid">0</dd></div><div><dt>' . esc_html__( 'Conflicts', 'peyvast-auth' ) . '</dt><dd data-counter="conflict">0</dd></div><div><dt>' . esc_html__( 'Failed', 'peyvast-auth' ) . '</dt><dd data-counter="failed">0</dd></div><div><dt>' . esc_html__( 'Skipped', 'peyvast-auth' ) . '</dt><dd data-counter="skipped">0</dd></div></dl></div>';
				self::card_end();
				self::card_start( __( 'Lazy Migration', 'peyvast-auth' ), __( 'Optional. When enabled, a successful authentication migrates only the current user using the same PhoneMigrationEngine.', 'peyvast-auth' ) );
				self::toggle( 'peyvast_auth_settings[migration][lazy_enabled]', ! empty( $s['migration']['lazy_enabled'] ), __( 'Enable Lazy Migration', 'peyvast-auth' ), __( 'Queued after successful OTP, password or Google authentication and runs in the background without delaying the login response.', 'peyvast-auth' ) );
				self::card_end();
				break;
			case 'woocommerce':
				self::card_start( __( 'WooCommerce integration', 'peyvast-auth' ), __( 'Each WooCommerce behavior is controlled independently. The existing Login page setting is used as the destination.', 'peyvast-auth' ) );
				$ready = \Peyvast\Auth\Integrations\WooCommerce\Integration::is_available();
				echo '<div class="peyvast-auth-integration-banner ' . ( $ready ? 'is-ready' : 'is-missing' ) . '"><span class="dashicons ' . ( $ready ? 'dashicons-yes-alt' : 'dashicons-warning' ) . '" aria-hidden="true"></span><div><strong>' . esc_html( $ready ? __( 'WooCommerce detected', 'peyvast-auth' ) : __( 'WooCommerce is not active', 'peyvast-auth' ) ) . '</strong><p>' . esc_html( $ready ? __( 'WooCommerce customer and checkout integration is available.', 'peyvast-auth' ) : __( 'The settings will activate automatically when WooCommerce is installed.', 'peyvast-auth' ) ) . '</p></div></div>';
				self::toggle( 'peyvast_auth_settings[woocommerce][my_account_redirect]', ! empty( $s['woocommerce']['my_account_redirect'] ), __( 'Redirect logged-out visitors from My Account', 'peyvast-auth' ) );
				self::toggle( 'peyvast_auth_settings[woocommerce][guest_checkout_redirect]', ! empty( $s['woocommerce']['guest_checkout_redirect'] ), __( 'Redirect logged-out visitors when guest checkout is disabled', 'peyvast-auth' ) );
				self::toggle( 'peyvast_auth_settings[woocommerce][guest_checkout_notice]', ! empty( $s['woocommerce']['guest_checkout_notice'] ), __( 'Show the guest-checkout login notice on the authentication page', 'peyvast-auth' ), __( 'The notice is shown after redirecting to the selected Login page.', 'peyvast-auth' ) );
				self::toggle( 'peyvast_auth_settings[woocommerce][order_receipt_redirect]', ! empty( $s['woocommerce']['order_receipt_redirect'] ), __( 'Redirect logged-out visitors from protected order receipts', 'peyvast-auth' ) );
				self::toggle( 'peyvast_auth_settings[woocommerce][order_receipt_notice]', ! empty( $s['woocommerce']['order_receipt_notice'] ), __( 'Show the order-receipt login notice on the authentication page', 'peyvast-auth' ), __( 'Only receipts belonging to a registered user are protected.', 'peyvast-auth' ) );
				self::card_end();
				self::card_start( __( 'WooCommerce customer data', 'peyvast-auth' ), __( 'Save selected WordPress customer data to both billing and shipping fields.', 'peyvast-auth' ) );
				self::toggle( 'peyvast_auth_settings[woocommerce][save_name]', ! empty( $s['woocommerce']['save_name'] ), __( 'Save first and last name to billing and shipping', 'peyvast-auth' ) );
				self::toggle( 'peyvast_auth_settings[woocommerce][save_email]', ! empty( $s['woocommerce']['save_email'] ), __( 'Save email to billing and shipping', 'peyvast-auth' ) );
				self::toggle( 'peyvast_auth_settings[woocommerce][save_phone]', ! empty( $s['woocommerce']['save_phone'] ), __( 'Save phone to billing and shipping', 'peyvast-auth' ) );
				self::field(
					__( 'WooCommerce phone storage format', 'peyvast-auth' ),
					'peyvast_auth_settings[woocommerce][phone_storage_format]',
					(string) $s['woocommerce']['phone_storage_format'],
					'select',
					__( 'The canonical phone option controls both billing and shipping phone fields.', 'peyvast-auth' ),
					array(
						'options' => array(
							'leading_zero' => __( 'Leading 0', 'peyvast-auth' ),
							'country_code' => __( 'Country code', 'peyvast-auth' ),
							'raw'          => __( 'Raw', 'peyvast-auth' ),
						),
					)
				);
				self::card_end();
				break;
			case 'providers':
				self::render_providers( $s );
				break;
			case 'security':
				self::card_start( __( 'General protection', 'peyvast-auth' ), __( 'One server-side policy for every authentication endpoint.', 'peyvast-auth' ) );
				self::toggle( 'peyvast_auth_settings[security][protection_enabled]', ! empty( $s['security']['protection_enabled'] ), __( 'Enable security protection', 'peyvast-auth' ), __( 'When disabled, the Guard does not enforce limits or create security state; authentication continues normally.', 'peyvast-auth' ) );
				echo '<p class="peyvast-auth-help">' . esc_html__( 'In WordPress Multi-Site, security settings and blocks are independent per site.', 'peyvast-auth' ) . '</p>';
				echo '<div class="peyvast-auth-grid peyvast-auth-grid--2">';
				self::field(
					__( 'General request limit', 'peyvast-auth' ),
					'peyvast_auth_settings[security][request_limit]',
					(string) min( 1000, (int) $s['security']['request_limit'] ),
					'number',
					__( 'Applies to OTP sending, password sign-in, reset and identifier lookups.', 'peyvast-auth' ),
					array(
						'min'  => 1,
						'max'  => 1000,
						'step' => 1,
					)
				);
				self::field(
					__( 'General request window (seconds)', 'peyvast-auth' ),
					'peyvast_auth_settings[security][request_window]',
					(string) min( 86400, (int) $s['security']['request_window'] ),
					'number',
					'',
					array(
						'min'  => 1,
						'max'  => 86400,
						'step' => 1,
					)
				);
				self::field(
					__( 'General verification limit', 'peyvast-auth' ),
					'peyvast_auth_settings[security][verify_limit]',
					(string) min( 1000, (int) $s['security']['verify_limit'] ),
					'number',
					__( 'Applies to every OTP code verification attempt, including the maximum attempts allowed for each code.', 'peyvast-auth' ),
					array(
						'min'  => 1,
						'max'  => 1000,
						'step' => 1,
					)
				);
				self::field(
					__( 'General verification window (seconds)', 'peyvast-auth' ),
					'peyvast_auth_settings[security][verify_window]',
					(string) min( 86400, (int) $s['security']['verify_window'] ),
					'number',
					'',
					array(
						'min'  => 1,
						'max'  => 86400,
						'step' => 1,
					)
				);
				echo '</div>';
				self::card_end();

				self::card_start( __( 'Network protection', 'peyvast-auth' ), __( 'Applies limits per request IP and per network range (IPv4 /24, IPv6 /64).', 'peyvast-auth' ) );
				self::field(
					__( 'IP multiplier', 'peyvast-auth' ),
					'peyvast_auth_settings[security][ip_multiplier]',
					(string) $s['security']['ip_multiplier'],
					'number',
					__( 'Raises or lowers the per-IP limits (3 = baseline).', 'peyvast-auth' ),
					array(
						'min'  => 1,
						'max'  => 10,
						'step' => 1,
					)
				);
				self::card_end();

				self::card_start( __( 'Progressive protection', 'peyvast-auth' ), __( 'Blocks escalate for repeat violations.', 'peyvast-auth' ) );
				self::toggle( 'peyvast_auth_settings[security][progressive][enabled]', ! empty( $s['security']['progressive']['enabled'] ), __( 'Enable progressive protection', 'peyvast-auth' ) );
				echo '<div class="peyvast-auth-grid peyvast-auth-grid--2">';
				self::field(
					__( 'Initial block duration (seconds)', 'peyvast-auth' ),
					'peyvast_auth_settings[security][progressive][initial_duration]',
					(string) $s['security']['progressive']['initial_duration'],
					'number',
					'',
					array(
						'min'  => 1,
						'max'  => 86400,
						'step' => 1,
					)
				);
				self::field(
					__( 'Duration multiplier', 'peyvast-auth' ),
					'peyvast_auth_settings[security][progressive][multiplier]',
					(string) $s['security']['progressive']['multiplier'],
					'number',
					'',
					array(
						'min'  => 2,
						'max'  => 10,
						'step' => 1,
					)
				);
				self::field(
					__( 'Maximum block duration (seconds)', 'peyvast-auth' ),
					'peyvast_auth_settings[security][progressive][max_duration]',
					(string) $s['security']['progressive']['max_duration'],
					'number',
					'',
					array(
						'min'  => 1,
						'max'  => 86400,
						'step' => 1,
					)
				);
				self::field(
					__( 'Recovery duration (seconds)', 'peyvast-auth' ),
					'peyvast_auth_settings[security][progressive][decay_seconds]',
					(string) $s['security']['progressive']['decay_seconds'],
					'number',
					__( 'After this clean period, the next block starts again from the initial duration.', 'peyvast-auth' ),
					array(
						'min'  => 1,
						'max'  => 86400,
						'step' => 1,
					)
				);
				echo '</div>';
				self::card_end();

				self::card_start( __( 'Active security blocks', 'peyvast-auth' ), __( 'Clears blocks, progressive state and rate-limit counters.', 'peyvast-auth' ) );
				echo '<div class="peyvast-auth-migration-tool"><div><strong>' . esc_html__( 'Clear all security blocks', 'peyvast-auth' ) . '</strong><p>' . esc_html__( 'Removes active blocks, progressive state and rate-limit counters. Security settings are preserved.', 'peyvast-auth' ) . '</p></div><button type="button" class="peyvast-auth-button peyvast-auth-button--secondary" data-peyvast-auth-clear-security>' . esc_html__( 'Clear all security blocks', 'peyvast-auth' ) . '</button><div class="peyvast-auth-migration-result" data-peyvast-auth-clear-security-result role="status" aria-live="polite"></div></div>';
				self::card_end();
				break;
			case 'logging':
				self::card_start( __( 'Operational logging', 'peyvast-auth' ), __( 'Troubleshooting diagnostics are disabled by default. Enable them only when needed; logging increases database usage.', 'peyvast-auth' ) );
				self::toggle( 'peyvast_auth_settings[logging][enabled]', ! empty( $s['logging']['enabled'] ), __( 'Enable logging', 'peyvast-auth' ) );
				self::field(
					__( 'Minimum log level', 'peyvast-auth' ),
					'peyvast_auth_settings[logging][minimum_level]',
					(string) $s['logging']['minimum_level'],
					'select',
					__( 'Only events at or above this level are stored.', 'peyvast-auth' ),
					array(
						'options' => array(
							'debug'    => __( 'Debug', 'peyvast-auth' ),
							'info'     => __( 'Info', 'peyvast-auth' ),
							'notice'   => __( 'Notice', 'peyvast-auth' ),
							'warning'  => __( 'Warning', 'peyvast-auth' ),
							'error'    => __( 'Error', 'peyvast-auth' ),
							'critical' => __( 'Critical', 'peyvast-auth' ),
						),
					)
				);
				self::field(
					__( 'Retention (days)', 'peyvast-auth' ),
					'peyvast_auth_settings[logging][retention_days]',
					(string) $s['logging']['retention_days'],
					'number',
					'',
					array(
						'min'  => 1,
						'max'  => 365,
						'step' => 1,
					)
				);
				self::card_end();
				break;
			case 'integrations':
				self::card_start( __( 'Builder integrations', 'peyvast-auth' ), __( 'Bricks and Blocks only add presentation and editor controls.', 'peyvast-auth' ) );
				self::toggle( 'peyvast_auth_settings[integrations][bricks]', ! empty( $s['integrations']['bricks'] ), __( 'Enable Bricks integration', 'peyvast-auth' ) );
				self::toggle( 'peyvast_auth_settings[integrations][blocks]', ! empty( $s['integrations']['blocks'] ), __( 'Enable WordPress Blocks integration', 'peyvast-auth' ) );
				self::card_end();
				break;
			case 'redirect':
				self::card_start( __( 'Redirect after login or registration', 'peyvast-auth' ), __( 'Choose where signed-in users go after login or registration.', 'peyvast-auth' ) );
				self::field(
					__( 'Options', 'peyvast-auth' ),
					'peyvast_auth_settings[redirect][mode]',
					(string) $s['redirect']['mode'],
					'select',
					__( 'Choose how successful authentication should redirect the user.', 'peyvast-auth' ),
					array(
						'options' => array(
							'origin'  => __( 'Return to origin', 'peyvast-auth' ),
							'page'    => __( 'Specific page', 'peyvast-auth' ),
							'custom'  => __( 'Custom URL', 'peyvast-auth' ),
							'disable' => __( 'Disable', 'peyvast-auth' ),
						),
					)
				);
				echo '<div class="peyvast-auth-field peyvast-auth-conditional" data-peyvast-auth-condition-field="peyvast_auth_settings[redirect][mode]" data-peyvast-auth-condition-value="page">';
				echo '<div class="peyvast-auth-field__head"><label>' . esc_html__( 'Destination page', 'peyvast-auth' ) . '</label></div><div class="peyvast-auth-field__control">';
				wp_dropdown_pages(
					array(
						'name'              => 'peyvast_auth_settings[redirect][page_id]',
						'selected'          => (int) $s['redirect']['page_id'],
						'show_option_none'  => esc_html__( 'Select a page', 'peyvast-auth' ),
						'option_none_value' => '',
						'class'             => 'peyvast-auth-select',
					)
				);
				echo '</div></div>';
				self::field(
					__( 'Custom URL', 'peyvast-auth' ),
					'peyvast_auth_settings[redirect][custom_url]',
					(string) $s['redirect']['custom_url'],
					'url',
					__( 'Only internal site URLs are accepted.', 'peyvast-auth' ),
					array(
						'condition_field' => 'peyvast_auth_settings[redirect][mode]',
						'condition_value' => 'custom',
					)
				);

				echo '<div class="peyvast-auth-conditional" data-peyvast-auth-condition-field="peyvast_auth_settings[redirect][mode]" data-peyvast-auth-condition-value="origin">';
				echo '<div class="peyvast-auth-section-heading"><strong>' . esc_html__( 'Origin fallback', 'peyvast-auth' ) . '</strong><p class="peyvast-auth-help">' . esc_html__( 'Used only when the original destination cannot be restored.', 'peyvast-auth' ) . '</p></div>';
				self::field(
					__( 'Fallback mode', 'peyvast-auth' ),
					'peyvast_auth_settings[redirect][fallback_mode]',
					(string) $s['redirect']['fallback_mode'],
					'select',
					__( 'Choose a fallback destination.', 'peyvast-auth' ),
					array(
						'options' => array(
							'home'    => __( 'Homepage', 'peyvast-auth' ),
							'page'    => __( 'Specific page', 'peyvast-auth' ),
							'custom'  => __( 'Custom URL', 'peyvast-auth' ),
							'disable' => __( 'Disable', 'peyvast-auth' ),
						),
					)
				);
				echo '<div class="peyvast-auth-field peyvast-auth-conditional" data-peyvast-auth-condition-field="peyvast_auth_settings[redirect][fallback_mode]" data-peyvast-auth-condition-value="page">';
				echo '<div class="peyvast-auth-field__head"><label>' . esc_html__( 'Fallback page', 'peyvast-auth' ) . '</label></div><div class="peyvast-auth-field__control">';
				wp_dropdown_pages(
					array(
						'name'              => 'peyvast_auth_settings[redirect][fallback_page_id]',
						'selected'          => (int) $s['redirect']['fallback_page_id'],
						'show_option_none'  => esc_html__( 'Select a page', 'peyvast-auth' ),
						'option_none_value' => '',
						'class'             => 'peyvast-auth-select',
					)
				);
				echo '</div></div>';
				self::field(
					__( 'Fallback custom URL', 'peyvast-auth' ),
					'peyvast_auth_settings[redirect][fallback_custom_url]',
					(string) $s['redirect']['fallback_custom_url'],
					'url',
					__( 'Only internal site URLs are accepted.', 'peyvast-auth' ),
					array(
						'condition_field' => 'peyvast_auth_settings[redirect][fallback_mode]',
						'condition_value' => 'custom',
					)
				);
				echo '</div>';
				self::card_end();

				self::card_start( __( 'Redirect after logout', 'peyvast-auth' ), __( 'Configure WooCommerce and WordPress logout destinations independently.', 'peyvast-auth' ) );
				self::field(
					__( 'WooCommerce logout', 'peyvast-auth' ),
					'peyvast_auth_settings[redirect][woocommerce_logout_mode]',
					(string) $s['redirect']['woocommerce_logout_mode'],
					'select',
					__( 'Destination used after the WooCommerce customer logout flow. Disable preserves WooCommerce default behavior.', 'peyvast-auth' ),
					array( 'options' => array( 'home' => __( 'Homepage', 'peyvast-auth' ), 'page' => __( 'Specific page', 'peyvast-auth' ), 'custom' => __( 'Custom URL', 'peyvast-auth' ), 'disable' => __( 'Disable', 'peyvast-auth' ) ) )
				);
				echo '<div class="peyvast-auth-field peyvast-auth-conditional" data-peyvast-auth-condition-field="peyvast_auth_settings[redirect][woocommerce_logout_mode]" data-peyvast-auth-condition-value="page"><div class="peyvast-auth-field__head"><label>' . esc_html__( 'WooCommerce logout page', 'peyvast-auth' ) . '</label></div><div class="peyvast-auth-field__control">';
				wp_dropdown_pages( array( 'name' => 'peyvast_auth_settings[redirect][woocommerce_logout_page_id]', 'selected' => (int) $s['redirect']['woocommerce_logout_page_id'], 'show_option_none' => esc_html__( 'Select a page', 'peyvast-auth' ), 'option_none_value' => '', 'class' => 'peyvast-auth-select' ) );
				echo '</div></div>';
				self::field( __( 'WooCommerce logout custom URL', 'peyvast-auth' ), 'peyvast_auth_settings[redirect][woocommerce_logout_custom_url]', (string) $s['redirect']['woocommerce_logout_custom_url'], 'url', __( 'Only internal site URLs are accepted.', 'peyvast-auth' ), array( 'condition_field' => 'peyvast_auth_settings[redirect][woocommerce_logout_mode]', 'condition_value' => 'custom' ) );
				self::field(
					__( 'WordPress native logout', 'peyvast-auth' ),
					'peyvast_auth_settings[redirect][wordpress_logout_mode]',
					(string) $s['redirect']['wordpress_logout_mode'],
					'select',
					__( 'Destination used by the native WordPress logout flow. Disable preserves WordPress default behavior.', 'peyvast-auth' ),
					array( 'options' => array( 'home' => __( 'Homepage', 'peyvast-auth' ), 'page' => __( 'Specific page', 'peyvast-auth' ), 'custom' => __( 'Custom URL', 'peyvast-auth' ), 'disable' => __( 'Disable', 'peyvast-auth' ) ) )
				);
				echo '<div class="peyvast-auth-field peyvast-auth-conditional" data-peyvast-auth-condition-field="peyvast_auth_settings[redirect][wordpress_logout_mode]" data-peyvast-auth-condition-value="page"><div class="peyvast-auth-field__head"><label>' . esc_html__( 'WordPress logout page', 'peyvast-auth' ) . '</label></div><div class="peyvast-auth-field__control">';
				wp_dropdown_pages( array( 'name' => 'peyvast_auth_settings[redirect][wordpress_logout_page_id]', 'selected' => (int) $s['redirect']['wordpress_logout_page_id'], 'show_option_none' => esc_html__( 'Select a page', 'peyvast-auth' ), 'option_none_value' => '', 'class' => 'peyvast-auth-select' ) );
				echo '</div></div>';
				self::field( __( 'WordPress logout custom URL', 'peyvast-auth' ), 'peyvast_auth_settings[redirect][wordpress_logout_custom_url]', (string) $s['redirect']['wordpress_logout_custom_url'], 'url', __( 'Only internal site URLs are accepted.', 'peyvast-auth' ), array( 'condition_field' => 'peyvast_auth_settings[redirect][wordpress_logout_mode]', 'condition_value' => 'custom' ) );
				self::card_end();

				self::card_start( __( 'Redirect logged-in visitors from the login page', 'peyvast-auth' ), __( 'Redirects only affect the frontend.', 'peyvast-auth' ) );
				echo '<div class="peyvast-auth-field"><div class="peyvast-auth-field__head"><label>' . esc_html__( 'Authentication page', 'peyvast-auth' ) . '</label></div><div class="peyvast-auth-field__control">';
				wp_dropdown_pages(
					array(
						'name'              => 'peyvast_auth_settings[redirect][login_page_id]',
						'selected'          => (int) $s['redirect']['login_page_id'],
						'show_option_none'  => esc_html__( 'Disable', 'peyvast-auth' ),
						'option_none_value' => '',
						'class'             => 'peyvast-auth-select',
					)
				);
				echo '<p class="peyvast-auth-help">' . esc_html__( 'Select the page that contains the login or registration element.', 'peyvast-auth' ) . '</p></div></div>';
				self::field(
					__( 'Options', 'peyvast-auth' ),
					'peyvast_auth_settings[redirect][logged_in_mode]',
					(string) $s['redirect']['logged_in_mode'],
					'select',
					__( 'Choose where an already signed-in visitor should go.', 'peyvast-auth' ),
					array(
						'options' => array(
							'home'    => __( 'Homepage', 'peyvast-auth' ),
							'page'    => __( 'Specific page', 'peyvast-auth' ),
							'custom'  => __( 'Custom URL', 'peyvast-auth' ),
							'disable' => __( 'Do nothing', 'peyvast-auth' ),
						),
					)
				);
				echo '<div class="peyvast-auth-field peyvast-auth-conditional" data-peyvast-auth-condition-field="peyvast_auth_settings[redirect][logged_in_mode]" data-peyvast-auth-condition-value="page">';
				echo '<div class="peyvast-auth-field__head"><label>' . esc_html__( 'Destination page', 'peyvast-auth' ) . '</label></div><div class="peyvast-auth-field__control">';
				wp_dropdown_pages(
					array(
						'name'              => 'peyvast_auth_settings[redirect][logged_in_page_id]',
						'selected'          => (int) $s['redirect']['logged_in_page_id'],
						'show_option_none'  => esc_html__( 'Select a page', 'peyvast-auth' ),
						'option_none_value' => '',
						'class'             => 'peyvast-auth-select',
					)
				);
				echo '</div></div>';
				self::field(
					__( 'Custom URL', 'peyvast-auth' ),
					'peyvast_auth_settings[redirect][logged_in_custom_url]',
					(string) $s['redirect']['logged_in_custom_url'],
					'url',
					__( 'Only internal site URLs are accepted.', 'peyvast-auth' ),
					array(
						'condition_field' => 'peyvast_auth_settings[redirect][logged_in_mode]',
						'condition_value' => 'custom',
					)
				);
				self::card_end();
				break;
			case 'google':
				self::card_start( __( 'Sign in with Google', 'peyvast-auth' ), __( 'Independent identity provider; registration stays phone-only.', 'peyvast-auth' ) );
				self::toggle( 'peyvast_auth_settings[google][enabled]', ! empty( $s['google']['enabled'] ), __( 'Enable Google sign-in', 'peyvast-auth' ) );
				self::field( __( 'Google Client ID', 'peyvast-auth' ), 'peyvast_auth_settings[google][client_id]', (string) $s['google']['client_id'] );
				self::card_end();
				break;
		}
	}

	private static function render_providers( array $s ): void {
		$providers = array(
			'none'                      => array( __( 'No provider', 'peyvast-auth' ), __( 'No SMS provider is selected.', 'peyvast-auth' ) ),
			'kavenegar'                 => array( __( 'Kavenegar', 'peyvast-auth' ), __( 'Pattern-based verification lookup.', 'peyvast-auth' ) ),
			'melipayamak'               => array( __( 'Melipayamak', 'peyvast-auth' ), __( 'Pattern-based verification delivery.', 'peyvast-auth' ) ),
			'msgway'                    => array( __( 'Msgway', 'peyvast-auth' ), __( 'Pattern-based verification delivery.', 'peyvast-auth' ) ),
			'smsir'                     => array( __( 'SMS.ir', 'peyvast-auth' ), __( 'Pattern-based verification delivery.', 'peyvast-auth' ) ),
			'ippanel'                   => array( __( 'IPPanel', 'peyvast-auth' ), __( 'API key pattern delivery.', 'peyvast-auth' ) ),
			'ippanel_username_password' => array( __( 'IPPanel username/password', 'peyvast-auth' ), __( 'Username/password delivery.', 'peyvast-auth' ) ),
		);
		self::card_start( __( 'SMS providers', 'peyvast-auth' ), __( 'One active provider with its own credentials.', 'peyvast-auth' ) );
		echo '<p class="peyvast-auth-help">' . esc_html__( 'For automatic one-time code filling, your message must include the code after the domain; for example: @website.com #Code.', 'peyvast-auth' ) . '</p>';
		echo '<div class="peyvast-auth-provider-picker" data-peyvast-auth-provider-picker>';
		foreach ( $providers as $key => [$label, $description] ) {
			$active = $s['providers']['sms']['active'] === $key;
			echo '<button type="button" class="peyvast-auth-provider-choice ' . ( $active ? 'is-active' : '' ) . '" data-peyvast-auth-provider-select="' . esc_attr( $key ) . '" aria-pressed="' . ( $active ? 'true' : 'false' ) . '"><span class="peyvast-auth-provider-choice__radio"></span><span><strong>' . esc_html__( $label, 'peyvast-auth' ) . '</strong><small>' . esc_html__( $description, 'peyvast-auth' ) . '</small></span></button>';
		}
		echo '</div><input type="hidden" name="peyvast_auth_settings[providers][sms][active]" value="' . esc_attr( $s['providers']['sms']['active'] ) . '" data-peyvast-auth-provider-value>';
		$fields = array(
			'kavenegar'                 => array(
				'api_key'  => array( __( 'API Key', 'peyvast-auth' ), 'password' ),
				'template' => array( __( 'Template name', 'peyvast-auth' ), 'text' ),
			),
			'melipayamak'               => array(
				'username'    => array( __( 'Username', 'peyvast-auth' ), 'text' ),
				'password'    => array( __( 'Password', 'peyvast-auth' ), 'password' ),
				'template_id' => array( __( 'Template ID / body ID', 'peyvast-auth' ), 'text' ),
			),
			'msgway'                    => array(
				'api_key'     => array( __( 'API Key', 'peyvast-auth' ), 'password' ),
				'template_id' => array( __( 'Template ID', 'peyvast-auth' ), 'text' ),
			),
			'smsir'                     => array(
				'api_key'        => array( __( 'API Key', 'peyvast-auth' ), 'password' ),
				'template_id'    => array( __( 'Template ID', 'peyvast-auth' ), 'text' ),
				'parameter_name' => array( __( 'Pattern parameter name', 'peyvast-auth' ), 'text' ),
			),
			'ippanel'                   => array(
				'api_key'        => array( __( 'API Key', 'peyvast-auth' ), 'password' ),
				'pattern_code'   => array( __( 'Pattern code', 'peyvast-auth' ), 'text' ),
				'originator'     => array( __( 'Originator', 'peyvast-auth' ), 'text' ),
				'parameter_name' => array( __( 'Pattern parameter name', 'peyvast-auth' ), 'text' ),
			),
			'ippanel_username_password' => array(
				'username'     => array( __( 'Username', 'peyvast-auth' ), 'text' ),
				'password'     => array( __( 'Password', 'peyvast-auth' ), 'password' ),
				'from'         => array( __( 'From', 'peyvast-auth' ), 'text' ),
				'pattern_code' => array( __( 'Pattern code', 'peyvast-auth' ), 'text' ),
				'input_name'   => array( __( 'Pattern variable name', 'peyvast-auth' ), 'text' ),
			),
		);
		foreach ( $providers as $provider => [$label, $description] ) {
			echo '<div class="peyvast-auth-provider-panel ' . ( $s['providers']['sms']['active'] === $provider ? 'is-active' : '' ) . '" data-peyvast-auth-provider-panel="' . esc_attr( $provider ) . '">';
			if ( $provider === 'none' ) {
				echo '<div class="peyvast-auth-empty-state"><span class="dashicons dashicons-warning" aria-hidden="true"></span><div><strong>' . esc_html__( 'SMS delivery is not configured.', 'peyvast-auth' ) . '</strong><p>' . esc_html__( 'Select a provider to enable SMS OTP delivery.', 'peyvast-auth' ) . '</p></div></div>';
			} else {
				echo '<div class="peyvast-auth-provider-panel__head"><div><h3>' . esc_html__( $label, 'peyvast-auth' ) . '</h3><p>' . esc_html__( $description, 'peyvast-auth' ) . '</p></div><span class="peyvast-auth-chip">SMS</span></div><div class="peyvast-auth-grid peyvast-auth-grid--2">';
				foreach ( $fields[ $provider ] as $fieldKey => $definition ) {
					[$fieldLabel, $inputType] = $definition;
					$stored                   = (string) ( $s['providers']['sms_credentials'][ $provider ][ $fieldKey ] ?? '' );
					$fieldName                = 'peyvast_auth_settings[providers][sms_credentials][' . $provider . '][' . $fieldKey . ']';
					if ( strpos( $inputType, 'select:' ) === 0 ) {
						$parts   = explode( '|', substr( $inputType, 7 ) );
						$options = array();
						for ( $i = 0; $i < count( $parts ); $i += 2 ) {
							if ( isset( $parts[ $i + 1 ] ) ) {
								$options[ $parts[ $i ] ] = $parts[ $i + 1 ];
							}
						}
						self::field( $fieldLabel, $fieldName, $stored, 'select', '', array( 'options' => $options ) );
					} else {
						self::field( $fieldLabel, $fieldName, $inputType === 'password' ? '' : $stored, $inputType, $inputType === 'password' ? __( 'Leave blank to keep the saved secret.', 'peyvast-auth' ) : '' );
					}
				}
				echo '</div>';
			}
			echo '</div>';
		}
		echo '<div class="peyvast-auth-provider-test"><div><strong>' . esc_html__( 'Provider test', 'peyvast-auth' ) . '</strong><p>' . esc_html__( 'Sends a real OTP through the selected provider.', 'peyvast-auth' ) . '</p></div><div class="peyvast-auth-provider-test__controls"><input class="peyvast-auth-input" type="tel" data-peyvast-auth-test-phone inputmode="tel" dir="ltr" placeholder="0903XXXXXXX" aria-label="' . esc_attr__( 'Test mobile number', 'peyvast-auth' ) . '" /><button type="button" class="peyvast-auth-button peyvast-auth-button--secondary" data-peyvast-auth-test-provider><span class="dashicons dashicons-email-alt" aria-hidden="true"></span><span>' . esc_html__( 'Send test OTP', 'peyvast-auth' ) . '</span></button></div><div class="peyvast-auth-provider-test__result" data-peyvast-auth-test-result role="status" aria-live="polite"></div></div>';

		self::field(
			__( 'HTTP request timeout (seconds)', 'peyvast-auth' ),
			'peyvast_auth_settings[providers][sms][timeout]',
			(string) $s['providers']['sms']['timeout'],
			'number',
			__( 'Timeout for provider HTTP requests.', 'peyvast-auth' ),
			array(
				'min'  => 5,
				'max'  => 60,
				'step' => 1,
			)
		);
		self::card_end();

		self::card_start( __( 'Email provider', 'peyvast-auth' ), __( 'Built-in WordPress mail delivery and the OTP email template.', 'peyvast-auth' ) );
		echo '<div class="peyvast-auth-grid peyvast-auth-grid--2">';
		self::field( __( 'Sender name', 'peyvast-auth' ), 'peyvast_auth_settings[providers][email][sender_name]', (string) ( $s['providers']['email']['sender_name'] ?? '' ) ?: Settings::default_email_sender_name(), 'text', __( 'Defaults to the site name.', 'peyvast-auth' ) );
		self::field( __( 'Sender address', 'peyvast-auth' ), 'peyvast_auth_settings[providers][email][sender_address]', (string) ( $s['providers']['email']['sender_address'] ?? '' ) ?: Settings::default_email_sender_address(), 'email', __( 'Defaults to noreply@{site_domain}.', 'peyvast-auth' ) );
		echo '</div>';
		self::field( __( 'Subject', 'peyvast-auth' ), 'peyvast_auth_settings[providers][email][subject]', (string) ( $s['providers']['email']['subject'] ?? '' ) !== '' ? (string) $s['providers']['email']['subject'] : Settings::default_email_subject(), 'text', __( 'Placeholders: {otp}, {expiration}, {site_name}, {email}. Empty uses the default.', 'peyvast-auth' ) );
		self::field( __( 'Body', 'peyvast-auth' ), 'peyvast_auth_settings[providers][email][body]', (string) ( $s['providers']['email']['body'] ?? '' ) !== '' ? (string) $s['providers']['email']['body'] : Settings::default_email_body(), 'textarea', __( 'Placeholders: {otp}, {expiration}, {site_name}, {email}. Empty body uses the generated default template.', 'peyvast-auth' ) );
		echo '<div class="peyvast-auth-provider-test"><div><strong>' . esc_html__( 'Email provider test', 'peyvast-auth' ) . '</strong><p>' . esc_html__( 'Sends a real OTP through WordPress mail to the address you enter.', 'peyvast-auth' ) . '</p></div><div class="peyvast-auth-provider-test__controls"><input class="peyvast-auth-input" type="email" data-peyvast-auth-test-email inputmode="email" dir="ltr" placeholder="you@example.com" aria-label="' . esc_attr__( 'Test email address', 'peyvast-auth' ) . '" /><button type="button" class="peyvast-auth-button peyvast-auth-button--secondary" data-peyvast-auth-test-email-provider><span class="dashicons dashicons-email-alt" aria-hidden="true"></span><span>' . esc_html__( 'Send test OTP', 'peyvast-auth' ) . '</span></button></div><div class="peyvast-auth-provider-test__result" data-peyvast-auth-test-email-result role="status" aria-live="polite"></div></div>';
		self::card_end();
	}
}
