( function () {
	'use strict';

	var root = document.getElementById( 'lion-rdv-widget' );
	if ( ! root || typeof lionRdvSettings === 'undefined' ) {
		return;
	}

	var i18n = lionRdvSettings.i18n;
	var restUrl = lionRdvSettings.restUrl.replace( /\/$/, '' );

	var state = {
		step: 'service',
		service: null,
		days: [],
		activeDayIndex: 0,
		selectedSlot: null,
		formLoadedAt: Date.now(),
		submitting: false,
	};

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		attrs = attrs || {};
		Object.keys( attrs ).forEach( function ( key ) {
			if ( 'text' === key ) {
				node.textContent = attrs[ key ];
			} else if ( 0 === key.indexOf( 'on' ) && 'function' === typeof attrs[ key ] ) {
				node.addEventListener( key.slice( 2 ), attrs[ key ] );
			} else {
				node.setAttribute( key, attrs[ key ] );
			}
		} );
		( children || [] ).forEach( function ( child ) {
			if ( child ) {
				node.appendChild( child );
			}
		} );
		return node;
	}

	function clear( node ) {
		while ( node.firstChild ) {
			node.removeChild( node.firstChild );
		}
	}

	function showMessage( container, text, type ) {
		var msg = el( 'div', { class: 'lion-rdv-message is-' + type, text: text } );
		container.insertBefore( msg, container.firstChild );
	}

	function render() {
		clear( root );
		if ( 'service' === state.step ) {
			renderServiceStep();
		} else if ( 'slots' === state.step ) {
			renderSlotsStep();
		} else if ( 'form' === state.step ) {
			renderFormStep();
		} else if ( 'done' === state.step ) {
			renderDoneStep();
		}
	}

	function renderServiceStep() {
		var stepEl = el( 'div', { class: 'lion-rdv-step' } );
		stepEl.appendChild( el( 'h2', { text: i18n.chooseService } ) );

		var grid = el( 'div', { class: 'lion-rdv-service-grid' } );

		grid.appendChild(
			el(
				'button',
				{
					type: 'button',
					class: 'lion-rdv-service-card',
					onclick: function () {
						selectService( 'depannage' );
					},
				},
				[
					el( 'strong', { text: i18n.depannage } ),
					el( 'span', { text: i18n.depannageDesc } ),
				]
			)
		);

		grid.appendChild(
			el(
				'button',
				{
					type: 'button',
					class: 'lion-rdv-service-card',
					onclick: function () {
						selectService( 'entretien' );
					},
				},
				[
					el( 'strong', { text: i18n.entretien } ),
					el( 'span', { text: i18n.entretienDesc } ),
				]
			)
		);

		stepEl.appendChild( grid );
		root.appendChild( stepEl );
	}

	function selectService( service ) {
		state.service = service;
		state.step = 'slots';
		state.days = [];
		render();
		fetchSlots();
	}

	function fetchSlots() {
		var stepEl = el( 'div', { class: 'lion-rdv-step' } );
		stepEl.appendChild( backButton( 'service' ) );
		stepEl.appendChild( el( 'div', { class: 'lion-rdv-loading', text: i18n.loadingSlots } ) );
		clear( root );
		root.appendChild( stepEl );

		fetch( restUrl + '/creneaux?service=' + encodeURIComponent( state.service ) )
			.then( function ( response ) {
				return response.json().then( function ( data ) {
					return { ok: response.ok, data: data };
				} );
			} )
			.then( function ( result ) {
				if ( ! result.ok || ! result.data.success ) {
					state.step = 'slots';
					state.days = [];
					render();
					showMessage( root.querySelector( '.lion-rdv-step' ), ( result.data && result.data.message ) || i18n.genericError, 'error' );
					return;
				}
				state.days = result.data.days || [];
				state.activeDayIndex = 0;
				render();
			} )
			.catch( function () {
				state.step = 'slots';
				state.days = [];
				render();
				showMessage( root.querySelector( '.lion-rdv-step' ), i18n.genericError, 'error' );
			} );
	}

	function backButton( targetStep ) {
		return el(
			'button',
			{
				type: 'button',
				class: 'lion-rdv-back',
				onclick: function () {
					state.step = targetStep;
					render();
				},
			},
			[ document.createTextNode( i18n.back ) ]
		);
	}

	function renderSlotsStep() {
		var stepEl = el( 'div', { class: 'lion-rdv-step' } );
		stepEl.appendChild( backButton( 'service' ) );

		if ( ! state.days.length ) {
			stepEl.appendChild( el( 'p', { text: i18n.noSlots } ) );
			root.appendChild( stepEl );
			return;
		}

		stepEl.appendChild( el( 'h3', { text: i18n.chooseDay } ) );

		var daysWrap = el( 'div', { class: 'lion-rdv-days' } );
		state.days.forEach( function ( day, index ) {
			daysWrap.appendChild(
				el(
					'button',
					{
						type: 'button',
						class: 'lion-rdv-day-btn' + ( index === state.activeDayIndex ? ' is-active' : '' ),
						text: day.label,
						onclick: function () {
							state.activeDayIndex = index;
							render();
						},
					}
				)
			);
		} );
		stepEl.appendChild( daysWrap );

		stepEl.appendChild( el( 'h3', { text: i18n.chooseTime } ) );

		var slotsWrap = el( 'div', { class: 'lion-rdv-slots' } );
		var activeDay = state.days[ state.activeDayIndex ];
		activeDay.slots.forEach( function ( slot ) {
			slotsWrap.appendChild(
				el(
					'button',
					{
						type: 'button',
						class: 'lion-rdv-slot-btn',
						text: slot.label,
						onclick: function () {
							state.selectedSlot = slot;
							state.selectedDayLabel = activeDay.label;
							state.formLoadedAt = Date.now();
							state.step = 'form';
							render();
						},
					}
				)
			);
		} );
		stepEl.appendChild( slotsWrap );

		root.appendChild( stepEl );
	}

	function field( key, label, type, extra ) {
		var wrap = el( 'div', { class: 'lion-rdv-field' + ( extra && extra.full ? ' lion-rdv-full' : '' ) } );
		wrap.appendChild( el( 'label', { for: 'lion-rdv-' + key, text: label } ) );
		var inputAttrs = { id: 'lion-rdv-' + key, name: key, type: type || 'text' };
		if ( extra && extra.required ) {
			inputAttrs.required = 'required';
		}
		var input = 'textarea' === type ? el( 'textarea', inputAttrs ) : el( 'input', inputAttrs );
		wrap.appendChild( input );
		return { wrap: wrap, input: input };
	}

	function renderFormStep() {
		var stepEl = el( 'div', { class: 'lion-rdv-step' } );
		stepEl.appendChild( backButton( 'slots' ) );

		stepEl.appendChild(
			el( 'div', { class: 'lion-rdv-summary' }, [
				el( 'strong', { text: i18n.selectedSlot + ' : ' } ),
				document.createTextNode( state.selectedDayLabel + ' — ' + state.selectedSlot.label ),
			] )
		);

		stepEl.appendChild( el( 'h3', { text: i18n.yourInfo } ) );

		var form = el( 'form', { novalidate: 'novalidate' } );
		var grid = el( 'div', { class: 'lion-rdv-form-grid' } );

		var fFirst = field( 'first_name', i18n.firstName, 'text', { required: true } );
		var fLast = field( 'last_name', i18n.lastName, 'text', { required: true } );
		var fPhone = field( 'phone', i18n.phone, 'tel', { required: true } );
		var fEmail = field( 'email', i18n.email, 'email', { required: true } );
		var fAddress = field( 'address', i18n.address, 'text', { required: true, full: true } );
		var fPostal = field( 'postal_code', i18n.postalCode, 'text', { required: true } );
		var fCity = field( 'city', i18n.city, 'text', { required: true } );
		var fMessage = field( 'message', i18n.message, 'textarea', { full: true } );

		[ fFirst, fLast, fPhone, fEmail, fAddress, fPostal, fCity, fMessage ].forEach( function ( f ) {
			grid.appendChild( f.wrap );
		} );

		form.appendChild( grid );

		var honeypot = el( 'div', { class: 'lion-rdv-honeypot' } );
		var honeypotInput = el( 'input', { type: 'text', name: 'site_web', tabindex: '-1', autocomplete: 'off' } );
		honeypot.appendChild( honeypotInput );
		form.appendChild( honeypot );

		var submitBtn = el( 'button', { type: 'submit', class: 'lion-rdv-submit', text: i18n.confirm } );
		form.appendChild( submitBtn );

		form.addEventListener( 'submit', function ( evt ) {
			evt.preventDefault();
			if ( state.submitting ) {
				return;
			}

			var payload = {
				service: state.service,
				start: state.selectedSlot.start,
				end: state.selectedSlot.end,
				first_name: fFirst.input.value.trim(),
				last_name: fLast.input.value.trim(),
				phone: fPhone.input.value.trim(),
				email: fEmail.input.value.trim(),
				address: fAddress.input.value.trim(),
				postal_code: fPostal.input.value.trim(),
				city: fCity.input.value.trim(),
				message: fMessage.input.value.trim(),
				site_web: honeypotInput.value,
			};

			state.submitting = true;
			submitBtn.disabled = true;
			submitBtn.textContent = i18n.sending;

			fetch( restUrl + '/reserver', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( payload ),
			} )
				.then( function ( response ) {
					return response.json().then( function ( data ) {
						return { ok: response.ok, status: response.status, data: data };
					} );
				} )
				.then( function ( result ) {
					state.submitting = false;
					if ( result.ok && result.data.success ) {
						state.step = 'done';
						state.doneMessage = result.data.message;
						render();
						return;
					}

					if ( 409 === result.status ) {
						state.step = 'slots';
						render();
						fetchSlots();
						return;
					}

					submitBtn.disabled = false;
					submitBtn.textContent = i18n.confirm;
					showMessage( stepEl, ( result.data && result.data.message ) || i18n.genericError, 'error' );
				} )
				.catch( function () {
					state.submitting = false;
					submitBtn.disabled = false;
					submitBtn.textContent = i18n.confirm;
					showMessage( stepEl, i18n.genericError, 'error' );
				} );
		} );

		stepEl.appendChild( form );
		root.appendChild( stepEl );
	}

	function renderDoneStep() {
		var stepEl = el( 'div', { class: 'lion-rdv-step lion-rdv-confirmation' } );
		stepEl.appendChild( el( 'div', { class: 'lion-rdv-icon', text: '✓' } ) );
		stepEl.appendChild( el( 'p', { text: state.doneMessage } ) );
		stepEl.appendChild(
			el(
				'button',
				{
					type: 'button',
					class: 'lion-rdv-submit',
					text: i18n.startOver,
					onclick: function () {
						state = {
							step: 'service',
							service: null,
							days: [],
							activeDayIndex: 0,
							selectedSlot: null,
							formLoadedAt: Date.now(),
							submitting: false,
						};
						render();
					},
				}
			)
		);
		root.appendChild( stepEl );
	}

	render();
} )();
