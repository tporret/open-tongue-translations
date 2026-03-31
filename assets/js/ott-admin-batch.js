/**
 * Open Tongue Translations — Admin Batch Progress
 *
 * Polls /wp-json/ott/v1/batch/status and updates the progress bar UI.
 * Triggered when the admin clicks "Run Batch Translation" on the Dashboard tab.
 *
 * Localised data injected by PHP via wp_localize_script() as window.ottBatch:
 *   ottBatch.restBase     — WP REST API root URL (e.g. /wp-json/)
 *   ottBatch.nonce        — X-WP-Nonce for authenticated requests
 *   ottBatch.targetLang   — current ltp_target_lang option value
 *   ottBatch.i18n.start   — "Start" button label
 *   ottBatch.i18n.running — "Running…" status label
 *   ottBatch.i18n.done    — "Complete" status label
 *   ottBatch.i18n.idle    — "Idle" status label
 *   ottBatch.i18n.eta     — "ETA: %s" label template
 */

( function () {
	'use strict';

	/** @type {{ restBase: string, nonce: string, targetLang: string, i18n: Record<string,string> }} */
	var cfg = window.ottBatch || {};

	/** Polling interval in milliseconds. */
	var POLL_MS = 2500;

	/** Active polling timer handle. */
	var pollTimer = null;

	/** Current job ID returned by /batch/start. */
	var currentJobId = null;

	// -------------------------------------------------------------------------
	// DOM references (resolved once on DOMContentLoaded)
	// -------------------------------------------------------------------------
	var $btn         = null;
	var $barWrap     = null;
	var $bar         = null;
	var $status      = null;
	var $pct         = null;
	var $eta         = null;
	var $doneCount   = null;
	var $failedCount = null;

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Authenticated fetch wrapper using the WP REST nonce.
	 *
	 * @param {string} url
	 * @param {RequestInit} [opts]
	 * @returns {Promise<Response>}
	 */
	function apiFetch( url, opts ) {
		var headers = Object.assign( {
			'Content-Type': 'application/json',
			'X-WP-Nonce':   cfg.nonce || '',
		}, ( opts && opts.headers ) || {} );

		return fetch( url, Object.assign( {}, opts || {}, { headers: headers } ) );
	}

	/**
	 * Build a full REST URL relative to ottBatch.restBase.
	 *
	 * @param {string} path
	 * @returns {string}
	 */
	function restUrl( path ) {
		var base = ( cfg.restBase || '/wp-json/' ).replace( /\/$/, '' );
		return base + '/' + path.replace( /^\//, '' );
	}

	// -------------------------------------------------------------------------
	// UI updaters
	// -------------------------------------------------------------------------

	/**
	 * Update all UI elements from a status response payload.
	 *
	 * @param {{ status: string, total: number, done: number, failed: number, pct: number, eta_seconds: number|null }} data
	 */
	function updateUI( data ) {
		var pct = Math.min( 100, Math.max( 0, data.pct || 0 ) );

		$bar.style.width = pct + '%';
		$bar.setAttribute( 'aria-valuenow', String( pct ) );
		$pct.textContent  = pct + '%';

		if ( $doneCount )   $doneCount.textContent   = String( data.done   || 0 );
		if ( $failedCount ) $failedCount.textContent = String( data.failed || 0 );

		if ( data.eta_seconds != null && data.eta_seconds > 0 ) {
			$eta.textContent = formatEta( data.eta_seconds );
			$eta.hidden = false;
		} else {
			$eta.hidden = true;
		}

		var statusLabel;
		switch ( data.status ) {
			case 'running':
				statusLabel = cfg.i18n.running || 'Running…';
				$btn.disabled = true;
				$btn.textContent = statusLabel;
				break;
			case 'complete':
				statusLabel = cfg.i18n.done || 'Complete';
				$btn.disabled = false;
				$btn.textContent = cfg.i18n.start || 'Run Batch Translation';
				stopPolling();
				break;
			case 'idle':
			case 'expired':
				statusLabel = cfg.i18n.idle || 'Idle';
				stopPolling();
				break;
			default:
				statusLabel = data.status || '';
		}

		$status.textContent = statusLabel;
	}

	/**
	 * Format seconds into a human-readable ETA string.
	 *
	 * @param {number} seconds
	 * @returns {string}
	 */
	function formatEta( seconds ) {
		if ( seconds < 60 ) {
			return seconds + 's';
		}
		var mins = Math.floor( seconds / 60 );
		var secs = seconds % 60;
		return mins + 'm ' + secs + 's';
	}

	// -------------------------------------------------------------------------
	// Polling
	// -------------------------------------------------------------------------

	function startPolling( jobId ) {
		currentJobId = jobId;
		poll();
	}

	function stopPolling() {
		if ( pollTimer !== null ) {
			clearTimeout( pollTimer );
			pollTimer = null;
		}
	}

	function poll() {
		var url = restUrl( 'ott/v1/batch/status' );
		if ( currentJobId ) {
			url += '?job_id=' + encodeURIComponent( currentJobId );
		}

		apiFetch( url )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}
				return response.json();
			} )
			.then( function ( data ) {
				updateUI( data );
				if ( data.status === 'running' ) {
					pollTimer = setTimeout( poll, POLL_MS );
				}
			} )
			.catch( function ( err ) {
				console.warn( '[OTT Batch] Poll error:', err );
				// Retry after a longer interval on error.
				pollTimer = setTimeout( poll, POLL_MS * 4 );
			} );
	}

	// -------------------------------------------------------------------------
	// Start handler
	// -------------------------------------------------------------------------

	function onStartClick() {
		$btn.disabled    = true;
		$status.textContent = cfg.i18n.running || 'Running…';
		$barWrap.hidden  = false;

		apiFetch( restUrl( 'ott/v1/batch/start' ), {
			method: 'POST',
			body:   JSON.stringify( { target_lang: cfg.targetLang || 'en' } ),
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( data ) {
				if ( data.job_id ) {
					startPolling( data.job_id );
					updateUI( data );
				} else {
					// idle / no pending strings
					updateUI( Object.assign( { status: 'idle', pct: 0, done: 0, failed: 0 }, data ) );
					$btn.disabled = false;
					$btn.textContent = cfg.i18n.start || 'Run Batch Translation';
				}
			} )
			.catch( function ( err ) {
				console.error( '[OTT Batch] Start error:', err );
				$btn.disabled    = false;
				$status.textContent = 'Error — check console';
			} );
	}

	// -------------------------------------------------------------------------
	// Bootstrap
	// -------------------------------------------------------------------------

	document.addEventListener( 'DOMContentLoaded', function () {
		$btn         = document.getElementById( 'ott-batch-start-btn' );
		$barWrap     = document.getElementById( 'ott-batch-bar-wrap' );
		$bar         = document.getElementById( 'ott-batch-bar' );
		$status      = document.getElementById( 'ott-batch-status' );
		$pct         = document.getElementById( 'ott-batch-pct' );
		$eta         = document.getElementById( 'ott-batch-eta' );
		$doneCount   = document.getElementById( 'ott-batch-done' );
		$failedCount = document.getElementById( 'ott-batch-failed' );

		if ( ! $btn ) {
			// Not on a page that has the batch UI — bail.
			return;
		}

		$btn.addEventListener( 'click', onStartClick );

		// If a job is already running (page reload mid-job), auto-resume polling.
		var existing = ( typeof cfg !== 'undefined' ) && cfg.activeJobId;
		if ( existing ) {
			$barWrap.hidden = false;
			startPolling( existing );
		}
	} );
}() );
