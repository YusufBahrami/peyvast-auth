<?php
namespace Peyvast\Auth\Integrations\Bricks;

use Peyvast\Auth\Core\Config\Settings;
use Peyvast\Auth\Infrastructure\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class Integration {
	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		// One authoritative gate: when disabled, no Bricks hook or element may remain active.
		if ( ! self::enabled() ) {
			return;
		}

		add_action( 'init', array( self::class, 'register_elements' ), 11 );
		add_filter( 'bricks/builder/elements', array( self::class, 'builder_elements' ), 10 );
		add_filter( 'bricks/dynamic_tags_list', array( self::class, 'tags' ), 10, 1 );
		add_filter( 'bricks/dynamic_data/render_tag', array( self::class, 'render_tag' ), 20, 3 );
	}

	/** The single gate for every Bricks producer/consumer hook. */
	public static function enabled(): bool {
		return (bool) Settings::get( 'integrations.bricks', true );
	}

	public static function register_elements(): void {
		if ( ! self::enabled() || ! class_exists( 'Bricks\\Elements' ) ) {
			return;
		}

		$auth_file = PEYVAST_AUTH_DIR . 'src/Integrations/Bricks/AuthElement.php';
		$back_file = PEYVAST_AUTH_DIR . 'src/Integrations/Bricks/BackButtonElement.php';
		if ( is_readable( $auth_file ) ) {
			self::register_element_once( $auth_file, 'peyvast-auth', AuthElement::class );
		}
		if ( is_readable( $back_file ) ) {
			self::register_element_once( $back_file, 'peyvast-auth-back', BackButtonElement::class );
		}
	}

	public static function builder_elements( array $elements ): array {
		if ( ! self::enabled() ) {
			return $elements;
		}
		foreach ( array( 'peyvast-auth', 'peyvast-auth-back' ) as $name ) {
			if ( ! in_array( $name, $elements, true ) ) {
				$elements[] = $name;
			}
		}
		return $elements;
	}

	private static function register_element_once( string $file, string $name, string $class ): void {
		static $registered = array();
		if ( isset( $registered[ $name ] ) ) {
			return;
		}
		try {
			\Bricks\Elements::register_element( $file, $name, $class );
			$registered[ $name ] = true;
		} catch ( \Throwable $error ) {
			Logger::error(
				'bricks',
				'element_registration_failed',
				'Bricks element registration failed.',
				array(
					'element'           => $name,
					'exception_class'   => get_class( $error ),
					'exception_message' => $error->getMessage(),
				)
			);
		}
	}

	public static function tags( array $tags ): array {
		if ( ! self::enabled() ) {
			return $tags;
		}
		$tags[] = array(
			'name'  => '{peyvast_auth_user_phone}',
			'label' => __( 'User mobile number', 'peyvast-auth' ),
			'group' => __( 'Peyvast Authentication', 'peyvast-auth' ),
		);
		$tags[] = array(
			'name'  => '{peyvast_auth_user_email}',
			'label' => __( 'User email', 'peyvast-auth' ),
			'group' => __( 'Peyvast Authentication', 'peyvast-auth' ),
		);
		$tags[] = array(
			'name'  => '{peyvast_auth_user_display_name}',
			'label' => __( 'User display name', 'peyvast-auth' ),
			'group' => __( 'Peyvast Authentication', 'peyvast-auth' ),
		);
		$tags[] = array(
			'name'  => '{peyvast_auth_user_id}',
			'label' => __( 'User ID', 'peyvast-auth' ),
			'group' => __( 'Peyvast Authentication', 'peyvast-auth' ),
		);

		return $tags;
	}

	public static function render_tag( $tag, $post, $context = 'text' ) {
		if ( ! self::enabled() || ! is_string( $tag ) ) {
			return $tag;
		}

		$normalized = trim( str_replace( array( '{', '}' ), '', $tag ) );
		if ( strpos( $normalized, 'peyvast_auth_' ) !== 0 ) {
			return $tag;
		}

		$user = wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return '';
		}

		switch ( $normalized ) {
			case 'peyvast_auth_user_phone':
				$value = (string) get_user_meta( $user->ID, '_peyvast_auth_phone', true );
				break;
			case 'peyvast_auth_user_email':
				$value = (string) $user->user_email;
				break;
			case 'peyvast_auth_user_display_name':
				$value = (string) $user->display_name;
				break;
			case 'peyvast_auth_user_id':
				$value = (string) $user->ID;
				break;
			default:
				$value = $tag;
				break;
		}

		return 'textarea' === $context ? $value : esc_html( $value );
	}
}
