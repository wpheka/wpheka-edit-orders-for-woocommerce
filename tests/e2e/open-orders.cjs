/**
 * Browser test of 1.3.0's pieces, against the wp-env store (tasks/edit-orders-1.3-spec.md):
 * - the orders list: the "Changes open" badge, the "Customer can still change" view,
 *   on the HPOS list and on the legacy posts list;
 * - the order screen note in the Edit order box;
 * - the settings: the two address switches and the product exclusion chips;
 * - My Account: the plain line on an excluded order, both address forms on an open one.
 *
 * Run from the plugin folder, with the playwright package installed somewhere outside
 * the synced plugins folder and found through NODE_PATH:
 *   NODE_PATH=/path/to/node_modules node tests/e2e/open-orders.cjs [screenshot-dir]
 *
 * Screenshots are taken at 1440 and 390 pixels wide. Uses the installed Chrome.
 *
 * @package Edit_Orders_For_WooCommerce
 */

'use strict';

const { chromium } = require( 'playwright' );
const { execSync } = require( 'child_process' );
const path = require( 'path' );
const fs = require( 'fs' );

const BASE = 'http://localhost:8890';
const SHOTS = process.argv[ 2 ] || path.join( __dirname, '..', '..', '.e2e-screens' );
const FIXTURE = 'wp-content/plugins/edit-orders-for-woocommerce/tests/e2e/order-fixture.php';
const SETTINGS = `${ BASE }/wp-admin/admin.php?page=edit-orders-for-woocommerce&tab=settings`;
const created = [];
let failures = 0;

function cli( command ) {
	return execSync( `npx -y @wordpress/env@11 run cli ${ command }`, {
		env: { ...process.env, MSYS_NO_PATHCONV: '1' },
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
	} );
}

function fixture( args ) {
	return cli( `wp eval-file ${ FIXTURE } ${ args }` );
}

function createOrder( args ) {
	const out = fixture( `create ${ args }` );
	const id = out.match( /ORDER_ID=(\d+)/ );
	const view = out.match( /VIEW=(\S+)/ );
	if ( ! id ) {
		throw new Error( 'Could not create the test order.' );
	}
	created.push( id[ 1 ] );
	return { id: id[ 1 ], view: view ? view[ 1 ] : '' };
}

function setHpos( on ) {
	cli( 'wp wc hpos sync' );
	cli( `wp wc hpos ${ on ? 'enable' : 'disable' }` );
	const state = fixture( 'hpos' ).match( /HPOS=(on|off)/ );
	if ( ! state || state[ 1 ] !== ( on ? 'on' : 'off' ) ) {
		throw new Error( `HPOS did not switch ${ on ? 'on' : 'off' }.` );
	}
}

function check( label, condition, detail ) {
	if ( condition ) {
		console.log( `  PASS  ${ label }` );
	} else {
		failures++;
		console.log( `  FAIL  ${ label }${ detail ? ` (${ detail })` : '' }` );
	}
}

