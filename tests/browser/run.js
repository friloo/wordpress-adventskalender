/**
 * Browser-Tests für das Frontend.
 *
 * Prüft im echten Browser, was sich mit PHP-Tests nicht abdecken lässt:
 * Öffnungs-Animation, Lightbox, Tastaturbedienung, Fokusführung,
 * Mosaikberechnung, Zustandsabgleich bei gecachten Seiten, verlinkbare
 * Türchen und die Zurück-Navigation.
 *
 * Nutzung:  node tests/browser/run.js
 *
 * Die Fixtures werden vorher mit PHP erzeugt und über einen lokalen
 * Webserver ausgeliefert – ein echter Ursprung ist nötig, weil die
 * History-API auf file:// nicht erlaubt ist.
 */
'use strict';

const { execFileSync, spawn } = require( 'child_process' );
const http = require( 'http' );
const path = require( 'path' );
const fs = require( 'fs' );

const ROOT = path.resolve( __dirname, '../..' );
const FIXTURES = path.join( __dirname, 'fixtures' );
const PORT = Number( process.env.AK_TEST_PORT || 8711 );
const BASE = `http://127.0.0.1:${ PORT }`;

let chromium;
try {
	( { chromium } = require( 'playwright' ) );
} catch ( e ) {
	console.error( 'Playwright ist nicht installiert – Browser-Tests übersprungen.' );
	console.error( 'Installation:  npm install -D playwright && npx playwright install chromium' );
	process.exit( 0 );
}

/* ----------------------------------------------------------- Testgerüst */

const tests = [];
let passed = 0;
let failed = 0;

function test( name, fn ) {
	tests.push( { name, fn } );
}

function ok( condition, message ) {
	if ( ! condition ) {
		throw new Error( message || 'Erwartung nicht erfüllt' );
	}
}

function equal( actual, expected, message ) {
	if ( actual !== expected ) {
		throw new Error( `${ message || 'Werte verschieden' }: erwartet ${ JSON.stringify( expected ) }, erhalten ${ JSON.stringify( actual ) }` );
	}
}

/* ------------------------------------------------------------- Hilfsmittel */

async function openPage( browser, file, options = {} ) {
	const context = await browser.newContext(
		Object.assign( { viewport: { width: 1280, height: 950 } }, options )
	);
	const page = await context.newPage();
	const errors = [];
	page.on( 'pageerror', ( e ) => errors.push( e.message ) );
	page.on( 'console', ( m ) => {
		if ( 'error' === m.type() ) {
			errors.push( m.text() );
		}
	} );
	await page.goto( `${ BASE }/${ file }` );
	await page.waitForTimeout( 450 );
	page.akErrors = errors;
	page.akContext = context;

	return page;
}

function findExecutable() {
	if ( process.env.AK_CHROMIUM ) {
		return process.env.AK_CHROMIUM;
	}
	const base = process.env.PLAYWRIGHT_BROWSERS_PATH;
	if ( base && fs.existsSync( base ) ) {
		for ( const dir of fs.readdirSync( base ) ) {
			const candidate = path.join( base, dir, 'chrome-linux', 'chrome' );
			if ( fs.existsSync( candidate ) ) {
				return candidate;
			}
		}
	}

	return undefined;
}

/* ------------------------------------------------------------------ Tests */

test( 'Raster: 24 Türchen, gesperrte ohne Vorschautext', async ( browser ) => {
	const page = await openPage( browser, 'kalender.html' );
	const result = await page.evaluate( () => {
		const html = document.querySelector( '[data-ak-calendar]' ).innerHTML;
		const leaked = [];
		for ( let d = 6; d <= 24; d++ ) {
			if ( html.includes( 'VORSCHAU-' + d ) ) {
				leaked.push( d );
			}
		}
		return {
			doors: document.querySelectorAll( '.ak-door' ).length,
			open: document.querySelectorAll( '.ak-door[data-state="closed"]' ).length,
			locked: document.querySelectorAll( '.ak-door[data-state="locked"]' ).length,
			leaked,
		};
	} );
	equal( result.doors, 24, 'Anzahl Türchen' );
	equal( result.open, 5, 'freigeschaltete Türchen am 5. Dezember' );
	equal( result.locked, 19, 'gesperrte Türchen' );
	equal( result.leaked.length, 0, `Vorschautexte gesperrter Türchen im DOM: ${ result.leaked }` );
	equal( page.akErrors.length, 0, 'JS-Fehler' );
} );

