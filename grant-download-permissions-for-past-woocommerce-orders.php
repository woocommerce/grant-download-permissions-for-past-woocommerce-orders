<?php
/**
 * Plugin Name: Grant download permissions for past WooCommerce orders
 * Plugin URI:  https://github.com/woocommerce/grant-download-permissions-for-past-woocommerce-orders
 * Description: This plugin grants downloads permissions like WooCommerce 2.6.x, granting permissions for new files added to a downloadable product. Note that this plugin performs heavy database queries and does not scale. For this reason it has been removed from WooCommerce core.
 * Author:      Claudio Sanches
 * Author URI:  https://claudiosanches.com
 * Version:     0.0.2
 * License:     GPLv2 or later
 *
 * Grant download permissions like legacy WooCommerce is free software:
 * you can redistribute it and/or modify it under the terms of
 * the GNU General Public License as published by the Free Software Foundation,
 * either version 2 of the License, or any later version.
 *
 * Grant download permissions like legacy WooCommerce is distributed in the hope
 * that it will be useful but WITHOUT ANY WARRANTY; without even the implied
 * warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Grant download permissions like legacy WooCommerce. If not, see
 * <https://www.gnu.org/licenses/gpl-2.0.txt>.
 *
 * @package Grant_Download_Permissions_Like_Legacy_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * WooCommerce_Legacy_Grant_Download_Permissions class.
 */
class WooCommerce_Legacy_Grant_Download_Permissions {

	/**
	 * Instance of this class.
	 *
	 * @var object
	 */
	protected static $instance = null;

	/**
	 * Initialize the plugin actions.
	 */
	private function __construct() {
		// Stop if WooCommerce isn't activated.
		if ( ! class_exists( 'WC_Admin_Post_Types', false ) ) {
			return;
		}

		// Remove WooCommerce 3.0 download permission action.
		remove_action( 'woocommerce_process_product_file_download_paths', array( 'WC_Admin_Post_Types', 'process_product_file_download_paths' ), 10, 3 );

		// Backwards compatibility method.
		add_action( 'woocommerce_process_product_file_download_paths', array( $this, 'grant_download_permissions' ), 10, 3 );
	}

	/**
	 * Return an instance of this class.
	 *
	 * @return object A single instance of this class.
	 */
	public static function get_instance() {
		// If the single instance hasn't been set, set it now.
		if ( null === self::$instance ) {
			self::$instance = new self;
		}
		return self::$instance;
	}

	/**
	 * Grant download permissions like WooCommerce 2.6.
	 *
	 * This method performs a heavy query and should not be used for anyone!
	 *
	 * @param int  $product_id          Product identifier.
	 * @param int  $variation_id        Optional product variation identifier.
	 * @param array $downloadable_files Newly set files.
	 */
	public function grant_download_permissions( $product_id, $variation_id, $downloadable_files ) {
		global $wpdb;

		if ( $variation_id ) {
			$product_id = $variation_id;
		}

		if ( ! $product = wc_get_product( $product_id ) ) {
			return;
		}

		$existing_download_ids = array_keys( (array) $product->get_downloads() );
		$updated_download_ids  = array_keys( (array) $downloadable_files );
		$new_download_ids      = array_filter( array_diff( $updated_download_ids, $existing_download_ids ) );
		$removed_download_ids  = array_filter( array_diff( $existing_download_ids, $updated_download_ids ) );

		if ( ! empty( $new_download_ids ) || ! empty( $removed_download_ids ) ) {
			// Determine whether downloadable file access has been granted via the typical order completion, or via the admin ajax method.
			$existing_orders = $wpdb->get_col( $wpdb->prepare( "SELECT order_id from {$wpdb->prefix}woocommerce_downloadable_product_permissions WHERE product_id = %d GROUP BY order_id", $product_id ) );

			foreach ( $existing_orders as $existing_order_id ) {
				$order = wc_get_order( $existing_order_id );

				if ( $order ) {
					// Remove permissions.
					if ( ! empty( $removed_download_ids ) ) {
						foreach ( $removed_download_ids as $download_id ) {
							if ( apply_filters( 'woocommerce_process_product_file_download_paths_remove_access_to_old_file', true, $download_id, $product_id, $order ) ) {
								$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}woocommerce_downloadable_product_permissions WHERE order_id = %d AND product_id = %d AND download_id = %s", $order->get_id(), $product_id, $download_id ) );
							}
						}
					}

					// Add permissions.
					if ( ! empty( $new_download_ids ) ) {
						foreach ( $new_download_ids as $download_id ) {
							if ( apply_filters( 'woocommerce_process_product_file_download_paths_grant_access_to_new_file', true, $download_id, $product_id, $order ) ) {
								// Grant permission if it doesn't already exist.
								if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT 1=1 FROM {$wpdb->prefix}woocommerce_downloadable_product_permissions WHERE order_id = %d AND product_id = %d AND download_id = %s", $order->get_id(), $product_id, $download_id ) ) ) {
									wc_downloadable_file_permission( $download_id, $product_id, $order );
								}
							}
						}
					}
				}
			}
		}
	}
}

add_action( 'admin_init', array( 'WooCommerce_Legacy_Grant_Download_Permissions', 'get_instance' ) );
