/**
 * Content Powered > Link Audit and Reports page behaviour. Only loaded on that page.
 *
 * - Scan box: runs the full scan in short AJAX batches while the page is
 *   open (WP-Cron carries on without it), then reloads.
 * - Domains: clicking a domain opens its links underneath it. The bulk
 *   actions, the per-domain buttons and Whitelist / Blacklist open a dialog
 *   that says exactly what will change, then run the change in batches with
 *   a progress bar and reload with an Undo link.
 * - Undo Log: a change that's still running is carried on from here, and
 *   Undo asks first.
 *
 * link_audit_reports_admin.ajaxUrl, .nonce, .filters, .actions and .i18n
 * are passed in from PHP (see Link_Audit_Reports_Admin::enqueue_assets).
 */

/* global link_audit_reports_admin */
( function () {
	'use strict';

	var data = window.link_audit_reports_admin;

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( typeof data === 'undefined' || ! window.fetch ) {
			return;
		}

		initScan();
		initReport();
		initRunning();
		initUndo();
	} );

	// AJAX -----------------------------------------------------------------------

	function post( action, fields ) {
		var body = new window.FormData();

		body.append( 'action', 'link_audit_reports_' + action );
		body.append( 'nonce', data.nonce );

		Object.keys( fields || {} ).forEach( function ( key ) {
			var value = fields[ key ];

			if ( Array.isArray( value ) ) {
				value.forEach( function ( item ) {
					body.append( key + '[]', item );
				} );
			} else if ( value && typeof value === 'object' ) {
				Object.keys( value ).forEach( function ( sub ) {
					body.append( key + '[' + sub + ']', value[ sub ] );
				} );
			} else {
				body.append( key, value );
			}
		} );

		return window.fetch( data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( response ) {
			return response.json();
		} );
	}

	function errorText( json ) {
		return json && json.data && json.data.message ? json.data.message : data.i18n.failed;
	}

	function pause( ms ) {
		return new Promise( function ( resolve ) {
			window.setTimeout( resolve, ms );
		} );
	}

	// Scan -----------------------------------------------------------------------

	function initScan() {
		var box = document.querySelector( '[data-lar-scan]' );

		if ( ! box ) {
			return;
		}

		var status = box.querySelector( '[data-lar-scan-status]' );
		var bar = box.querySelector( '[data-lar-scan-bar]' );
		var failures = 0;

		function step() {
			post( 'scan' )
				.then( function ( json ) {
					if ( ! json || ! json.success ) {
						throw new Error( 'scan' );
					}

					failures = 0;
					status.textContent = json.data.status;
					bar.style.width = json.data.percent + '%';

					if ( json.data.running ) {
						window.setTimeout( step, 400 );
					} else {
						window.location.reload();
					}
				} )
				.catch( function () {
					// A slow batch or a blip: try a few more times, then leave it to WP-Cron.
					failures++;
					if ( failures < 5 ) {
						window.setTimeout( step, 3000 );
					}
				} );
		}

		step();
	}

	// Report ---------------------------------------------------------------------

	function initReport() {
		var report = document.querySelector( '[data-lar-report]' );

		if ( ! report ) {
			return;
		}

		report.addEventListener( 'click', function ( event ) {
			var toggle = event.target.closest( '[data-lar-toggle]' );
			var action = event.target.closest( '[data-lar-action]' );
			var more = event.target.closest( '[data-lar-more]' );

			if ( toggle ) {
				event.preventDefault();
				toggleRow( toggle.closest( 'tr' ) );
			} else if ( action ) {
				event.preventDefault();
				openChange( action.getAttribute( 'data-lar-action' ), [ domainOf( action ) ] );
			} else if ( more ) {
				event.preventDefault();
				loadMore( more );
			}
		} );

		// Bulk actions: run them through the dialog instead of a page load.
		[ 'doaction', 'doaction2' ].forEach( function ( id ) {
			var button = document.getElementById( id );

			if ( ! button ) {
				return;
			}

			button.addEventListener( 'click', function ( event ) {
				var select = document.getElementById( 'doaction' === id ? 'bulk-action-selector-top' : 'bulk-action-selector-bottom' );
				var action = select ? select.value : '-1';

				if ( '-1' === action || ! data.actions[ action ] ) {
					return;
				}

				event.preventDefault();

				var domains = Array.prototype.map.call( report.querySelectorAll( 'input[name="domain[]"]:checked' ), function ( box ) {
					return box.value;
				} );

				if ( ! domains.length ) {
					window.alert( data.i18n.noneChosen ); // eslint-disable-line no-alert
					return;
				}

				openChange( action, domains );
			} );
		} );
	}

	function domainOf( element ) {
		var holder = element.closest( '[data-lar-domain]' );

		return holder ? holder.getAttribute( 'data-lar-domain' ) : '';
	}

	function toggleRow( row ) {
		if ( ! row || ! row.classList.contains( 'lar-row' ) ) {
			return;
		}

		var details = row.nextElementSibling;
		var button = row.querySelector( '.lar-toggle' );
		var open = ! row.classList.contains( 'is-open' );

		row.classList.toggle( 'is-open', open );
		if ( button ) {
			button.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		}
		Array.prototype.forEach.call( row.querySelectorAll( '.lar_show [data-lar-toggle]' ), function ( link ) {
			link.textContent = open ? data.i18n.hideLinks : data.i18n.showLinks;
		} );

		if ( details && details.classList.contains( 'lar-details' ) ) {
			details.hidden = ! open;
			return;
		}

		details = document.createElement( 'tr' );
		details.className = 'lar-details';
		details.setAttribute( 'data-lar-domain', row.getAttribute( 'data-lar-domain' ) );

		var cell = document.createElement( 'td' );
		cell.colSpan = row.children.length;
		cell.innerHTML = '<div class="lar-details-loading"></div>';
		cell.firstChild.textContent = data.i18n.loading;
		details.appendChild( cell );
		row.parentNode.insertBefore( details, row.nextSibling );

		post( 'details', { domain: row.getAttribute( 'data-lar-domain' ), offset: 0, filters: data.filters } )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					throw new Error( errorText( json ) );
				}

				// Built and escaped on the server.
				cell.innerHTML = json.data.html;
			} )
			.catch( function ( error ) {
				cell.firstChild.textContent = error && error.message ? error.message : data.i18n.failed;
			} );
	}

	function loadMore( button ) {
		var details = button.closest( '.lar-details' );
		var body = details.querySelector( '[data-lar-links]' );

		button.disabled = true;

		post( 'details', { domain: details.getAttribute( 'data-lar-domain' ), offset: button.getAttribute( 'data-lar-more' ), filters: data.filters } )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					throw new Error( 'more' );
				}

				body.insertAdjacentHTML( 'beforeend', json.data.rows );

				if ( json.data.next ) {
					button.setAttribute( 'data-lar-more', json.data.next );
					button.disabled = false;
				} else {
					button.parentNode.removeChild( button );
				}
			} )
			.catch( function () {
				button.disabled = false;
			} );
	}

	// Dialog ----------------------------------------------------------------------

	function openChange( action, domains ) {
		var dialog = document.querySelector( '[data-lar-dialog]' );
		var meta = data.actions[ action ];

		if ( ! dialog || ! meta || ! domains.length ) {
			return;
		}

		var title = dialog.querySelector( '[data-lar-dialog-title]' );
		var icon = dialog.querySelector( '[data-lar-dialog-icon]' );
		var body = dialog.querySelector( '[data-lar-dialog-body]' );
		var options = dialog.querySelector( '[data-lar-dialog-options]' );
		var progress = dialog.querySelector( '[data-lar-dialog-progress]' );
		var bar = dialog.querySelector( '[data-lar-dialog-bar]' );
		var status = dialog.querySelector( '[data-lar-dialog-status]' );
		var confirm = dialog.querySelector( '[data-lar-dialog-confirm]' );
		var cancels = dialog.querySelectorAll( '[data-lar-dialog-cancel]' );
		var applyLabel = dialog.querySelector( '[data-lar-apply-label]' );
		var apply = dialog.querySelector( 'input[name="lar_apply"]' );
		var running = false;
		var preview = null;

		title.textContent = meta.label;
		icon.innerHTML = meta.icon; // Bundled SVG from PHP.
		dialog.classList.toggle( 'is-strip', 'strip' === action );
		body.innerHTML = '';
		say( data.i18n.loading );
		options.hidden = true;
		progress.hidden = true;
		bar.style.width = '0';
		confirm.textContent = meta.label;
		confirm.disabled = true;
		setCancel( true );

		function say( text, isError ) {
			var p = document.createElement( 'p' );

			p.textContent = text;
			if ( isError ) {
				p.className = 'is-error';
			}
			body.appendChild( p );
		}

		function setCancel( enabled ) {
			Array.prototype.forEach.call( cancels, function ( button ) {
				button.disabled = ! enabled;
			} );
		}

		function mode() {
			var checked = options.querySelector( 'input[name="lar_mode"]:checked' );

			return checked ? checked.value : 'strip';
		}

		function updateApply() {
			if ( preview && preview.apply ) {
				applyLabel.textContent = preview.apply[ mode() ];
				apply.closest( 'label' ).hidden = preview.apply.none;
			}
		}

		function close() {
			if ( running ) {
				return;
			}

			options.removeEventListener( 'change', updateApply );
			confirm.removeEventListener( 'click', start );
			Array.prototype.forEach.call( cancels, function ( button ) {
				button.removeEventListener( 'click', close );
			} );
			dialog.removeEventListener( 'cancel', onCancel );

			if ( dialog.open ) {
				dialog.close();
			}
		}

		function onCancel( event ) {
			if ( running ) {
				event.preventDefault();
			} else {
				close();
			}
		}

		function fail( message ) {
			running = false;
			progress.hidden = true;
			body.innerHTML = '';
			say( message, true );
			confirm.disabled = true;
			setCancel( true );
		}

		function start() {
			running = true;
			confirm.disabled = true;
			setCancel( false );
			options.hidden = true;
			progress.hidden = false;
			status.textContent = data.i18n.working;

			runJob(
				{
					step: 'start',
					change: action,
					domains: domains,
					filters: data.filters,
					mode: mode(),
					apply: apply.checked && ! apply.closest( 'label' ).hidden ? 1 : 0,
				},
				function ( info ) {
					bar.style.width = info.percent + '%';
					status.textContent = info.text;
				}
			)
				.then( function ( id ) {
					var url = new window.URL( window.location.href );

					url.searchParams.delete( 'paged' );
					url.searchParams.set( 'lar_done', id );
					running = false;
					window.location.href = url.toString();
				} )
				.catch( function ( error ) {
					fail( error && error.message ? error.message : data.i18n.failed );
				} );
		}

		options.addEventListener( 'change', updateApply );
		confirm.addEventListener( 'click', start );
		Array.prototype.forEach.call( cancels, function ( button ) {
			button.addEventListener( 'click', close );
		} );
		dialog.addEventListener( 'cancel', onCancel );

		if ( typeof dialog.showModal === 'function' ) {
			dialog.showModal();
		} else {
			dialog.setAttribute( 'open', '' );
		}

		post( 'preview', { change: action, domains: domains, filters: data.filters } )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					throw new Error( errorText( json ) );
				}

				preview = json.data;
				body.innerHTML = '';
				preview.lines.forEach( function ( line ) {
					say( line );
				} );

				confirm.textContent = preview.confirm;
				confirm.disabled = !! preview.disabled;

				if ( preview.apply ) {
					options.hidden = false;
					updateApply();
				}

				if ( ! confirm.disabled ) {
					confirm.focus();
				}
			} )
			.catch( function ( error ) {
				fail( error && error.message ? error.message : data.i18n.failed );
			} );
	}

	// Changes ---------------------------------------------------------------------

	/**
	 * Start or carry on a change until it's finished.
	 *
	 * @param {Object}   fields   First request: step "start" with the change, or step "run" with its id.
	 * @param {Function} onStep   Gets each progress report.
	 * @return {Promise<number>} The change's log ID.
	 */
	function runJob( fields, onStep ) {
		var failures = 0;

		function next( request ) {
			return post( 'job', request ).then(
				function ( json ) {
					if ( ! json || ! json.success ) {
						throw new Error( errorText( json ) );
					}

					failures = 0;
					onStep( json.data );

					if ( ! json.data.running ) {
						return json.data.id;
					}

					// Another batch (WP-Cron) may hold the lock: give it a moment.
					return pause( 300 ).then( function () {
						return next( { step: 'run', id: json.data.id } );
					} );
				},
				function ( error ) {
					// A network blip or a slow batch: retry a run step a few times.
					failures++;
					if ( 'run' === request.step && failures < 5 ) {
						return pause( 2000 ).then( function () {
							return next( request );
						} );
					}
					throw error;
				}
			);
		}

		return next( fields );
	}

	// Undo Log --------------------------------------------------------------------

	function initRunning() {
		var row = document.querySelector( '[data-lar-running]' );

		if ( ! row ) {
			return;
		}

		var bar = row.querySelector( '[data-lar-log-bar]' );

		runJob( { step: 'run', id: row.getAttribute( 'data-lar-running' ) }, function ( info ) {
			if ( bar ) {
				bar.style.width = info.percent + '%';
			}
		} )
			.then( function () {
				window.location.reload();
			} )
			.catch( function () {
				// Leave it to WP-Cron; the page shows its progress on the next load.
			} );
	}

	function initUndo() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-lar-undo]' ), function ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				if ( ! window.confirm( data.i18n.undoSure ) ) { // eslint-disable-line no-alert
					event.preventDefault();
				}
			} );
		} );
	}
}() );
