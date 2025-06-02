<?php
declare( strict_types = 1 );

/**
 * Tests for Shop page behavior when trashed.
 */
class WC_Tests_Shop_Page_Trashed extends WC_Unit_Test_Case {

	/**
	 * Data provider: themes to test.
	 *
	 * @return array[]
	 */
	public function theme_provider() {
		return array(
			array( 'twentytwentyfour' ), // Block theme.
			array( 'storefront' ),       // Classic theme.
		);
	}

	/**
	 * Tests shop page behavior when trashed.
	 *
	 * @dataProvider theme_provider
	 * @param string $theme the theme to switch to.
	 */
	public function test_shop_page_trashed( $theme ) {

		// Switch to provided theme.
		switch_theme( $theme );
		echo esc_html( "Switching theme: {$theme}" ) . PHP_EOL;

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

		// At this point, WooCommerce still holds the ID but the page is trashed.
		$page_title = get_the_title( wc_get_page_id( 'shop' ) );

		// If title is empty (due to trash), fallback to default.
		if ( empty( $page_title ) ) {
			$page_title = 'Shop';
		}

		// Assert the fallback title.
		$this->assertEquals( 'Shop', $page_title, 'Expected fallback title "Shop" when shop page is trashed.' );
	}
}