test( 'Türchen öffnen: Klappe auf, Lightbox mit Inhalt', async ( browser ) => {
	const page = await openPage( browser, 'kalender.html' );
	await page.locator( '.ak-door[data-day="3"]' ).click();
	await page.waitForTimeout( 1000 );
	const result = await page.evaluate( () => ( {
		isOpen: document.querySelector( '.ak-door[data-day="3"]' ).classList.contains( 'is-open' ),
		expanded: document.querySelector( '.ak-door[data-day="3"]' ).getAttribute( 'aria-expanded' ),
		lightbox: ! document.querySelector( '[data-ak-lightbox]' ).hidden,
		title: document.querySelector( '[data-ak-title]' ).textContent,
		body: document.querySelector( '[data-ak-content]' ).textContent.trim(),
		label: document.querySelector( '.ak-door[data-day="3"]' ).getAttribute( 'aria-label' ),
	} ) );
	ok( result.isOpen, 'Türchen bleibt offen' );
	equal( result.expanded, 'true', 'aria-expanded' );
	ok( result.lightbox, 'Lightbox sichtbar' );
	equal( result.title, 'Titel 3', 'Titel' );
	ok( result.body.includes( 'Inhalt von Tag 3.' ), 'Inhalt geladen' );
	ok( result.label.includes( 'erneut' ), 'Beschriftung für geöffnetes Türchen' );
} );

test( 'Gesperrtes Türchen: Hinweis statt Inhalt', async ( browser ) => {
	const page = await openPage( browser, 'kalender.html' );
	const result = await page.evaluate( async () => {
		const door = document.querySelector( '.ak-door[data-day="20"]' );
		door.click();
		await new Promise( ( r ) => setTimeout( r, 300 ) );
		return {
			toast: document.querySelector( '[data-ak-toast]' ).textContent,
			lightbox: ! document.querySelector( '[data-ak-lightbox]' ).hidden,
			open: door.classList.contains( 'is-open' ),
		};
	} );
	ok( result.toast.includes( '20. Dezember 2026' ), `Hinweis nennt das Datum: ${ result.toast }` );
	ok( ! result.lightbox, 'Lightbox bleibt zu' );
	ok( ! result.open, 'Türchen bleibt geschlossen' );
} );

test( 'Tastatur: Enter öffnet, Pfeile blättern, Escape schließt', async ( browser ) => {
	const page = await openPage( browser, 'kalender.html' );
	await page.locator( '.ak-door[data-day="2"]' ).focus();
	await page.keyboard.press( 'Enter' );
	await page.waitForTimeout( 900 );
	const opened = await page.evaluate( () => ( {
		inDialog: document.querySelector( '[data-ak-dialog]' ).contains( document.activeElement ),
		day: document.querySelector( '[data-ak-day]' ).textContent,
	} ) );
	ok( opened.inDialog, 'Fokus liegt im Dialog' );
	equal( opened.day, 'Türchen 2', 'richtiges Türchen' );

	await page.keyboard.press( 'ArrowRight' );
	await page.waitForTimeout( 700 );
	equal(
		await page.evaluate( () => document.querySelector( '[data-ak-day]' ).textContent ),
		'Türchen 3',
		'Pfeil rechts blättert weiter'
	);

	await page.keyboard.press( 'Escape' );
	await page.waitForTimeout( 400 );
	const closed = await page.evaluate( () => ( {
		hidden: document.querySelector( '[data-ak-lightbox]' ).hidden,
		focusOnDoor: document.activeElement.classList.contains( 'ak-door' ),
	} ) );
	ok( closed.hidden, 'Lightbox geschlossen' );
	ok( closed.focusOnDoor, 'Fokus zurück auf dem Türchen' );
} );

test( 'Fokus-Falle: Tabulator verlässt den Dialog nicht', async ( browser ) => {
	const page = await openPage( browser, 'kalender.html' );
	await page.locator( '.ak-door[data-day="1"]' ).click();
	await page.waitForTimeout( 900 );
	for ( let i = 0; i < 25; i++ ) {
		await page.keyboard.press( 'Tab' );
	}
	ok(
		await page.evaluate( () => document.querySelector( '[data-ak-dialog]' ).contains( document.activeElement ) ),
		'Fokus bleibt im Dialog'
	);
} );

