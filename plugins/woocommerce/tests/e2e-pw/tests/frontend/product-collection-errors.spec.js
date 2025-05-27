/**
 * Internal dependencies
 */
import { test as baseTest, expect } from '../../fixtures/fixtures';
import { ADMIN_STATE_PATH } from '../../playwright.config';

const test = baseTest.extend( {
	storageState: ADMIN_STATE_PATH,

	category: async ( { page }, use ) => {
		const name = `e2e-test-cat-${ Date.now() }`;

		await page.goto(
			'wp-admin/edit-tags.php?taxonomy=product_cat&post_type=product'
		);
		await page.locator( '#tag-name' ).fill( name );
		await page.getByRole( 'button', { name: 'Add new category' } ).click();

		const row = page
			.locator( 'table.wp-list-table td.name a.row-title' )
			.filter( { hasText: name } );
		await expect( row ).toBeVisible();

		const href = await row.getAttribute( 'href' );
		const idMatch = href?.match( /tag_ID=(\d+)/ );
		const id = idMatch ? parseInt( idMatch[ 1 ], 10 ) : null;

		if ( ! id )
			throw new Error( `Could not extract category ID for: ${ name }` );

		await use( { name, id } );
	},

	product: async ( { page, category }, use ) => {
		const productName = `Out of Stock Product ${ Date.now() }`;
		const { id: categoryId } = category;

		await page.goto(
			'http://localhost:8086/wp-admin/post-new.php?post_type=product',
			{
				waitUntil: 'domcontentloaded',
			}
		);

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

		await page
			.locator(
				`input[name="tax_input[product_cat][]"][value="${ categoryId }"]`
			)
			.check();

		await Promise.all( [
			page.waitForNavigation( {
				waitUntil: 'domcontentloaded',
				timeout: 10000,
			} ),
			page.locator( 'input#publish' ).click(),
		] );

		// Fallback to detect *any* relevant message by text content
		const notice = page.locator( '#message' ).filter( {
			hasText: /Product (published|updated)/i,
		} );
		await expect( notice ).toHaveCount( 1 );
		await expect( notice ).toBeVisible();

		await use( { name: productName } );
	},

	pageSetup: async ( { restApi, category }, use ) => {
		const pageRes = await restApi.post( '/wp/v2/pages', {
			title: 'Product Collection Test',
			content: `<!-- wp:woocommerce/product-collection {"categories":["${ category.id }"]} /-->`,
			status: 'publish',
			slug: 'product-collection-test',
		} );

		expect( pageRes.status ).toBe( 201 );
		await use( true );
	},
} );

test( 'displays error notice when adding out-of-stock product from Product Collection block', async ( {
	page,
	product,
	pageSetup,
} ) => {
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
		await expect( errorNotice ).toBeVisible();
		await expect( errorNotice ).toContainText(
			/out of stock|cannot be added/i
		);
	} );
} );
