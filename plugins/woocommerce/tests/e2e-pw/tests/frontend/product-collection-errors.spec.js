/**
 * Internal dependencies
 */
import { test as baseTest, expect } from '../../fixtures/fixtures';
import { ADMIN_STATE_PATH } from '../../playwright.config';

const test = baseTest.extend( {
	storageState: ADMIN_STATE_PATH,
	category: async ( { page }, use ) => {
		const name = `e2e-test-cat-${ Date.now() }`;
		let categoryId = null;

		try {
			await page.goto(
				'wp-admin/edit-tags.php?taxonomy=product_cat&post_type=product'
			);
			await page.locator( '#tag-name' ).fill( name );
			await page
				.getByRole( 'button', { name: 'Add new category' } )
				.click();

			const row = page
				.locator( 'table.wp-list-table td.name a.row-title' )
				.filter( { hasText: name } );
			await expect( row ).toBeVisible();

			const href = await row.getAttribute( 'href' );
			const idMatch = href?.match( /tag_ID=(\d+)/ );
			const id = idMatch ? parseInt( idMatch[ 1 ], 10 ) : null;

			if ( ! id ) {
				throw new Error(
					`Could not extract category ID for: ${ name }`
				);
			}

			categoryId = id;
			await use( { name, id } );
		} finally {
			// Cleanup: Delete the category after test
			if ( categoryId ) {
				try {
					await page.goto(
						'wp-admin/edit-tags.php?taxonomy=product_cat&post_type=product'
					);
					const deleteLink = page.locator(
						`tr[id="tag-${ categoryId }"] .row-actions .delete a`
					);
					if ( await deleteLink.isVisible() ) {
						await deleteLink.click();
					}
				} catch ( error ) {
					console.warn(
						`Failed to cleanup category ${ categoryId }:`,
						error
					);
				}
			}
		}
	},

	product: async ( { page, category }, use ) => {
		const productName = `Out of Stock Product ${ Date.now() }`;
		const { id: categoryId } = category;
		let productId = null;

		try {
			await page.goto( '/wp-admin/post-new.php?post_type=product', {
				waitUntil: 'domcontentloaded',
			} );

			await page.getByLabel( 'Product name' ).fill( productName );
			await page.getByLabel( 'Regular price ($)' ).fill( '29.99' );
			await page.getByRole( 'link', { name: 'Inventory' } ).click();

			const manageStock = page.locator( '#_manage_stock' );
			if ( await manageStock.isChecked() ) {
				await manageStock.uncheck();
			}

			const outOfStockRadio = page.getByRole( 'radio', {
				name: 'Out of stock',
			} );
			await outOfStockRadio.waitFor( { timeout: 5000 } );
			await outOfStockRadio.check();
			await expect( outOfStockRadio ).toBeChecked();

			// More robust category selection
			const categoryCheckbox = page.locator(
				`input[name="tax_input[product_cat][]"][value="${ categoryId }"]`
			);
			await categoryCheckbox.check();

			await Promise.all( [
				page.locator( 'input#publish' ).click(),
				page.waitForLoadState( 'domcontentloaded', { timeout: 5000 } ),
			] );

			// Verify for 'Product published' message
			const successNotice = page.locator( '#message.notice-success' );
			await Promise.all( [
				expect( successNotice ).toHaveCount( 1 ),
				expect( successNotice ).toBeVisible(),
			] );

			// Extract product ID for cleanup
			const currentUrl = page.url();
			const productIdMatch = currentUrl.match( /post=(\d+)/ );
			if ( productIdMatch ) {
				productId = parseInt( productIdMatch[ 1 ], 10 );
			}

			await use( { name: productName, id: productId } );
		} finally {
			// Cleanup: Move product to trash after test
			if ( productId ) {
				try {
					await page.goto(
						`/wp-admin/post.php?post=${ productId }&action=edit`
					);
					const moveToTrashLink = page.getByRole( 'link', {
						name: 'Move to Trash',
					} );
					if ( await moveToTrashLink.isVisible() ) {
						await moveToTrashLink.click();
					}
				} catch ( error ) {
					console.warn(
						`Failed to cleanup product ${ productId }:`,
						error
					);
				}
			}
		}
	},

	pageSetup: async ( { restApi, category }, use ) => {
		let pageId = null;

		try {
			const pageRes = await restApi.post( '/wp/v2/pages', {
				title: 'Product Collection Test',
				content: `<!-- wp:woocommerce/product-collection {"categories":["${ category.id }"]} /-->`,
				status: 'publish',
				slug: 'product-collection-test',
			} );

			expect( pageRes.status ).toBe( 201 );
			pageId = pageRes.data?.id;

			await use( true );
		} finally {
			// Cleanup: Delete the test page
			if ( pageId ) {
				try {
					await restApi.delete(
						`/wp/v2/pages/${ pageId }?force=true`
					);
				} catch ( error ) {
					console.warn(
						`Failed to cleanup page ${ pageId }:`,
						error
					);
				}
			}
		}
	},
} );

test( 'displays error notice when adding out-of-stock product from Product Collection block', async ( {
	page,
	product,
	pageSetup,
} ) => {
	expect( pageSetup ).toBeTruthy();

	await test.step( 'Go to the test page with Product Collection block', async () => {
		await page.goto( '/product-collection-test' );
		await expect( page.getByText( product.name ) ).toBeVisible();
	} );

	await test.step( 'Try adding the out-of-stock product to cart', async () => {
		const addToCartBtn = page
			.getByRole( 'button', { name: /add to cart/i } )
			.first();
		await addToCartBtn.click();
	} );

	await test.step( 'Expect error notice to appear', async () => {
		const errorNotice = page.locator(
			'.wc-block-components-notice-banner.is-error'
		);
		await expect( errorNotice ).toBeVisible( { timeout: 5000 } );
		await expect( errorNotice ).toContainText(
			/out of stock|cannot be added/i
		);
	} );
} );
