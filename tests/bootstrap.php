<?php
// Unit tests cover the WordPress-independent classes only.
define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../includes/class-field-names.php';
require_once __DIR__ . '/../includes/class-document-builder.php';
require_once __DIR__ . '/../includes/class-query-builder.php';
if ( file_exists( __DIR__ . '/../vendor/autoload.php' ) ) {
	require_once __DIR__ . '/../vendor/autoload.php';
}
