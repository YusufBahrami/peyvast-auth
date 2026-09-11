<?php
namespace Peyvast\Auth\Integrations\Bricks;

use Peyvast\Auth\Presentation\Frontend\Assets;

defined( 'ABSPATH' ) || exit;

final class BackButtonElement extends \Bricks\Element {
	public $category = 'general';
	public $name     = 'peyvast-auth-back';
	public $icon     = 'ti-arrow-left';
	public $nestable = false;

	public function get_label() {
		return __( 'Peyvast Authentication Back Button', 'peyvast-auth' );
	}

	public function get_keywords() {
		return array( 'peyvast', 'auth', 'back', 'button', 'login', 'otp' );
	}

	private function button_controls( string $label ): void {
		$this->controls['size']  = array(
			'tab'         => 'content',
			'type'        => 'select',
			'label'       => __( 'Size', 'peyvast-auth' ),
			'options'     => $this->control_options['buttonSizes'],
			'inline'      => true,
			'reset'       => true,
			'placeholder' => __( 'Default', 'peyvast-auth' ),
		);
		$this->controls['style'] = array(
			'tab'         => 'content',
			'type'        => 'select',
			'label'       => __( 'Style', 'peyvast-auth' ),
			'options'     => $this->control_options['styles'],
			'inline'      => true,
			'reset'       => true,
			'placeholder' => __( 'Default', 'peyvast-auth' ),
		);
		foreach ( array( 'circle', 'outline' ) as $flag ) {
			$this->controls[ $flag ] = array(
				'tab'    => 'content',
				'type'   => 'checkbox',
				'label'  => __( $flag === 'circle' ? 'Circle' : 'Outline', 'peyvast-auth' ),
				'reset'  => true,
				'inline' => true,
			);
		}
		$this->controls['padding']    = array(
			'tab'   => 'content',
			'type'  => 'spacing',
			'label' => __( 'Padding', 'peyvast-auth' ),
			'css'   => array(
				array(
					'property' => 'padding',
					'selector' => '.peyvast-auth-back',
				),
			),
			'reset' => true,
		);
		$this->controls['background'] = array(
			'tab'   => 'content',
			'type'  => 'color',
			'label' => __( 'Background', 'peyvast-auth' ),
			'css'   => array(
				array(
					'property' => 'background-color',
					'selector' => '.peyvast-auth-back',
				),
			),
			'reset' => true,
		);
		$this->controls['border']     = array(
			'tab'   => 'content',
			'type'  => 'border',
			'label' => __( 'Border', 'peyvast-auth' ),
			'css'   => array(
				array(
					'property' => 'border',
					'selector' => '.peyvast-auth-back',
				),
			),
			'reset' => true,
		);
		$this->controls['typography'] = array(
			'tab'   => 'content',
			'type'  => 'typography',
			'label' => __( 'Typography', 'peyvast-auth' ),
			'css'   => array(
				array(
					'property' => 'font',
					'selector' => '.peyvast-auth-back',
				),
			),
			'reset' => true,
		);
	}

	public function set_controls() {
		$this->controls['text']           = array(
			'tab'           => 'content',
			'type'          => 'text',
			'label'         => __( 'Text', 'peyvast-auth' ),
			'default'       => '',
			'inlineEditing' => true,
		);
		$this->controls['icon']           = array(
			'tab'     => 'content',
			'type'    => 'icon',
			'label'   => __( 'Icon', 'peyvast-auth' ),
			'default' => array(),
		);
		$this->controls['icon_position']  = array(
			'tab'      => 'content',
			'type'     => 'select',
			'label'    => __( 'Icon position', 'peyvast-auth' ),
			'options'  => $this->control_options['iconPosition'],
			'default'  => 'right',
			'inline'   => true,
			'reset'    => true,
			'required' => array( 'icon', '!=', '' ),
		);
		$this->controls['iconTypography'] = array(
			'tab'      => 'content',
			'type'     => 'typography',
			'label'    => __( 'Icon typography', 'peyvast-auth' ),
			'css'      => array(
				array(
					'property' => 'font',
					'selector' => '.peyvast-auth-back i',
				),
			),
			'reset'    => true,
			'required' => array( 'icon', '!=', '' ),
		);
		$this->controls['icon_gap']       = array(
			'tab'      => 'content',
			'type'     => 'number',
			'label'    => __( 'Icon gap', 'peyvast-auth' ),
			'units'    => true,
			'css'      => array(
				array(
					'property' => 'gap',
					'selector' => '.peyvast-auth-back',
				),
			),
			'reset'    => true,
			'required' => array( 'icon', '!=', '' ),
		);
		$this->controls['icon_space']     = array(
			'tab'      => 'content',
			'type'     => 'checkbox',
			'label'    => __( 'Space between', 'peyvast-auth' ),
			'css'      => array(
				array(
					'property' => 'justify-content',
					'value'    => 'space-between',
					'selector' => '.peyvast-auth-back',
				),
			),
			'reset'    => true,
			'required' => array( 'icon', '!=', '' ),
		);
		$this->controls['hide_text']      = array(
			'tab'     => 'content',
			'type'    => 'checkbox',
			'label'   => __( 'Hide text', 'peyvast-auth' ),
			'default' => false,
			'inline'  => true,
			'reset'   => true,
		);
		$this->controls['aria_label']     = array(
			'tab'         => 'content',
			'type'        => 'text',
			'label'       => __( 'Accessibility label', 'peyvast-auth' ),
			'default'     => '',
			'description' => __( 'Used when visible button text is hidden.', 'peyvast-auth' ),
		);
		$this->controls['target']         = array(
			'tab'         => 'content',
			'type'        => 'text',
			'label'       => __( 'Authentication target', 'peyvast-auth' ),
			'default'     => '',
			'description' => __( 'Optional CSS selector for a specific authentication instance.', 'peyvast-auth' ),
		);
		$this->button_controls( 'Button' );
	}

