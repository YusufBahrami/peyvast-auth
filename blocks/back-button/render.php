<?php
if (!defined('ABSPATH')) { exit; }
echo \Peyvast\Auth\Integrations\Blocks\Integration::render_back_button(is_array($attributes ?? null) ? $attributes : []);
