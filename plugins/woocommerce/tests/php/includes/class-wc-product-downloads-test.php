<?php

use Automattic\WooCommerce\Internal\ProductDownloads\ApprovedDirectories\Register as Download_Directories;
use Automattic\WooCommerce\Enums\ProductType;
use Automattic\WooCommerce\Enums\OrderStatus;
/**
 * Class WC_Product_Download_Test
 */
class WC_Product_Download_Test extends WC_Unit_Test_Case {
	/**
	 * Test for file without extension.
	 */
	public function test_is_allowed_filetype_with_no_extension() {
		$upload_dir                  = trailingslashit( wp_upload_dir()['basedir'] );
		$file_path_with_no_extension = $upload_dir . 'upload_file';
		if ( ! file_exists( $file_path_with_no_extension ) ) {
			// Copy an existing file without extension.
			$this->assertTrue( touch( $file_path_with_no_extension ), 'Unable to create file without extension.' );
		}
		$download = new WC_Product_Download();
		$download->set_file( $file_path_with_no_extension );
		$this->assertEquals( true, $download->is_allowed_filetype() );
	}

	/**
	 * Simulates test condition for windows when filename ends with a period.
	 */
	public function test_is_allowed_filetype_on_windows_with_period_at_end() {
		$upload_dir                   = trailingslashit( wp_upload_dir()['basedir'] );
		$file_path_with_period_at_end = $upload_dir . 'upload_file.';
		if ( ! file_exists( $file_path_with_period_at_end ) ) {
			// Copy an existing file without extension.
			$this->assertTrue( touch( $file_path_with_period_at_end ), 'Unable to create file with period at the end.' );
		}
		\Automattic\Jetpack\Constants::set_constant( 'PHP_OS', 'winnt' );
		$download = new WC_Product_Download();
		$download->set_file( $file_path_with_period_at_end );
		$this->assertEquals( false, $download->is_allowed_filetype() );
	}

	/**
	 * Test that download URLs are automatically added to the approved directories list (for
	 * "admin"-level users) but that they are not automatically added in other cases.
	 */
	public function test_allowed_directory_rules_are_enforced() {
		/** @var Download_Directories $download_directories */
		$download_directories = wc_get_container()->get( Download_Directories::class );
		$download_directories->set_mode( Download_Directories::MODE_ENABLED );

		$non_admin_user = wp_insert_user(
			array(
				'user_login' => uniqid(),
				'role'       => 'editor',
				'user_pass'  => 'x',
			)
		);
		$admin_user     = wp_insert_user(
			array(
				'user_login' => uniqid(),
				'role'       => 'administrator',
				'user_pass'  => 'x',
			)
		);
		$ebook_url      = 'https://external.site/books/ultimate-guide-to-stuff.pdf';
		$podcast_url    = 'https://external.site/podcasts/ultimate-guide-to-stuff.mp3';

		wp_set_current_user( $admin_user );
		$download = new WC_Product_Download();
		$download->set_file( $ebook_url );
		$this->assertFalse( $download_directories->is_valid_path( $ebook_url ), 'Verify ebook path has not been added prior to next test.' );
		$download->check_is_valid();
		$this->assertTrue( $download_directories->is_valid_path( $ebook_url ), 'Verify ebook path was automatically added by the last operation.' );

		wp_set_current_user( $non_admin_user );
		$download = new WC_Product_Download();
		$download->set_file( $podcast_url );
		$this->expectExceptionMessage( 'is not located within an approved directory' );
		$download->check_is_valid();
	}

	/**
	 * Test handling of filepaths described via shortcodes in relation to the Approved Download Directory
	 * feature. This is to simulate scenarios such as encountered when using the S3 Downloads extension.
	 */
	public function test_shortcode_resolution_for_approved_directory_rules() {
		/** @var Download_Directories $download_directories */
		$download_directories = wc_get_container()->get( Download_Directories::class );
		$download_directories->set_mode( Download_Directories::MODE_ENABLED );
		$dynamic_filepath = 'https://fast.reliable.external.fileserver.com/bucket-123/textbook.pdf';

		// We select an admin user because we wish to automatically add Approved Directory rules.
		$admin_user = wp_insert_user(
			array(
				'user_login' => uniqid(),
				'role'       => 'administrator',
				'user_pass'  => 'x',
			)
		);
		wp_set_current_user( $admin_user );

		add_shortcode(
			'dynamic-download',
			function () {
				return 'https://fast.reliable.external.fileserver.com/bucket-123/textbook.pdf';
			}
		);

		$this->assertFalse(
			$download_directories->is_valid_path( $dynamic_filepath ),
			'Confirm the filepath returned by the test URL is not yet valid.'
		);

		$download = new WC_Product_Download();
		$download->set_file( '[dynamic-download]' );

		$this->assertNull(
			$download->check_is_valid(),
			'The downloadable file successfully validates (if it did not, an exception would be thrown).'
		);

		$this->assertTrue(
			$download_directories->is_valid_path( $dynamic_filepath ),
			'Confirm the filepath returned by the test URL is now considered valid.'
		);

		remove_shortcode( 'dynamic-download' );

		// Now the shortcode is removed (perhaps the parent plugin has been removed/disabled) it will not resolve
		// and so the filepath will not validate.
		$this->expectException( 'Error' );
		$download_directories->check_is_valid();
	}

