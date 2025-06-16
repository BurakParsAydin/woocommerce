<?php

declare( strict_types = 1 );

/**
 * Ensures webhook deletion is rejected without a valid nonce.
 */
class WC_Admin_Webhook_Bulk_Delete_Test extends WC_Unit_Test_Case {

	/**
	 * Test that webhook deletion fails when no nonce is provided.
	 */
	public function test_bulk_delete_fails_without_nonce() {

		// Create and save a new webhook.
		$webhook = new WC_Webhook();
		$webhook->set_name( 'Webhook 1' );
		$webhook->save();

		$webhook_id = $webhook->get_id();

		echo 'Webhook successfully created. ID: ' . esc_html( $webhook_id ) . "\n";

		// Simulate a REST API request to delete the webhook without a nonce.
		$request = new WP_REST_Request( 'GET', '/wc/v3/webhooks' );
		$request->set_query_params(
			array(
				'action'    => 'delete',
				'webhook[]' => $webhook_id,
			)
		);

		// Dispatch the request.
		$response = rest_do_request( $request );

		// Assert that the request fails with a 401 Unauthorized status due to missing nonce.
		$this->assertEquals( 401, $response->get_status() );
	}
}