	public function enqueue_scripts() {
		Assets::enqueue_front(); }

	public function render() {
		Assets::enqueue_front();
		$text = isset( $this->settings['text'] ) && is_string( $this->settings['text'] ) ? trim( $this->settings['text'] ) : '';
		if ( $text === '' ) {
			$text = __( 'Back', 'peyvast-auth' );
		}
		$show_text      = array_key_exists( 'hide_text', $this->settings )
			? ! filter_var( $this->settings['hide_text'], FILTER_VALIDATE_BOOLEAN )
			: true;
		$icon_setting   = $this->settings['icon'] ?? array();
		$position_value = sanitize_key( (string) ( $this->settings['icon_position'] ?? 'right' ) );
		$position       = in_array( $position_value, array( 'after', 'right' ), true ) ? 'after' : 'before';
		$aria           = isset( $this->settings['aria_label'] ) && is_string( $this->settings['aria_label'] ) && trim( $this->settings['aria_label'] ) !== '' ? trim( $this->settings['aria_label'] ) : $text;
		$size           = sanitize_html_class( (string) ( $this->settings['size'] ?? '' ) );
		$style          = sanitize_html_class( (string) ( $this->settings['style'] ?? '' ) );
		$circle         = filter_var( $this->settings['circle'] ?? false, FILTER_VALIDATE_BOOLEAN );
		$outline        = filter_var( $this->settings['outline'] ?? false, FILTER_VALIDATE_BOOLEAN );
		$classes        = 'peyvast-auth-back';
		if ( $size !== '' || $style !== '' || $circle || $outline ) {
			$classes .= ' bricks-button';
		}
		if ( $size !== '' ) {
			$classes .= ' ' . $size;
		}
		if ( $style !== '' ) {
			$classes .= ' ' . ( $outline ? 'bricks-color-' : 'bricks-background-' ) . $style;
		}
		if ( $circle ) {
			$classes .= ' circle';
		}
		if ( $outline ) {
			$classes .= ' outline';
		}
		$gap = isset( $this->settings['icon_gap'] ) ? trim( (string) $this->settings['icon_gap'] ) : '';
		if ( $gap !== '' && preg_match( '/^[a-zA-Z0-9#%().,_+\\-\\s\\/]+$/', $gap ) ) {
			$this->set_attribute( '_root', 'style', '--peyvast-auth-back-icon-gap:' . esc_attr( $gap ) );
		}
		$icon_space = $this->settings['icon_space'] ?? false;
		if ( filter_var( $icon_space, FILTER_VALIDATE_BOOLEAN ) ) {
			$classes .= ' has-icon-space';
		}
		$this->set_attribute( '_root', 'type', 'button' );
		$this->set_attribute( '_root', 'class', $classes );
		$this->set_attribute( '_root', 'data-peyvast-auth-action', 'back' );
		if ( ! empty( $this->settings['target'] ) ) {
			$this->set_attribute( '_root', 'data-peyvast-auth-target', sanitize_text_field( (string) $this->settings['target'] ) );
		}
		if ( ! $show_text ) {
			$this->set_attribute( '_root', 'aria-label', $aria );
		}
		$icon = '';
		if ( ! empty( $icon_setting ) && is_array( $icon_setting ) && is_callable( array( '\\Bricks\\Element', 'render_icon' ) ) ) {
			// Bricks owns icon parsing/markup for font and SVG icons.
			$rendered_icon = self::render_icon( $icon_setting );
			$icon          = is_string( $rendered_icon ) ? trim( $rendered_icon ) : '';
		}
		$icon_markup = $icon !== '' ? $icon : '';
		$text_markup = $show_text ? '<span class="peyvast-auth-back__text">' . esc_html( $text ) . '</span>' : '';
		$content     = $position === 'after' ? $text_markup . $icon_markup : $icon_markup . $text_markup;
		echo '<button ' . $this->render_attributes( '_root' ) . '>' . $content . '</button>';
	}
}
