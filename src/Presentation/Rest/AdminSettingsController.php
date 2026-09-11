<?php
namespace Peyvast\Auth\Presentation\Rest;
use Peyvast\Auth\Core\Config\Settings;
use Peyvast\Auth\Domain\Security\Guard;
use Peyvast\Auth\Domain\Phone\PhoneNumber;
use Peyvast\Auth\Application\Migration\PhoneMigrationService;
defined( 'ABSPATH' ) || exit;
final class AdminSettingsController {
	public static function settings( \WP_REST_Request $request ) {
		if ( $response = RestResponse::require_nonce( $request, 'peyvast_auth_settings' ) ) {
			return $response;
		}
		$raw = $request->get_param( 'peyvast_auth_settings' );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		if ( ! $raw ) {
			return RestResponse::error( 'invalid_request', __( 'No settings were submitted.', 'peyvast-auth' ) );
		}
		update_option( PEYVAST_AUTH_OPTION, Settings::sanitize( $raw ), false );
		self::clear_cache();
		return RestResponse::success(
			array(
				'message'          => __( 'Settings saved successfully.', 'peyvast-auth' ),
				'migration_status' => PhoneMigrationService::status_summary(),
			),
			'admin'
		);
	}
	public static function security_blocks( \WP_REST_Request $request ) {
		if ( $response = RestResponse::require_nonce( $request, 'peyvast_auth_settings' ) ) {
			return $response;
		} Guard::clear_all_blocks();
		return RestResponse::success( array( 'message' => __( 'All security blocks and rate-limit counters were cleared.', 'peyvast-auth' ) ), 'admin' ); }
	public static function migrate( \WP_REST_Request $request ) {
		if ( $response = RestResponse::require_nonce( $request, 'peyvast_auth_settings' ) ) {
			return $response;
		} $job = PhoneMigrationService::start();
		return RestResponse::success(
			array(
				'migration'        => $job,
				'migration_status' => PhoneMigrationService::status_summary( $job ),
			),
			'admin'
		); }
	public static function migration_status( \WP_REST_Request $request ) {
		if ( $response = RestResponse::require_nonce( $request, 'peyvast_auth_settings' ) ) {
			return $response;
		} $job = PhoneMigrationService::get_status();
		return RestResponse::success( array( 'migration_status' => PhoneMigrationService::status_summary( $job ) ), 'admin' ); }
	public static function migration_notice( \WP_REST_Request $request ) {
		if ( $response = RestResponse::require_nonce( $request, 'peyvast_auth_settings' ) ) {
			return $response;
		} PhoneMigrationService::dismiss_notice();
		return RestResponse::success(
			array(
				'dismissed'         => true,
				'migration_status' => PhoneMigrationService::status_summary(),
			),
			'admin'
		); }
	public static function validate_phone_meta( \WP_REST_Request $request ) {
		if ( $response = RestResponse::require_nonce( $request, 'peyvast_auth_settings' ) ) {
			return $response;
		}
		$key = sanitize_text_field( (string) $request->get_param( 'meta_key' ) );
		if ( $key === '' || in_array( $key, array( '_peyvast_auth_phone', '_peyvast_auth_phone_canonical' ), true ) ) {
			return RestResponse::success(
				array(
					'status'        => 'invalid',
					'sample_count'  => 0,
					'valid_count'   => 0,
					'invalid_count' => 0,
				),
				'admin'
			);
		}
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> '' ORDER BY user_id ASC LIMIT 200", $key ) );
		$rows = is_array( $rows ) ? $rows : array();
		if ( count( $rows ) > 20 ) {
			shuffle( $rows );
			$rows = array_slice( $rows, 0, 20 );
		} $valid = 0;
		foreach ( $rows as $row ) {
			if ( PhoneNumber::valid_input( (string) $row->meta_value ) ) {
				++$valid;
			}
		}		$count = count( $rows );
		return RestResponse::success(
			array(
				'status'        => $count > 0 && $valid === $count ? 'valid' : ( $count > 0 && $valid > 0 ? 'partial' : 'invalid' ),
				'sample_count'  => $count,
				'valid_count'   => $valid,
				'invalid_count' => max( 0, $count - $valid ),
			),
			'admin'
		);
	}
	private static function clear_cache(): void {
		delete_transient( 'peyvast_auth_phone_meta_keys' );
		wp_cache_delete( 'peyvast_auth_phone_meta_keys', 'peyvast_auth' ); }
}
