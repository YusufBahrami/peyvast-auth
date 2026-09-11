<?php
if (!defined('ABSPATH')) { exit; }
echo \Peyvast\Auth\Integrations\Blocks\Integration::render_auth(is_array($attributes ?? null) ? $attributes : []);