	/**
	 * We should use the same error message when rejecting files that do not exist as when we we reject
	 * files in an unapproved directory, otherwise we are leaking information about the possible existence
	 * of system files.
	 *
	 * @return void
	 */
	public function test_error_messages_do_not_leak_file_existence(): void {
		/** @var Download_Directories $download_directories */
		$download_directories = wc_get_container()->get( Download_Directories::class );
		$download_directories->set_mode( Download_Directories::MODE_ENABLED );

		wp_set_current_user(
			$this->factory->user->create(
				array(
					'user_login' => uniqid(),
					'role'       => 'editor',
				)
			)
		);

		$test_file = ABSPATH . 'wp-content/uploads/empty.png';
		file_put_contents( $test_file, '' );
		$this->assertTrue( file_exists( $test_file ), 'Confirms that our test files exists.' );

		// Ensure the final test fails in the event exceptions are not raised later in the test.
		$file_does_not_exist = new Exception( '1' );
		$invalid_directory   = new Exception( '2' );

		$download = new WC_Product_Download();
		$download->set_file( $test_file );

		try {
			$download->check_is_valid();
		} catch ( Exception $invalid_directory ) {
			// Do nothing here: we simply wish to capture the exception.
		}

		unlink( $test_file );
		$this->assertFalse( file_exists( $test_file ), 'Confirms that our test file no longer exists.' );

		try {
			$download->check_is_valid();
		} catch ( Exception $file_does_not_exist ) {
			// Do nothing here: we simply wish to capture the exception.
		}

		$this->assertEquals(
			$invalid_directory->getMessage(),
			$file_does_not_exist->getMessage(),
			'We use the same error message when the file does not exist as when the directory is invalid.'
		);
	}

	/**
	 * Test that a product download is not allowed when the downloads remaining set to 0.
	 */
	public function test_downloads_remaining_attempts() {
		self::remove_download_handlers();

		wp_set_current_user( 1 );

		// Unregister download handlers to prevent unwanted output and side-effects.
		remove_action( 'woocommerce_download_file_xsendfile', array( WC_Download_Handler::class, 'download_file_xsendfile' ) );
		remove_action( 'woocommerce_download_file_redirect', array( WC_Download_Handler::class, 'download_file_redirect' ) );
		remove_action( 'woocommerce_download_file_force', array( WC_Download_Handler::class, 'download_file_force' ) );

		// 1. Setup: Create product and set backorders allowed.
		$json_data = array(
			'name'         => 'Digital Product',
			'type'         => ProductType::SIMPLE,
			'price'        => '21.99',
			'virtual'      => true,
			'downloadable' => true,
		);

		$request = new WP_REST_Request( 'POST', '/wc/v3/products' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $json_data ) );

		$response = rest_do_request( $request );

		if ( $response->get_status() !== 201 ) {
			throw new \Exception( 'Product has not been created' );
		}

		$response_data = $response->get_data();
		$product_id    = $response_data['id'];

		// Step 2: Add a downloadable file to the product.
		$product = wc_get_product( $product_id );
		$product->set_downloads(
			array(
				'file_key' => array(
					'name' => 'Test Download',
					'file' => 'https://example.com/test-file.zip', // Dummy file URL.
				),
			)
		);
		$product->save();
		$downloads = $product->get_downloads();
		$this->assertNotEmpty( $downloads, 'Downloadable file was not set correctly.' );

		// Step 3: Create a paid order using WC_Helper_Order.
		$order = new WC_Order();
		$item  = new WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_total( $product->get_price() );
		$order->add_item( $item );
		$order->set_billing_email( 'admin@example.org' );
		$order->set_status( OrderStatus::COMPLETED );
		$order->save();

		$order_id = $order->get_id();
		echo 'Created order ID: ' . esc_html( $order_id ) . "\n";

		// Step 4: Set 'Downloads Remainings' to 0. And go to the url if the download exists or not.
		$downloads     = $product->get_downloads();
		$download_keys = array_keys( $downloads );
		$email         = 'admin@example.org';
		$download      = current( WC_Data_Store::load( 'customer-download' )->get_downloads( array( 'product_id' => $product_id ) ) );

		$download->set_downloads_remaining( 0 );
		$download->save();

		$_GET = array(
			'download_file' => $product_id,
			'order'         => $order->get_order_key(),
			'email'         => $email,
			'uid'           => hash( 'sha256', $email ),
			'key'           => $download_keys[0],
		);

