<?php
namespace Peyvast\Auth\Infrastructure\Providers;

defined( 'ABSPATH' ) || exit;

interface ProviderInterface {
	public function id(): string;
	public function send( string $recipient, string $otp, array $context = array() ): ProviderResult;
}
