<?php
namespace Peyvast\Auth\Infrastructure\WordPress;

defined( 'ABSPATH' ) || exit;

/** Stores only presentation dismissal state; migration jobs remain untouched. */
final class MigrationNoticeStore {
	public static function key( $scope ) {
		return 'peyvast_auth_phone_migration_notice_' . (string) $scope;
	}

	public static function exists( $scope ) {
		return is_array( get_option( self::key( $scope ), null ) );
	}

	public static function dismiss( $scope ) {
		$deleted = delete_option( self::key( $scope ) );
		return $deleted || ! self::exists( $scope );
	}

	public static function mark( $scope, array $payload ) {
		return update_option( self::key( $scope ), $payload, false );
	}
}