		// Simulate the download.
		ob_start();
		try {
			WC_Download_Handler::download_product();
		} catch ( \WPDieException $e ) {
			$this->assertStringContainsString(
				'Sorry, you have reached your download limit',
				$e->getMessage()
			);
		}
		ob_end_clean();

		// Ensure download count hasn't changed.
		$download = new WC_Customer_Download( $download->get_id() );
		$this->assertEquals(
			0,
			$download->get_downloads_remaining(),
			'Expected remaining downloads to be 0.'
		);

		$downloads_available = wc_get_customer_available_downloads( 1 );

		$this->assertCount(
			0,
			$downloads_available,
			'Expected no available downloads when download limit is reached.'
		);

		self::restore_download_handlers();
	}

	/**
	 * Test that a product download is disallowed after the expiration date has passed.
	 */
	public function test_download_after_expiry() {
		self::remove_download_handlers();

		wp_set_current_user( 1 );

		// Unregister download handlers to prevent unwanted output and side-effects.
		remove_action( 'woocommerce_download_file_xsendfile', array( WC_Download_Handler::class, 'download_file_xsendfile' ) );
		remove_action( 'woocommerce_download_file_redirect', array( WC_Download_Handler::class, 'download_file_redirect' ) );
		remove_action( 'woocommerce_download_file_force', array( WC_Download_Handler::class, 'download_file_force' ) );

		// 1. Setup: Create product and set backorders allowed.
		$json_data = array(
			'name'         => 'Digital Product',
			'type'         => ProductType::SIMPLE,
			'price'        => '21.99',
			'virtual'      => true,
			'downloadable' => true,
		);

		$request = new WP_REST_Request( 'POST', '/wc/v3/products' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $json_data ) );

		$response = rest_do_request( $request );

		if ( $response->get_status() !== 201 ) {
			throw new \Exception( 'Product has not been created' );
		}

		$response_data = $response->get_data();
		$product_id    = $response_data['id'];

		// Step 2: Add a downloadable file to the product.
		$product = wc_get_product( $product_id );
		$product->set_downloads(
			array(
				'file_key' => array(
					'name' => 'Test Download',
					'file' => 'https://example.com/test-file.zip', // Dummy file URL.
				),
			)
		);
		$product->save();
		$downloads = $product->get_downloads();
		$this->assertNotEmpty( $downloads, 'Downloadable file was not set correctly.' );

		// Step 3: Create a paid order using WC_Helper_Order.
		$order = new WC_Order();
		$item  = new WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_total( $product->get_price() );
		$order->add_item( $item );
		$order->set_billing_email( 'admin@example.org' );
		$order->set_status( OrderStatus::COMPLETED );
		$order->save();

		$order_id = $order->get_id();
		echo 'Created order ID: ' . esc_html( $order_id ) . "\n";

		// Step 4: Set the expiry date. And go to the url if the download exists or not.
		$downloads     = $product->get_downloads();
		$download_keys = array_keys( $downloads );
		$email         = 'admin@example.org';
		$download      = current( WC_Data_Store::load( 'customer-download' )->get_downloads( array( 'product_id' => $product_id ) ) );
		$download->set_access_expires( '2020-01-01 00:00:00' );
		$download->save();

		$_GET = array(
			'download_file' => $product_id,
			'order'         => $order->get_order_key(),
			'email'         => $email,
			'uid'           => hash( 'sha256', $email ),
			'key'           => $download_keys[0],
		);

		// Simulate the download.
		ob_start();
		try {
			WC_Download_Handler::download_product();
		} catch ( \WPDieException $e ) {
			$this->assertStringContainsString(
				'Sorry, this download has expired',
				$e->getMessage()
			);
		}
		ob_end_clean();

		$download            = new WC_Customer_Download( $download->get_id() );
		$downloads_available = wc_get_customer_available_downloads( 1 );

		$this->assertCount(
			0,
			$downloads_available,
			'Expected no available downloads when the expiration date has passed.'
		);

		self::restore_download_handlers();
	}

	/**
	 * Unregister download handlers to prevent unwanted output and side-effects.
	 */
	private static function remove_download_handlers() {
		remove_action( 'woocommerce_download_file_xsendfile', array( WC_Download_Handler::class, 'download_file_xsendfile' ) );
		remove_action( 'woocommerce_download_file_redirect', array( WC_Download_Handler::class, 'download_file_redirect' ) );
		remove_action( 'woocommerce_download_file_force', array( WC_Download_Handler::class, 'download_file_force' ) );
	}

	/**
	 * Restores download handlers in case needed by other tests.
	 */
	private static function restore_download_handlers() {
		add_action( 'woocommerce_download_file_redirect', array( WC_Download_Handler::class, 'download_file_redirect' ), 10, 2 );
		add_action( 'woocommerce_download_file_xsendfile', array( WC_Download_Handler::class, 'download_file_xsendfile' ), 10, 2 );
		add_action( 'woocommerce_download_file_force', array( WC_Download_Handler::class, 'download_file_force' ), 10, 2 );
	}
}
