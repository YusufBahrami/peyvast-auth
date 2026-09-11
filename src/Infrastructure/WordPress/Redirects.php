<?php
namespace Peyvast\Auth\Infrastructure\WordPress;

use Peyvast\Auth\Core\Config\Settings;

defined( 'ABSPATH' ) || exit;

final class Redirects {
	public static function logged_in_login_page() {
		if ( ! is_user_logged_in() || is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
			return;
		}
		if ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() ) {
			return;
		}
		if ( function_exists( 'bricks_is_builder_iframe' ) && bricks_is_builder_iframe() ) {
			return;
		}
		$page_id = absint( Settings::get( 'redirect.login_page_id', 0 ) );
		if ( ! $page_id || ! is_page( $page_id ) ) {
			return;
		}
		$mode = (string) Settings::get( 'redirect.logged_in_mode', 'disable' );
		if ( $mode === 'disable' ) {
			return;
		}
		$url = '';
		if ( $mode === 'home' ) {
			$url = home_url( '/' );
		} elseif ( $mode === 'page' ) {
			$url = get_permalink( absint( Settings::get( 'redirect.logged_in_page_id', 0 ) ) ) ?: '';
		} elseif ( $mode === 'custom' ) {
			$url = wp_validate_redirect( (string) Settings::get( 'redirect.logged_in_custom_url', '' ), '' );
		}
		if ( $url !== '' ) {
			wp_safe_redirect( $url );
		}
		exit;
	}
}
