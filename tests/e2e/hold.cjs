/**
 * Browser test of 1.4.0, against the wp-env store (tasks/edit-orders-1.4-spec.md, section 5.2):
 * - the "Open for changes" hold: checkout, the thank-you page, My Account, the orders lists
 *   (HPOS and legacy) with the status pill, "Release now" and "All good";
 * - the settings: the hold switch and the six counters;
 * - the page extension points, through the dev-only fake Pro mu-plugin: a tab, a settings
 *   section that saves with the form, and the changed links.
 *
 * Run from the plugin folder, with the playwright package installed somewhere outside
 * the synced plugins folder and found through NODE_PATH:
 *   NODE_PATH=/path/to/node_modules node tests/e2e/hold.cjs [screenshot-dir]
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
const PAGE = `${ BASE }/wp-admin/admin.php?page=edit-orders-for-woocommerce`;
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
	if ( ! id ) {
		throw new Error( 'Could not create the test order.' );
	}
	created.push( id[ 1 ] );
	return {
		id: id[ 1 ],
		received: ( out.match( /RECEIVED=(\S+)/ ) || [] )[ 1 ] || '',
		view: ( out.match( /VIEW=(\S+)/ ) || [] )[ 1 ] || '',
	};
}

function status( id ) {
	return ( fixture( `status ${ id }` ).match( /STATUS=(\S+)/ ) || [] )[ 1 ] || '';
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

function row( page, id ) {
	return page.locator( `tr#order-${ id }, tr#post-${ id }` );
}

async function orderScreen( page, id, hpos ) {
	await page.goto( hpos ? `${ BASE }/wp-admin/admin.php?page=wc-orders&action=edit&id=${ id }` : `${ BASE }/wp-admin/post.php?post=${ id }&action=edit`, { waitUntil: 'domcontentloaded' } );
}

// A real checkout with the test gateway. Returns the order ID, or '' when the store's checkout
// doesn't offer the gateway (the Checkout block lists only gateways with block support).
async function checkout( page ) {
	const productId = cli( 'wp eval "echo wc_get_product_id_by_sku( \'EO-TSHIRT\' );"' ).trim().split( /\s+/ ).pop();
	await page.goto( `${ BASE }/?add-to-cart=${ productId }`, { waitUntil: 'domcontentloaded' } );
	await page.goto( `${ BASE }/checkout/`, { waitUntil: 'load' } );
	const classic = page.locator( '#payment_method_edit_orders_test' );
	const block = page.locator( 'input[value="edit_orders_test"]' );
	if ( await classic.count() ) {
		await classic.check( { force: true } );
		await Promise.all( [ page.waitForURL( /order-received/, { timeout: 60000 } ), page.click( '#place_order' ) ] );
	} else if ( await block.count() ) {
		await block.check( { force: true } );
		await Promise.all( [ page.waitForURL( /order-received/, { timeout: 60000 } ), page.click( '.wc-block-components-checkout-place-order-button' ) ] );
	} else {
		return '';
	}
	const id = ( page.url().match( /order-received\/(\d+)/ ) || [] )[ 1 ] || '';
	if ( id ) {
		created.push( id );
	}
	return id;
}

( async () => {
	fs.mkdirSync( SHOTS, { recursive: true } );
	fixture( 'settings-backup' );
	fixture( 'hold on' );
	const browser = await chromium.launch( { channel: 'chrome', headless: true } );
	const errors = [];
	let hposWas = true;

	try {
		hposWas = 'on' === ( fixture( 'hpos' ).match( /HPOS=(on|off)/ ) || [] )[ 1 ];

		const customer = await ( await browser.newContext( { viewport: { width: 1440, height: 900 } } ) ).newPage();
		customer.on( 'pageerror', ( e ) => errors.push( e.message ) );
		await login( customer, 'customer' );

		console.log( 'Checkout and the thank-you page' );
		let first = { id: await checkout( customer ).catch( () => '' ), view: '' };
		if ( first.id ) {
			check( 'a real checkout with the test gateway enters "Open for changes"', 'open-for-changes' === status( first.id ), status( first.id ) );
		} else {
			console.log( '  NOTE  the checkout page does not offer the test gateway (Checkout block): the order is paid through payment_complete() instead, as the gateway does' );
			first = createOrder( '1' );
			await customer.goto( first.received, { waitUntil: 'load' } );
			check( 'a paid order enters "Open for changes"', 'open-for-changes' === status( first.id ), status( first.id ) );
		}
		const windowLine = customer.locator( '.eofw-panel .eofw-window' );
		check( 'the thank-you page says "You can still change this order until <time>"', /You can still change this order until \S/.test( ( await windowLine.textContent().catch( () => '' ) ) || '' ), ( await windowLine.textContent().catch( () => '' ) ) || '' );
		await shoot( customer, 'thank-you-held', customer.locator( '.eofw-panel' ) );

		console.log( 'My Account' );
		await customer.goto( `${ BASE }/my-account/orders/`, { waitUntil: 'load' } );
		const accountRow = customer.locator( 'tr', { hasText: `#${ first.id }` } ).first();
		check( 'My Account\'s order list shows "Open for changes"', /Open for changes/.test( ( await accountRow.textContent().catch( () => '' ) ) || '' ) );
		await shoot( customer, 'account-orders-held', customer.locator( '.woocommerce-orders-table' ) );

		const admin = await ( await browser.newContext( { viewport: { width: 1440, height: 900 } } ) ).newPage();
		admin.on( 'pageerror', ( e ) => errors.push( e.message ) );
		await login( admin, 'admin' );

		for ( const hpos of [ true, false ] ) {
			setHpos( hpos );
			const mode = hpos ? 'HPOS list' : 'legacy list';
			await admin.goto( hpos ? `${ BASE }/wp-admin/admin.php?page=wc-orders` : `${ BASE }/wp-admin/edit.php?post_type=shop_order`, { waitUntil: 'domcontentloaded' } );
			const pill = row( admin, first.id ).first().locator( 'mark.order-status' ).first();
			const pillText = ( await pill.textContent().catch( ( e ) => e.message ) ) || '';
			const pillClass = ( await pill.getAttribute( 'class' ).catch( ( e ) => e.message ) ) || '';
			check( `${ mode }: the order shows the "Open for changes" pill`, /Open for changes/.test( pillText ) && /status-open-for-changes/.test( pillClass ), `${ pillText } | ${ pillClass }` );
			const background = await pill.evaluate( ( el ) => getComputedStyle( el ).backgroundColor ).catch( () => '' );
			check( `${ mode }: the pill has its own colour (light blue)`, 'rgb(216, 238, 251)' === background, background );
			check( `${ mode }: an "Open for changes" status view`, 1 === ( await admin.locator( '.subsubsub a', { hasText: 'Open for changes' } ).count() ) );
			if ( hpos ) {
				await shoot( admin, 'orders-list-held', admin.locator( '.wp-list-table' ) );
			}
		}
		setHpos( hposWas );

		console.log( 'Release now' );
		await orderScreen( admin, first.id, hposWas );
		const box = admin.locator( '#edit-orders-for-woocommerce' );
		check( 'the Edit order box says the order is open for changes', /Open for changes\./.test( ( await box.textContent().catch( () => '' ) ) || '' ) );
		await shoot( admin, 'order-box-held', box );
		await Promise.all( [ admin.waitForNavigation(), box.locator( 'a.eofw-release-now' ).click() ] );
		check( '"Release now" moves the order to Processing', 'processing' === status( first.id ), status( first.id ) );
		check( 'and the order note says who released it', 1 <= ( await admin.locator( '.note_content', { hasText: 'Released to Processing by' } ).count() ) );

		console.log( '"All good"' );
		const second = createOrder( '1' );
		await customer.goto( second.view, { waitUntil: 'load' } );
		check( 'the My Account order page says "You can still change this order until"', /You can still change this order until/.test( ( await customer.locator( '.eofw-panel' ).textContent().catch( () => '' ) ) || '' ) );
		await Promise.all( [ customer.waitForNavigation(), customer.locator( 'button.eofw-done' ).click() ] );
		check( '"All good, no changes needed" moves it to Processing', 'processing' === status( second.id ), status( second.id ) );

		console.log( 'Settings and counters' );
		await admin.goto( `${ PAGE }&tab=settings`, { waitUntil: 'load' } );
		const holdRow = admin.locator( '.w-row', { hasText: 'Hold new orders while customers can change them' } );
		check( 'the hold switch is on the settings page, on', 1 === ( await holdRow.count() ) && await holdRow.locator( 'input[type="checkbox"]' ).isChecked() );
		await shoot( admin, 'settings-hold-switch', holdRow );
		const tiles = admin.locator( '.w-stats > *' );
		check( 'six tiles in the counters strip', 6 === ( await tiles.count() ), String( await tiles.count() ) );
		await admin.setViewportSize( { width: 390, height: 900 } );
		const tops = await tiles.evaluateAll( ( els ) => els.map( ( el ) => Math.round( el.getBoundingClientRect().top ) ) );
		check( 'the strip wraps on a phone (more than one row)', new Set( tops ).size > 1, tops.join( ',' ) );
		await admin.setViewportSize( { width: 1440, height: 900 } );
		await shoot( admin, 'counters', admin.locator( '.w-stats' ) );

		console.log( 'Page extension points (fake Pro)' );
		fixture( 'fake-pro on' );
		await admin.goto( `${ PAGE }&tab=settings`, { waitUntil: 'load' } );
		check( 'P1: the added tab is in the header', 1 === ( await admin.locator( 'a', { hasText: 'Pro analytics' } ).count() ) );
		check( 'P4: the header\'s Support link is the filtered one', 1 <= ( await admin.locator( 'a[href="https://example.com/helpdesk"]' ).count() ) );
		check( 'P2: the section link is in the settings nav', 1 <= ( await admin.locator( 'a', { hasText: 'Pro window rules' } ).count() ) );
		const proField = admin.locator( 'input[name="edit_orders_test_fake_pro_value"]' );
		check( 'P2: the section is inside the settings form', 1 === ( await admin.locator( '#edit-orders-settings input[name="edit_orders_test_fake_pro_value"]' ).count() ) );
		await proField.fill( '42' );
		await Promise.all( [ admin.waitForNavigation(), admin.click( '.w-savebar button[type="submit"]' ) ] );
		check( 'P3: the field saves with the form', /VALUE=42/.test( fixture( 'fake-pro on' ) ) );
		await shoot( admin, 'settings-extension-section', admin.locator( '#fake-pro-rules' ).first() );
		await admin.goto( `${ PAGE }&tab=fake-pro`, { waitUntil: 'load' } );
		check( 'P1: the tab opens and prints its content with the filtered links', 1 === ( await admin.locator( '#eofw-fake-pro-tab a[href="https://example.com/pro-docs"]' ).count() ) );
		await admin.goto( `${ PAGE }&tab=help`, { waitUntil: 'load' } );
		check( 'P4: the Help tab uses the filtered Support link', 1 <= ( await admin.locator( 'a[href="https://example.com/helpdesk"]' ).count() ) );
		fixture( 'fake-pro off' );

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
		fixture( 'fake-pro off' );
		fixture( 'settings-restore' );
		if ( created.length ) {
			fixture( `clean ${ created.join( ' ' ) }` );
		}
		await browser.close();
	}

	console.log( failures ? `${ failures } failed` : 'Hold browser test passed.' );
	process.exit( failures ? 1 : 0 );
} )();