test( 'Geöffnete Klappe verschwindet und verdeckt nichts', async ( browser ) => {
	const page = await openPage( browser, 'kalender.html' );
	const before = await page.evaluate(
		() => getComputedStyle( document.querySelector( '.ak-door[data-day="3"] .ak-door__flap' ) ).display
	);
	ok( 'none' !== before, 'vor dem Öffnen ist die Klappe da' );

	await page.locator( '.ak-door[data-day="3"]' ).click();
	await page.waitForTimeout( 1300 );
	const after = await page.evaluate( () => {
		const door = document.querySelector( '.ak-door[data-day="3"]' );
		const flap = door.querySelector( '.ak-door__flap' );
		const rect = door.getBoundingClientRect();
		// Liegt links daneben noch etwas von diesem Türchen?
		const left = document.elementFromPoint( rect.left - rect.width * 0.15, rect.top + rect.height / 2 );
		return {
			display: getComputedStyle( flap ).display,
			revealed: door.classList.contains( 'is-revealed' ),
			overlapsNeighbour: !! ( left && left.closest( '.ak-door' ) === door ),
		};
	} );
	equal( after.display, 'none', 'Klappe ist weg' );
	ok( after.revealed, 'Türchen ist als aufgedeckt markiert' );
	ok( ! after.overlapsNeighbour, 'nichts ragt mehr über den Nachbarn' );
} );

test( 'Gemerkte Türchen zeigen beim Laden gar keine Klappe', async ( browser ) => {
	const context = await browser.newContext( { viewport: { width: 1280, height: 950 } } );
	const page = await context.newPage();
	await page.goto( `${ BASE }/kalender.html` );
	await page.waitForTimeout( 450 );
	await page.locator( '.ak-door[data-day="2"]' ).click();
	await page.waitForTimeout( 1200 );

	await page.goto( `${ BASE }/kalender.html` );
	await page.waitForTimeout( 500 );
	const result = await page.evaluate( () => {
		const door = document.querySelector( '.ak-door[data-day="2"]' );
		return {
			revealed: door.classList.contains( 'is-revealed' ),
			display: getComputedStyle( door.querySelector( '.ak-door__flap' ) ).display,
			others: getComputedStyle( document.querySelector( '.ak-door[data-day="1"] .ak-door__flap' ) ).display,
		};
	} );
	ok( result.revealed, 'sofort aufgedeckt, ohne Animation' );
	equal( result.display, 'none', 'keine Klappe' );
	ok( 'none' !== result.others, 'ungeöffnete Türchen behalten ihre Klappe' );
	await context.close();
} );

test( 'Überschrift steht nicht doppelt', async ( browser ) => {
	const page = await openPage( browser, 'kalender.html' );

	await page.locator( '.ak-door[data-day="3"]' ).click();
	await page.waitForTimeout( 1000 );
	const andererTitel = await page.evaluate( () => ( {
		augenzeile: document.querySelector( '[data-ak-day]' ).hidden,
		titel: document.querySelector( '[data-ak-title]' ).textContent,
	} ) );
	ok( ! andererTitel.augenzeile, 'bei eigenem Titel bleibt die Augenzeile' );
	equal( andererTitel.titel, 'Titel 3', 'Titel' );

	await page.keyboard.press( 'Escape' );
	await page.waitForTimeout( 400 );
	await page.locator( '.ak-door[data-day="4"]' ).click();
	await page.waitForTimeout( 1000 );
	const gleicherTitel = await page.evaluate( () => ( {
		augenzeile: document.querySelector( '[data-ak-day]' ).hidden,
		titel: document.querySelector( '[data-ak-title]' ).textContent,
	} ) );
	ok( gleicherTitel.augenzeile, 'heißt das Türchen „Türchen 4“, verschwindet die Augenzeile' );
	equal( gleicherTitel.titel, 'Türchen 4', 'Überschrift bleibt' );
} );

test( 'Gecachte Seite: Zustand wird nachgezogen', async ( browser ) => {
	const page = await openPage( browser, 'veraltet.html' );
	const result = await page.evaluate( () => ( {
		open: document.querySelectorAll( '.ak-door[data-state="closed"]' ).length,
		locked: document.querySelectorAll( '.ak-door[data-state="locked"]' ).length,
		previews: [ ...document.querySelectorAll( '.ak-door[data-state="closed"]' ) ]
			.filter( ( d ) => /VORSCHAU-\d+/.test( d.textContent ) ).length,
	} ) );
	equal( result.open, 12, 'am 12. Dezember sind zwölf Türchen offen' );
	equal( result.locked, 12, 'zwölf bleiben gesperrt' );
	equal( result.previews, 12, 'Vorschauen wurden nachgeladen' );

	await page.locator( '.ak-door[data-day="10"]' ).click();
	await page.waitForTimeout( 1000 );
	ok(
		( await page.evaluate( () => document.querySelector( '[data-ak-content]' ).textContent ) ).includes( 'Inhalt von Tag 10.' ),
		'nachträglich freigeschaltetes Türchen lässt sich öffnen'
	);
} );

