<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/*
 * Action Scheduler is a shared library: even when this plugin's data is being
 * removed, the plugin-owned scheduled actions (recurring maintenance, chained
 * migrations, queued deliveries) must be cancelled. The bundled bootstrap is
 * loaded so version arbitration picks the newest registered runtime; if another
 * plugin already provides a newer version it is used instead. Action Scheduler
 * tables themselves are never dropped here.
 */
if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
	$peyvast_as_bootstrap = __DIR__ . '/lib/action-scheduler/action-scheduler.php';
	if ( file_exists( $peyvast_as_bootstrap ) ) {
		require_once $peyvast_as_bootstrap;
	}
}
if ( function_exists( 'as_unschedule_all_actions' ) && class_exists( 'ActionScheduler', false ) && method_exists( 'ActionScheduler', 'is_initialized' ) && ActionScheduler::is_initialized() ) {
	as_unschedule_all_actions( '', array(), 'peyvast-auth' );
}

$settings = get_option( 'peyvast_auth_settings', array() );
$delete   = is_array( $settings ) && ! empty( $settings['general']['delete_data_on_uninstall'] );
if ( ! $delete ) {
	return;
}

global $wpdb;

// Authoritative list of plugin-owned options; keep in sync.
foreach ( array(
	'peyvast_auth_settings',
	'peyvast_auth_identifier_key',
	'peyvast_auth_phone_migration_batch',
	'peyvast_auth_phone_migration_cancelled',
	'peyvast_auth_phone_migration_lock',
	'peyvast_auth_phone_migration_notice_default',
	'peyvast_auth_phone_identity_index_state',
	'peyvast_auth_phone_identity_index_generation',
	'peyvast_auth_schema_health',
) as $option ) {
	delete_option( $option );
}

// Cache backends may not honour TTLs; delete transients explicitly.
foreach ( array(
	'peyvast_auth_google_jwks',
	'peyvast_auth_phone_meta_keys',
) as $transient ) {
	delete_transient( $transient );
}
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_peyvast_auth_google_nonce_%' OR option_name LIKE '_transient_timeout_peyvast_auth_google_nonce_%'" );


$tables = array(
	$wpdb->prefix . 'peyvast_auth_otp_challenges',
	$wpdb->prefix . 'peyvast_auth_otp_deliveries',
	$wpdb->prefix . 'peyvast_auth_logs',
	$wpdb->prefix . 'peyvast_auth_security_state',
	$wpdb->prefix . 'peyvast_auth_phone_identity',
);
foreach ( $tables as $table ) {
	$safe = preg_replace( '/[^A-Za-z0-9_]/', '', $table );
	$wpdb->query( "DROP TABLE IF EXISTS `{$safe}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

$meta_keys    = array(
	'_peyvast_auth_phone',
	'_peyvast_auth_phone_canonical',
	'_peyvast_auth_phone_recovery',
	'_peyvast_auth_migration_recovery',
	'_peyvast_auth_registration_recovery',
	'_peyvast_auth_google_identity',
);
$placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ({$placeholders})",
		...$meta_keys
	)
);
