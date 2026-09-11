<?php
namespace Peyvast\Auth\Integrations\WooCommerce;

use Peyvast\Auth\Domain\Phone\PhoneNumber;
use Peyvast\Auth\Core\Config\Settings;

defined( 'ABSPATH' ) || exit;

final class Integration {
	private static bool $booted         = false;
	private static array $syncing_users = array();

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_action( 'before_woocommerce_init', array( self::class, 'declare_hpos_compatibility' ) );

		// Register regardless of WooCommerce load order; each callback checks availability itself.
		add_action( 'user_register', array( self::class, 'sync_user' ), 20, 1 );
		add_action( 'peyvast_auth_sync_woocommerce_phone', array( self::class, 'sync' ), 10, 2 );
		add_action( 'profile_update', array( self::class, 'profile_update' ), 20, 2 );
		add_action( 'updated_user_meta', array( self::class, 'updated_user_meta' ), 20, 4 );
		add_action( 'woocommerce_checkout_create_order', array( self::class, 'checkout_sync' ), 20, 2 );
		add_action( 'woocommerce_before_checkout_form', array( self::class, 'checkout_notice' ), 5, 1 );
		add_action( 'woocommerce_checkout_process', array( self::class, 'checkout_notice' ), 5, 0 );
		add_action( 'template_redirect', array( self::class, 'protect_frontend_pages' ), 1 );
		add_action( 'admin_notices', array( self::class, 'runtime_login_page_notice' ) );
		add_filter( 'woocommerce_get_return_url', array( self::class, 'filter_return_url' ), 20, 2 );
		add_filter( 'woocommerce_logout_default_redirect_url', array( self::class, 'logout_redirect' ), 20 );
		add_action( 'woocommerce_blocks_loaded', array( self::class, 'register_store_api_extension' ), 20 );
	}

	public static function declare_hpos_compatibility(): void {
		if ( ! class_exists( 'Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			return;
		}
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PEYVAST_AUTH_FILE, true );
	}

	public static function is_available(): bool {
		return class_exists( 'WooCommerce' ) || class_exists( 'WC_Customer' ) || function_exists( 'wc_get_customer' );
	}

	public static function customer_for_user( int $user_id ) {
		if ( $user_id <= 0 || ! self::is_available() ) {
			return null;
		}
		if ( function_exists( 'wc_get_customer' ) ) {
			$customer = wc_get_customer( $user_id );
			if ( is_object( $customer ) ) {
				return $customer;
			}
		}
		if ( class_exists( 'WC_Customer' ) ) {
			try {
				$customer = new \WC_Customer( $user_id );
				return $customer instanceof \WC_Customer ? $customer : null;
			} catch ( \Throwable $e ) {
				return null;
			}
		}
		return null;
	}

	public static function sync( int $user_id, string $canonical ): void {
		if ( $user_id <= 0 || isset( self::$syncing_users[ $user_id ] ) || ! self::is_available() ) {
			return;
		}
		self::$syncing_users[ $user_id ] = true;
		try {
			self::sync_customer_values( $user_id, $canonical );
		} finally {
			unset( self::$syncing_users[ $user_id ] );
		}
	}

	private static function sync_customer_values( int $user_id, string $canonical ): void {
		if ( ! self::is_available() ) {
			return;
		}
		$customer = self::customer_for_user( $user_id );
		if ( ! $customer ) {
			return;
		}

		$user    = get_userdata( $user_id );
		$changed = false;
		if ( $user instanceof \WP_User && Settings::get( 'woocommerce.save_name', true ) ) {
			$changed = self::set_customer_value( $customer, 'billing', 'first_name', (string) $user->first_name ) || $changed;
			$changed = self::set_customer_value( $customer, 'shipping', 'first_name', (string) $user->first_name ) || $changed;
			$changed = self::set_customer_value( $customer, 'billing', 'last_name', (string) $user->last_name ) || $changed;
			$changed = self::set_customer_value( $customer, 'shipping', 'last_name', (string) $user->last_name ) || $changed;
		}
		if ( $user instanceof \WP_User && Settings::get( 'woocommerce.save_email', true ) ) {
			$email   = sanitize_email( (string) $user->user_email );
			$changed = self::set_customer_value( $customer, 'billing', 'email', $email ) || $changed;
			$changed = self::set_customer_value( $customer, 'shipping', 'email', $email ) || $changed;
		}
		if ( Settings::get( 'woocommerce.save_phone', true ) ) {
			$storage = PhoneNumber::storage_value( $canonical, (string) Settings::get( 'woocommerce.phone_storage_format', 'leading_zero' ) );
			if ( $storage !== '' ) {
				$changed = self::set_customer_value( $customer, 'billing', 'phone', $storage ) || $changed;
				$changed = self::set_customer_value( $customer, 'shipping', 'phone', $storage ) || $changed;
			}
		}
		if ( $changed && method_exists( $customer, 'save' ) ) {
			$customer->save();
		}
	}

	public static function profile_update( int $user_id, $old_user_data = null ): void {
		self::sync_user( $user_id );
	}

	public static function updated_user_meta( int $meta_id, int $user_id, string $meta_key, $meta_value ): void {
		if ( $user_id <= 0 || isset( self::$syncing_users[ $user_id ] ) || ! in_array( $meta_key, array( '_peyvast_auth_phone', '_peyvast_auth_phone_canonical' ), true ) ) {
			return;
		}
		self::sync_user( $user_id );
	}

	public static function checkout_sync( $order, $data = null ): void {
		if ( ! self::is_available() || ! is_object( $order ) || ! method_exists( $order, 'get_user_id' ) ) {
			return;
		}
		$user_id = (int) $order->get_user_id();
		if ( $user_id <= 0 ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return;
		}

		$changed = false;
		if ( Settings::get( 'woocommerce.save_name', true ) ) {
			$changed = self::set_order_if_empty( $order, 'billing', 'first_name', (string) $user->first_name ) || $changed;
			$changed = self::set_order_if_empty( $order, 'shipping', 'first_name', (string) $user->first_name ) || $changed;
			$changed = self::set_order_if_empty( $order, 'billing', 'last_name', (string) $user->last_name ) || $changed;
			$changed = self::set_order_if_empty( $order, 'shipping', 'last_name', (string) $user->last_name ) || $changed;
		}
		if ( Settings::get( 'woocommerce.save_email', true ) ) {
			$email   = sanitize_email( (string) $user->user_email );
			$changed = self::set_order_if_empty( $order, 'billing', 'email', $email ) || $changed;
			$changed = self::set_order_if_empty( $order, 'shipping', 'email', $email ) || $changed;
		}
		if ( Settings::get( 'woocommerce.save_phone', true ) ) {
			$stored    = (string) get_user_meta( $user_id, '_peyvast_auth_phone', true );
			$canonical = PhoneNumber::canonical_value( $stored );
			$phone     = $canonical ? PhoneNumber::storage_value( $canonical, (string) Settings::get( 'woocommerce.phone_storage_format', 'leading_zero' ) ) : '';
			if ( $phone !== '' ) {
				$changed = self::set_order_if_empty( $order, 'billing', 'phone', $phone ) || $changed;
				$changed = self::set_order_if_empty( $order, 'shipping', 'phone', $phone ) || $changed;
			}
		}
		if ( $changed && method_exists( $order, 'save' ) ) {
			$order->save();
		}
	}

	public static function sync_user( int $user_id ): void {
		if ( $user_id <= 0 || isset( self::$syncing_users[ $user_id ] ) ) {
			return;
		}
		$stored    = (string) get_user_meta( $user_id, '_peyvast_auth_phone', true );
		$canonical = PhoneNumber::canonical_value( $stored );
		if ( $canonical !== '' ) {
			self::sync( $user_id, $canonical );
			return;
		}
		self::$syncing_users[ $user_id ] = true;
		try {
			if ( ! self::is_available() ) {
				return;
			}
			$customer = self::customer_for_user( $user_id );
			$user     = get_userdata( $user_id );
			if ( ! $customer || ! $user instanceof \WP_User ) {
				return;
			}
			$changed = false;
			if ( Settings::get( 'woocommerce.save_name', true ) ) {
				$changed = self::set_customer_value( $customer, 'billing', 'first_name', (string) $user->first_name ) || $changed;
				$changed = self::set_customer_value( $customer, 'shipping', 'first_name', (string) $user->first_name ) || $changed;
				$changed = self::set_customer_value( $customer, 'billing', 'last_name', (string) $user->last_name ) || $changed;
				$changed = self::set_customer_value( $customer, 'shipping', 'last_name', (string) $user->last_name ) || $changed;
			}
			if ( Settings::get( 'woocommerce.save_email', true ) ) {
				$email   = sanitize_email( (string) $user->user_email );
				$changed = self::set_customer_value( $customer, 'billing', 'email', $email ) || $changed;
				$changed = self::set_customer_value( $customer, 'shipping', 'email', $email ) || $changed;
			}
			if ( $changed && method_exists( $customer, 'save' ) ) {
				$customer->save();
			}
		} finally {
			unset( self::$syncing_users[ $user_id ] );
		}
	}

	public static function logout_redirect( string $default_url ): string {
		$destination = Settings::logout_destination( 'woocommerce' );
		return $destination !== '' ? $destination : $default_url;
	}

	public static function filter_return_url( string $return_url, $order = null ): string {
		if ( ! self::is_available() || is_user_logged_in() || ! Settings::get( 'woocommerce.order_receipt_redirect', false ) || ! is_object( $order ) || ! method_exists( $order, 'get_user_id' ) ) {
			return $return_url;
		}
		if ( (int) $order->get_user_id() <= 0 ) {
			return $return_url;
		}
		$login_url = self::login_url( Settings::get( 'woocommerce.order_receipt_notice', false ) ? 'order_receipt' : '' );
		if ( $login_url === '' ) {
			return $return_url;
		}
		return add_query_arg( 'peyvast_return_to', esc_url_raw( $return_url ), $login_url );
	}

	public static function checkout_notice( $checkout = null ): void {
		if ( ! self::is_available() || is_user_logged_in() ) {
			return;
		}
		if ( ! Settings::get( 'woocommerce.guest_checkout_notice', false ) || ! self::is_guest_checkout_disabled_page() ) {
			return;
		}
		if ( function_exists( 'wc_print_notice' ) ) {
			$message = __( 'Please sign in or register before continuing to checkout.', 'peyvast-auth' );
			if ( ! function_exists( 'wc_has_notice' ) || ! wc_has_notice( $message, 'notice' ) ) {
				wc_print_notice( $message, 'notice' );
			}
		}
	}

	public static function runtime_login_page_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! self::is_available() ) {
			return;
		}
		$page_id = absint( Settings::get( 'redirect.login_page_id', 0 ) );
		if ( $page_id > 0 && get_post_status( $page_id ) !== false && in_array( get_post_status( $page_id ), array( 'publish', 'private' ), true ) ) {
			return;
		}
		if ( $page_id > 0 ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Peyvast Auth: the configured WooCommerce login page is missing or unavailable. WooCommerce redirects are using the homepage fallback.', 'peyvast-auth' ) . '</p></div>';
		}
	}

	public static function protect_frontend_pages(): void {
		if ( ! self::is_available() || is_user_logged_in() || is_admin() || wp_doing_ajax() ) {
			return;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) ) {
			return;
		}
		if ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() ) {
			return;
		}
		if ( function_exists( 'bricks_is_builder_iframe' ) && bricks_is_builder_iframe() ) {
			return;
		}

		$notice = '';
		if ( self::is_my_account_page() && Settings::get( 'woocommerce.my_account_redirect', false ) ) {
			$notice = '';
		} elseif ( self::is_order_receipt_page() ) {
			if ( Settings::get( 'woocommerce.order_receipt_redirect', false ) && self::receipt_belongs_to_user() ) {
				$notice = Settings::get( 'woocommerce.order_receipt_notice', false ) ? 'order_receipt' : '';
			} else {
				return;
			}
		} elseif ( self::is_guest_checkout_disabled_page() ) {
			if ( Settings::get( 'woocommerce.guest_checkout_redirect', false ) ) {
				$notice = Settings::get( 'woocommerce.guest_checkout_notice', false ) ? 'guest_checkout' : '';
			} else {
				return;
			}
		} else {
			return;
		}

		$login_url = self::login_url( $notice );
		if ( $login_url === '' || self::same_request( $login_url ) ) {
			return;
		}
		$return_to = self::current_url();
		if ( $return_to !== '' ) {
			$login_url = add_query_arg( 'peyvast_return_to', $return_to, $login_url );
		}
		wp_safe_redirect( $login_url );
		exit;
	}

	private static function set_customer_value( object $customer, string $scope, string $field, string $value ): bool {
		$getter = 'get_' . $scope . '_' . $field;
		$setter = 'set_' . $scope . '_' . $field;
		if ( ! is_callable( array( $customer, $getter ) ) || ! is_callable( array( $customer, $setter ) ) ) {
			return false;
		}
		if ( (string) $customer->{$getter}() === $value ) {
			return false;
		}
		$customer->{$setter}( $value );
		return true;
	}

	private static function set_order_if_empty( object $order, string $scope, string $field, string $value ): bool {
		if ( $value === '' ) {
			return false;
		}
		$getter = 'get_' . $scope . '_' . $field;
		$setter = 'set_' . $scope . '_' . $field;
		if ( ! is_callable( array( $order, $getter ) ) || ! is_callable( array( $order, $setter ) ) ) {
			return false;
		}
		if ( (string) $order->{$getter}() !== '' ) {
			return false;
		}
		$order->{$setter}( $value );
		return true;
	}

	private static function is_my_account_page(): bool {
		return function_exists( 'is_account_page' ) && is_account_page();
	}

	private static function is_guest_checkout_disabled_page(): bool {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return false;
		}
		if ( self::is_order_receipt_page() ) {
			return false;
		}
		$enabled = get_option( 'woocommerce_enable_guest_checkout', 'yes' );
		return strtolower( (string) $enabled ) !== 'yes';
	}

	/** Store API requests power the Checkout block; never redirect them from PHP. */
	public static function register_store_api_extension(): void {
		if ( ! class_exists( 'Automattic\\WooCommerce\\StoreApi\\StoreApi' ) || ! class_exists( 'Automattic\\WooCommerce\\StoreApi\\Extensions\\ExtensionInterface' ) ) {
			return;
		}
		// Store API JSON is left untouched; classic checkout/return URLs stay with the hooks above.
	}

	public static function is_store_api_request(): bool {
		return function_exists( 'woocommerce_store_api_is_request_to_endpoint' ) && woocommerce_store_api_is_request_to_endpoint( 'checkout' );
	}

	/** Protected pages: order-received receipt and order-pay checkout. */
	private static function is_order_receipt_page(): bool {
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			return true;
		}
		return function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' );
	}

	private static function receipt_belongs_to_user(): bool {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return false;
		}
		$order_id = 0;
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
			$order_id = absint( get_query_var( 'order-pay' ) );
			if ( ! $order_id ) {
				$order_id = absint( $_GET['order-pay'] ?? 0 );
			}
		}
		if ( ! $order_id ) {
			$order_id = absint( get_query_var( 'order-received' ) );
			if ( ! $order_id ) {
				$order_id = absint( $_GET['order-received'] ?? 0 );
			}
		}
		$order = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_user_id' ) ) {
			return false;
		}
		$user_id = (int) $order->get_user_id();
		if ( $user_id <= 0 ) {
			return false;
		}
		$provided_key = sanitize_text_field( wp_unslash( $_GET['key'] ?? '' ) );
		$order_key    = method_exists( $order, 'get_order_key' ) ? (string) $order->get_order_key() : '';
		if ( $provided_key === '' || $order_key === '' || ! hash_equals( $order_key, $provided_key ) ) {
			return false;
		}
		return true;
	}

	private static function login_url( string $notice = '' ): string {
		$page_id = absint( Settings::get( 'redirect.login_page_id', 0 ) );
		$url     = $page_id ? get_permalink( $page_id ) : '';
		if ( ! $url ) {
			// A missing login page falls back to the homepage; never loop or redirect externally.
			$url = home_url( '/' );
		}
		if ( $notice !== '' ) {
			$url = add_query_arg( 'peyvast_wc_notice', $notice, $url );
		}
		return esc_url_raw( $url );
	}

	private static function same_request( string $url ): bool {
		$current = self::current_url();
		if ( $current === '' ) {
			return false;
		}
		$current_parts = wp_parse_url( $current );
		$target_parts  = wp_parse_url( $url );
		if ( ! $current_parts || ! $target_parts ) {
			return false;
		}
		$current_path = untrailingslashit( (string) ( $current_parts['path'] ?? '' ) );
		$target_path  = untrailingslashit( (string) ( $target_parts['path'] ?? '' ) );
		$current_host = strtolower( (string) ( $current_parts['host'] ?? '' ) );
		$target_host  = strtolower( (string) ( $target_parts['host'] ?? '' ) );
		$current_scheme = strtolower( (string) ( $current_parts['scheme'] ?? '' ) );
		$target_scheme  = strtolower( (string) ( $target_parts['scheme'] ?? '' ) );
		if ( $current_host !== '' && $target_host !== '' && $current_host !== $target_host ) {
			return false;
		}
		if ( $current_scheme !== '' && $target_scheme !== '' && $current_scheme !== $target_scheme ) {
			return false;
		}
		return $current_path !== '' && $current_path === $target_path && self::query_args_equal( $current_parts['query'] ?? '', $target_parts['query'] ?? '' );
	}

	private static function query_args_equal( string $current, string $target ): bool {
		parse_str( $current, $current_args );
		parse_str( $target, $target_args );
		unset( $current_args['peyvast_return_to'], $current_args['peyvast_wc_notice'], $target_args['peyvast_return_to'], $target_args['peyvast_wc_notice'] );
		ksort( $current_args );
		ksort( $target_args );
		return $current_args === $target_args;
	}

	private static function current_url(): string {
		$uri = wp_unslash( $_SERVER['REQUEST_URI'] ?? '' );
		if ( $uri === '' ) {
			return '';
		}
		$url = home_url( $uri );
		return wp_validate_redirect( $url, '' ) ? esc_url_raw( $url ) : '';
	}
}