test( 'Verlinktes Türchen öffnet sich beim Laden', async ( browser ) => {
	const page = await openPage( browser, 'kalender.html?tuerchen=5' );
	await page.waitForTimeout( 900 );
	const result = await page.evaluate( () => ( {
		lightbox: ! document.querySelector( '[data-ak-lightbox]' ).hidden,
		title: document.querySelector( '[data-ak-title]' ).textContent,
		doorOpen: document.querySelector( '.ak-door[data-day="5"]' ).classList.contains( 'is-open' ),
	} ) );
	ok( result.lightbox, 'Lightbox offen' );
	equal( result.title, 'Titel 5', 'richtiges Türchen' );
	ok( result.doorOpen, 'Türchen sichtbar geöffnet' );
} );

test( 'Verlinktes gesperrtes Türchen zeigt nur den Hinweis', async ( browser ) => {
	const page = await openPage( browser, 'kalender.html?tuerchen=22' );
	await page.waitForTimeout( 900 );
	const result = await page.evaluate( () => ( {
		lightbox: ! document.querySelector( '[data-ak-lightbox]' ).hidden,
		toast: document.querySelector( '[data-ak-toast]' ).textContent,
	} ) );
	ok( ! result.lightbox, 'keine Lightbox' );
	ok( result.toast.includes( '22. Dezember' ), 'Hinweis erscheint' );
} );

test( 'Adresszeile und Zurück-Taste', async ( browser ) => {
	const page = await openPage( browser, 'kalender.html' );
	await page.locator( '.ak-door[data-day="5"]' ).click();
	await page.waitForTimeout( 1000 );
	ok( page.url().includes( 'tuerchen=5' ), `URL enthält das Türchen: ${ page.url() }` );

	// Blättern ersetzt den Eintrag, hängt keinen neuen an.
	await page.keyboard.press( 'ArrowLeft' );
	await page.waitForTimeout( 700 );
	ok( page.url().includes( 'tuerchen=4' ), 'URL folgt beim Blättern' );

	await page.goBack();
	await page.waitForTimeout( 600 );
	const afterBack = await page.evaluate( () => document.querySelector( '[data-ak-lightbox]' ).hidden );
	ok( afterBack, 'Zurück schließt die Lightbox' );
	ok( ! page.url().includes( 'tuerchen=' ), `URL wieder sauber: ${ page.url() }` );

	// Vorwärts öffnet sie wieder.
	await page.goForward();
	await page.waitForTimeout( 700 );
	ok(
		! ( await page.evaluate( () => document.querySelector( '[data-ak-lightbox]' ).hidden ) ),
		'Vorwärts öffnet erneut'
	);
} );

test( 'Schließen über das Kreuz räumt die Adresszeile auf', async ( browser ) => {
	const page = await openPage( browser, 'kalender.html' );
	await page.locator( '.ak-door[data-day="1"]' ).click();
	await page.waitForTimeout( 1000 );
	await page.locator( '.ak-lightbox__close' ).click();
	await page.waitForTimeout( 600 );
	ok( ! page.url().includes( 'tuerchen=' ), `URL ohne Parameter: ${ page.url() }` );
	ok(
		await page.evaluate( () => document.querySelector( '[data-ak-lightbox]' ).hidden ),
		'Lightbox zu'
	);
} );

test( 'Geöffnete Türchen bleiben nach dem Neuladen offen', async ( browser ) => {
	const context = await browser.newContext( { viewport: { width: 1280, height: 950 } } );
	const page = await context.newPage();
	await page.goto( `${ BASE }/kalender.html` );
	await page.waitForTimeout( 450 );
	await page.locator( '.ak-door[data-day="2"]' ).click();
	await page.waitForTimeout( 1000 );
	await page.locator( '.ak-lightbox__close' ).click();
	await page.waitForTimeout( 400 );

	await page.goto( `${ BASE }/kalender.html` );
	await page.waitForTimeout( 600 );
	ok(
		await page.evaluate( () => document.querySelector( '.ak-door[data-day="2"]' ).classList.contains( 'is-open' ) ),
		'Türchen 2 ist weiterhin offen'
	);
	ok(
		! ( await page.evaluate( () => document.querySelector( '.ak-door[data-day="1"]' ).classList.contains( 'is-open' ) ) ),
		'ungeöffnete Türchen bleiben zu'
	);
	await context.close();
} );