async function login( page, user ) {
	// Poll the address: the redirect after wp-login.php can finish before a listener attaches.
	for ( let attempt = 0; attempt < 3; attempt++ ) {
		await page.goto( `${ BASE }/wp-login.php`, { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( () => document.activeElement && 'user_login' === document.activeElement.id ).catch( () => {} );
		await page.fill( '#user_login', user );
		await page.fill( '#user_pass', 'password' );
		await page.click( '#wp-submit', { noWaitAfter: true } );
		for ( let i = 0; i < 60; i++ ) {
			if ( ! /wp-login\.php/.test( page.url() ) ) {
				await page.waitForLoadState( 'domcontentloaded' );
				return;
			}
			await page.waitForTimeout( 500 );
		}
	}
	throw new Error( `could not log in as ${ user }` );
}

async function shoot( page, name, locator ) {
	for ( const width of [ 1440, 390 ] ) {
		await page.setViewportSize( { width, height: 900 } );
		await page.waitForTimeout( 300 );
		const file = path.join( SHOTS, `${ name }-${ width }.png` );
		if ( locator ) {
			await locator.scrollIntoViewIfNeeded();
			await locator.screenshot( { path: file } );
		} else {
			await page.screenshot( { path: file, fullPage: true } );
		}
	}
	await page.setViewportSize( { width: 1440, height: 900 } );
}

// The orders list on whichever storage is on: the row of an order, and the view link.
async function ordersList( page, hpos, view ) {
	const url = hpos ? `${ BASE }/wp-admin/admin.php?page=wc-orders` : `${ BASE }/wp-admin/edit.php?post_type=shop_order`;
	await page.goto( view ? `${ url }&eofw_open=1` : url, { waitUntil: 'domcontentloaded' } );
}

function row( page, id ) {
	// HPOS rows are id="order-<id>", legacy rows id="post-<id>".
	return page.locator( `tr#order-${ id }, tr#post-${ id }` );
}

( async () => {
	fs.mkdirSync( SHOTS, { recursive: true } );
	fixture( 'settings-backup' );
	fixture( 'billing-on' );
	const browser = await chromium.launch( { channel: 'chrome', headless: true } );
	const errors = [];
	let hposWas = true;

	try {
		hposWas = 'on' === ( fixture( 'hpos' ).match( /HPOS=(on|off)/ ) || [] )[ 1 ];
		const open = createOrder( '1' );
		const shut = createOrder( '1' );
		fixture( `close ${ shut.id }` );

		const admin = await ( await browser.newContext( { viewport: { width: 1440, height: 900 } } ) ).newPage();
		admin.on( 'pageerror', ( e ) => errors.push( e.message ) );
		await login( admin, 'admin' );

		for ( const hpos of [ true, false ] ) {
			setHpos( hpos );
			const mode = hpos ? 'HPOS list' : 'legacy list';
			console.log( `Orders list (${ mode })` );

			await ordersList( admin, hpos, false );
			const badge = row( admin, open.id ).locator( '.eofw-open-badge' );
			const text = ( await badge.textContent().catch( () => '' ) ) || '';
			check( `${ mode }: the open order shows "Changes open, .. min left"`, /Changes open, \d+ min left/.test( text ), text );
			check( `${ mode }: the closed order shows no badge`, 0 === ( await row( admin, shut.id ).locator( '.eofw-open-badge' ).count() ) );
			const viewLink = admin.locator( '.subsubsub a', { hasText: 'Customer can still change' } );
			check( `${ mode }: the "Customer can still change" view link is there with a count`, /Customer can still change \(\d+\)/.test( ( await viewLink.textContent().catch( () => '' ) ) || '' ) );
			if ( hpos ) {
				await shoot( admin, 'orders-list-badge', admin.locator( '.wp-list-table' ) );
				// On a phone WordPress folds the extra columns into each row: open the row to show the badge.
				await admin.setViewportSize( { width: 390, height: 900 } );
				await row( admin, open.id ).locator( 'button.toggle-row' ).click();
				check( `${ mode }: on a phone the badge shows in the opened row`, await row( admin, open.id ).locator( '.eofw-open-badge' ).isVisible() );
				await row( admin, open.id ).screenshot( { path: path.join( SHOTS, 'orders-list-badge-row-opened-390.png' ) } );
				await admin.setViewportSize( { width: 1440, height: 900 } );
			}

			await Promise.all( [ admin.waitForLoadState( 'domcontentloaded' ), viewLink.click() ] );
			await admin.waitForLoadState( 'domcontentloaded' );
			check( `${ mode }: the view lists the open order`, 1 === ( await row( admin, open.id ).count() ) );
			check( `${ mode }: the view leaves out the closed order`, 0 === ( await row( admin, shut.id ).count() ) );
			check( `${ mode }: the view is marked current`, /current/.test( ( await admin.locator( '.subsubsub a', { hasText: 'Customer can still change' } ).getAttribute( 'class' ) ) || '' ) );
			if ( hpos ) {
				await shoot( admin, 'orders-list-view', null );
			}
		}
		setHpos( hposWas );

		console.log( 'Order screen' );
		await admin.goto( `${ BASE }/wp-admin/admin.php?page=wc-orders&action=edit&id=${ open.id }`, { waitUntil: 'domcontentloaded' } );
		if ( ! hposWas ) {
			await admin.goto( `${ BASE }/wp-admin/post.php?post=${ open.id }&action=edit`, { waitUntil: 'domcontentloaded' } );
		}
		const note = admin.locator( '#edit-orders-for-woocommerce .eofw-open-note' );
		check( 'the Edit order box says until when the customer can change it', /The customer can still change this order until \d/.test( ( await note.textContent().catch( () => '' ) ) || '' ) );
		await shoot( admin, 'order-screen-note', admin.locator( '#edit-orders-for-woocommerce' ) );

		console.log( 'Settings' );
		await admin.goto( SETTINGS, { waitUntil: 'load' } );
		check( 'two address switches: shipping and billing', 1 === ( await admin.locator( 'input[name="edit_orders_for_woocommerce_settings[action_address_shipping]"][type="checkbox"]' ).count() ) && 1 === ( await admin.locator( 'input[name="edit_orders_for_woocommerce_settings[action_address_billing]"][type="checkbox"]' ).count() ) );
		const productsRow = admin.locator( '.w-row', { hasText: 'Never offer changes for these products' } );
		const categoriesRow = admin.locator( '.w-row', { hasText: 'Never offer changes for these categories' } );
		check( 'the product and category exclusion rows are there', 1 === ( await productsRow.count() ) && 1 === ( await categoriesRow.count() ) );
		await productsRow.locator( 'input[data-w-chipfind]' ).fill( 'Mug' );
		const mug = productsRow.locator( 'label.w-chip', { hasText: /^Mug$/ } );
		check( 'the product search narrows the chips to "Mug"', await mug.isVisible() && ! ( await productsRow.locator( 'label.w-chip', { hasText: /^T-Shirt$/ } ).isVisible() ) );
		await mug.click();
		await Promise.all( [ admin.waitForNavigation(), admin.click( '.w-savebar button[type="submit"]' ) ] );
		await admin.waitForLoadState( 'load' );
		check( 'Mug is saved as excluded', await admin.locator( '.w-row', { hasText: 'Never offer changes for these products' } ).locator( 'label.w-chip', { hasText: /^Mug$/ } ).locator( 'input' ).isChecked() );
		await shoot( admin, 'settings-exclusions', admin.locator( '.w-row', { hasText: 'Never offer changes for these products' } ) );
		await shoot( admin, 'settings-address-switches', admin.locator( '.w-row', { hasText: 'Customers can' } ).first() );

		console.log( 'My Account' );
		const excluded = createOrder( '1 account EO-MUG' );
		const fresh = createOrder( '1' );
		const customer = await ( await browser.newContext( { viewport: { width: 1440, height: 900 } } ) ).newPage();
		customer.on( 'pageerror', ( e ) => errors.push( e.message ) );
		await login( customer, 'customer' );
		await customer.goto( excluded.view, { waitUntil: 'load' } );
		check( 'an excluded order shows "This order can\'t be changed online. Please contact us."', /This order can.t be changed online\. Please contact us\./.test( ( await customer.locator( '.eofw-panel' ).textContent().catch( () => '' ) ) || '' ) && 0 === ( await customer.locator( '.eofw-panel form' ).count() ) );
		await shoot( customer, 'account-excluded', customer.locator( '.eofw-panel' ) );
		await customer.goto( fresh.view, { waitUntil: 'load' } );
		check( 'an open order offers the delivery and the billing address', 1 === ( await customer.locator( 'summary', { hasText: 'Change the delivery address' } ).count() ) && 1 === ( await customer.locator( 'summary', { hasText: 'Change the billing address' } ).count() ) );
		await customer.locator( 'summary', { hasText: 'Change the billing address' } ).click();
		await customer.selectOption( '#eofw-billing-country', 'US' );
		check( 'the billing state follows the billing country', 1 === ( await customer.locator( 'select[name="billing[state]"] option[value="NY"]' ).count() ) );
		await shoot( customer, 'account-billing-form', customer.locator( '.eofw-panel' ) );

		check( 'no JavaScript errors', 0 === errors.length, errors.join( ' | ' ) );
	} catch ( e ) {
		failures++;
		console.log( `  FAIL  ${ e.message }` );
	} finally {
		try {
			setHpos( hposWas );
		} catch ( e ) {
			failures++;
			console.log( `  FAIL  restoring HPOS: ${ e.message }` );
		}
		fixture( 'settings-restore' );
		if ( created.length ) {
			fixture( `clean ${ created.join( ' ' ) }` );
		}
		await browser.close();
	}

	console.log( failures ? `${ failures } failed` : 'Open orders browser test passed.' );
	process.exit( failures ? 1 : 0 );
} )();
