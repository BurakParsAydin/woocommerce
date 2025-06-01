<?php
declare( strict_types = 1 );

/**
 * Tests for Shop page behavior when trashed.
 */
class WC_Tests_Shop_Page_Trashed extends WC_Unit_Test_Case {

	/**
	 * Tests shop page behavior when trashed.
	 */
	public function test_shop_page_trashed() {
		// Create a Shop page.
		$page_id = wp_insert_post(
			array(
				'post_title'  => 'Shop',
				'post_name'   => 'shop',
				'post_status' => 'publish',
				'post_type'   => 'page',
			)
		);

		$this->assertNotEmpty( $page_id, 'Failed to create Shop page.' );

		// Set as WooCommerce shop page.
		update_option( 'woocommerce_shop_page_id', $page_id );

		// Trash the page (soft-delete).
		wp_trash_post( $page_id );

		// Confirm post status is now 'trash'.
		$trashed_post = get_post( $page_id );
		$this->assertEquals( 'trash', $trashed_post->post_status, 'Post status is not trash.' );

		// Confirm wc_get_page_id() still returns the page ID.
		$shop_page_id = wc_get_page_id( 'shop' );
		$this->assertEquals( $page_id, $shop_page_id, 'wc_get_page_id() did not return expected ID for trashed shop page.' );
	}
}