test( 'Mosaik deckt das Raster lückenlos ab', async ( browser ) => {
	const page = await openPage( browser, 'mosaik.html' );
	await page.waitForTimeout( 700 );
	const result = await page.evaluate( () => {
		const grid = document.querySelector( '.ak-grid' ).getBoundingClientRect();
		const fronts = [ ...document.querySelectorAll( '.ak-door__front' ) ];
		const sizes = new Set( fronts.map( ( f ) => getComputedStyle( f ).backgroundSize ) );
		const first = fronts[ 0 ].getBoundingClientRect();
		const cs = getComputedStyle( fronts[ 0 ] );
		const [ w, h ] = cs.backgroundSize.split( ' ' ).map( parseFloat );
		return {
			einheitlicheGroesse: sizes.size === 1,
			deckendBreite: w >= grid.width - 1,
			deckendHoehe: h >= grid.height - 1,
			inPixeln: cs.backgroundSize.includes( 'px' ),
			ersteKachelOben: Math.abs( first.top - grid.top ) < 2,
		};
	} );
	ok( result.einheitlicheGroesse, 'alle Türchen nutzen dieselbe Bildgröße' );
	ok( result.inPixeln, 'Mosaik rechnet in Pixeln, nicht in Prozent pro Zelle' );
	ok( result.deckendBreite, 'Bild deckt die Rasterbreite' );
	ok( result.deckendHoehe, 'Bild deckt die Rasterhöhe' );
	ok( result.ersteKachelOben, 'erste Kachel sitzt am Rasteranfang' );
} );

test( 'Telefon: drei Spalten, kein seitliches Scrollen', async ( browser ) => {
	const page = await openPage( browser, 'kalender.html', {
		viewport: { width: 390, height: 844 },
		isMobile: true,
		hasTouch: true,
	} );
	const result = await page.evaluate( () => {
		const cells = [ ...document.querySelectorAll( '.ak-cell' ) ];
		const top = cells[ 0 ].offsetTop;
		let columns = 0;
		for ( const cell of cells ) {
			if ( cell.offsetTop !== top ) {
				break;
			}
			columns++;
		}
		return {
			columns,
			overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
		};
	} );
	equal( result.columns, 3, 'Spalten auf dem Telefon' );
	equal( result.overflow, 0, 'kein horizontaler Überlauf' );
} );

/* ------------------------------------------------------------------ Ablauf */

async function main() {
	console.log( 'Fixtures erzeugen …' );
	execFileSync( 'php', [ path.join( __dirname, 'fixtures.php' ) ], { stdio: 'inherit' } );

	const server = spawn( 'php', [ '-S', `127.0.0.1:${ PORT }`, '-t', FIXTURES ], { stdio: 'ignore' } );
	const stop = () => {
		try {
			server.kill();
		} catch ( e ) {
			/* egal */
		}
	};
	process.on( 'exit', stop );

	// Auf den Server warten.
	for ( let i = 0; i < 50; i++ ) {
		const up = await new Promise( ( resolve ) => {
			const req = http.get( `${ BASE }/kalender.html`, ( res ) => {
				res.resume();
				resolve( true );
			} );
			req.on( 'error', () => resolve( false ) );
			req.setTimeout( 300, () => {
				req.destroy();
				resolve( false );
			} );
		} );
		if ( up ) {
			break;
		}
		await new Promise( ( r ) => setTimeout( r, 100 ) );
	}

	const browser = await chromium.launch( {
		executablePath: findExecutable(),
		args: [ '--no-sandbox' ],
	} );

	console.log( `\n\u001b[1m=== Browser-Tests (${ tests.length }) ===\u001b[0m` );
	for ( const { name, fn } of tests ) {
		let page;
		try {
			page = await fn( browser );
			console.log( `  OK   ${ name }` );
			passed++;
		} catch ( error ) {
			console.log( `FAIL  ${ name }\n        ${ error.message }` );
			failed++;
		} finally {
			for ( const context of browser.contexts() ) {
				await context.close();
			}
		}
	}

	await browser.close();
	stop();

	console.log( `\n\u001b[1m=== ${ passed + failed } Tests, ${ failed } fehlgeschlagen ===\u001b[0m` );
	process.exit( failed > 0 ? 1 : 0 );
}

main().catch( ( error ) => {
	console.error( error );
	process.exit( 1 );
} );
