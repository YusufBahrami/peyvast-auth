<?php
namespace Peyvast\Auth\Infrastructure\Providers;

defined( 'ABSPATH' ) || exit;

class ProviderResult {
	public $success;
	public $provider;
	public $message;
	public $meta;

	public function __construct( $success, $provider = '', $message = '', array $meta = array() ) {
		$this->success  = (bool) $success;
		$this->provider = (string) $provider;
		$this->message  = (string) $message;
		$this->meta     = $meta;
	}
}
