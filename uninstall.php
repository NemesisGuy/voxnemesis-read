<?php
/**
 * Removes the plugin's settings.
 *
 * The player keeps nothing on the server, so this is all there is: the options row, and
 * nothing else. The model is downloaded by the reader, into the reader's own browser, and
 * the browser's cache is not this plugin's to clear.
 *
 * @package voxnemasis_read
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'vnra_options' );
