/**
 * Heads Up Mailer admin JS.
 *
 * Delegated handlers and screen behaviours:
 *
 * - `data-hum-confirm` on any element prompts the user before the
 *   default action proceeds (used for destructive links).
 * - `.nav-tab` elements wired with `data-tab="..."` toggle visibility
 *   of the matching `#<tab>-panel` element. State persists in the URL
 *   hash so reloads and deep links land on the right tab.
 * - `#hum-mb-test` POSTs the mailbox tab's current values to the
 *   `hum_test_mailbox` AJAX endpoint and prints the result inline.
 * - `#hum-mb-poll` triggers a synchronous mailbox poll using the
 *   stored credentials and prints the result inline.
 * - Group "add" screen: generates the slug from the name as the user
 *   types, with a `.hum-slug-manual-toggle` checkbox to edit it by hand.
 *
 * @since 0.1.0
 */

document.addEventListener('click', (event) => {
	const target = event.target.closest('[data-hum-confirm]');

	if (!target) {
		return;
	}

	const message = target.dataset.humConfirm || 'Are you sure?';

	if (!window.confirm(message)) {
		event.preventDefault();
	}
});

// Subscribers-list bulk select: header checkbox toggles every
// subscriber_ids[] checkbox in the same form.
document.addEventListener('change', (event) => {
	const master = event.target.closest('#hum-cb-all');

	if (!master) {
		return;
	}

	const form = master.closest('form');

	if (!form) {
		return;
	}

	form.querySelectorAll('input[type="checkbox"][name="subscriber_ids[]"]').forEach((cb) => {
		cb.checked = master.checked;
	});
});

// WooCommerce integration: per-group checkout-opt-in repeater.
// Rebuilds the hidden JSON whenever any row's checkbox or label
// changes so the saved option matches what the admin sees.
function humWcRebuildJson() {
	const hidden = document.getElementById('hum-wc-checkout-groups-json');

	if (!hidden) {
		return;
	}

	const rows = document.querySelectorAll('tr[data-slug]');
	const map = {};

	rows.forEach((row) => {
		const slug = row.dataset.slug;
		const cb = row.querySelector('.hum-wc-cb');
		const lbl = row.querySelector('.hum-wc-lbl');

		if (!slug || !cb) {
			return;
		}

		map[slug] = {
			at_checkout: !!cb.checked,
			label: lbl ? lbl.value : '',
		};
	});

	hidden.value = JSON.stringify(map);
}

document.addEventListener('change', (event) => {
	if (event.target.closest('.hum-wc-cb')) {
		humWcRebuildJson();
	}
});

document.addEventListener('input', (event) => {
	if (event.target.closest('.hum-wc-lbl')) {
		humWcRebuildJson();
	}
});

function humActivateTab(name) {
	if (!name) {
		return;
	}

	document.querySelectorAll('.nav-tab[data-tab]').forEach((t) => {
		t.classList.toggle('nav-tab-active', t.dataset.tab === name);
	});

	document.querySelectorAll('.tab-panel').forEach((panel) => {
		const match = panel.id === `${name}-panel`;
		panel.classList.toggle('active', match);
		panel.style.display = match ? 'block' : 'none';
	});
}

document.addEventListener('DOMContentLoaded', () => {
	const tabs = document.querySelectorAll('.nav-tab[data-tab]');

	if (tabs.length === 0) {
		return;
	}

	const fromHash = window.location.hash.replace(/^#/, '');
	const initial = fromHash || tabs[0].dataset.tab;
	humActivateTab(initial);

	tabs.forEach((tab) => {
		tab.addEventListener('click', (event) => {
			event.preventDefault();
			const name = tab.dataset.tab;
			if (!name) {
				return;
			}
			window.location.hash = name;
			humActivateTab(name);
		});
	});

	window.addEventListener('hashchange', () => {
		humActivateTab(window.location.hash.replace(/^#/, ''));
	});
});

async function humTestMailbox(button) {
	if (typeof humAdminData === 'undefined') {
		return;
	}

	const result = document.getElementById('hum-mb-test-result');
	const fieldValue = (id) => document.getElementById(id)?.value ?? '';

	const body = new FormData();
	body.append('action', 'hum_test_mailbox');
	body.append('nonce', humAdminData.testMailboxNonce);
	body.append('host', fieldValue('hum-mb-host'));
	body.append('port', fieldValue('hum-mb-port'));
	body.append('user', fieldValue('hum-mb-user'));
	body.append('password', fieldValue('hum-mb-password'));
	body.append('folder', fieldValue('hum-mb-folder'));
	body.append('tls', document.getElementById('hum-mb-tls')?.checked ? '1' : '0');
	body.append('validate_cert', document.getElementById('hum-mb-validate-cert')?.checked ? '1' : '0');

	button.disabled = true;
	if (result) {
		result.textContent = 'Testing…';
		result.className = 'description';
	}

	try {
		const response = await fetch(humAdminData.ajaxUrl, {
			method: 'POST',
			body: body,
			credentials: 'same-origin',
		});

		const json = await response.json();
		const message = json?.data?.message ?? (json?.success ? 'OK' : 'Failed');

		if (result) {
			result.textContent = message;
			result.className = json?.success ? 'description hum-mb-test-success' : 'description hum-mb-test-error';
		}
	} catch (err) {
		if (result) {
			result.textContent = 'Network error: ' + err.message;
			result.className = 'description hum-mb-test-error';
		}
	} finally {
		button.disabled = false;
	}
}

document.addEventListener('click', (event) => {
	const button = event.target.closest('#hum-mb-test');

	if (!button) {
		return;
	}

	event.preventDefault();
	humTestMailbox(button);
});

async function humPollMailbox(button) {
	if (typeof humAdminData === 'undefined') {
		return;
	}

	const result = document.getElementById('hum-mb-poll-result');

	const body = new FormData();
	body.append('action', 'hum_poll_mailbox');
	body.append('nonce', humAdminData.pollMailboxNonce);

	button.disabled = true;
	if (result) {
		result.textContent = 'Polling…';
		result.className = 'description';
	}

	try {
		const response = await fetch(humAdminData.ajaxUrl, {
			method: 'POST',
			body: body,
			credentials: 'same-origin',
		});

		const json = await response.json();
		const message = json?.data?.message ?? (json?.success ? 'OK' : 'Failed');

		if (result) {
			result.textContent = message;
			result.className = json?.success ? 'description hum-mb-poll-success' : 'description hum-mb-poll-error';
		}
	} catch (err) {
		if (result) {
			result.textContent = 'Network error: ' + err.message;
			result.className = 'description hum-mb-poll-error';
		}
	} finally {
		button.disabled = false;
	}
}

document.addEventListener('click', (event) => {
	const button = event.target.closest('#hum-mb-poll');

	if (!button) {
		return;
	}

	event.preventDefault();
	humPollMailbox(button);
});

// Group "add" screen: derive a slug from the name as the user types.
// A "Set the slug manually" checkbox reveals the slug field for hand
// editing; while it is unchecked the (hidden) slug input is kept in
// sync so it still posts a value. Mirrors sanitize_title() closely
// enough for a live preview — the server re-sanitises on save.
function humSlugify(value) {
	return value
		.toString()
		.toLowerCase()
		.replace(/[\s_]+/g, '-')
		.replace(/[^a-z0-9-]+/g, '')
		.replace(/-+/g, '-')
		.replace(/^-+|-+$/g, '');
}

document.addEventListener('DOMContentLoaded', () => {
	const toggle = document.querySelector('.hum-slug-manual-toggle');

	if (!toggle) {
		return;
	}

	const nameInput = document.getElementById('hum-group-name');
	const slugInput = document.getElementById('hum-group-slug');
	const preview = document.querySelector('.hum-slug-preview');
	const previewValue = document.querySelector('.hum-slug-value');
	const fieldWrap = document.querySelector('.hum-slug-field-wrap');

	if (!nameInput || !slugInput) {
		return;
	}

	const syncFromName = () => {
		if (toggle.checked) {
			return;
		}

		const slug = humSlugify(nameInput.value);
		slugInput.value = slug;

		if (previewValue) {
			previewValue.textContent = slug;
		}
	};

	nameInput.addEventListener('input', syncFromName);

	toggle.addEventListener('change', () => {
		const manual = toggle.checked;

		if (fieldWrap) {
			fieldWrap.hidden = !manual;
		}

		if (preview) {
			preview.hidden = manual;
		}

		// Only require (and pattern-validate via the browser) the slug
		// field while it is visible — a required hidden field cannot be
		// focused and would silently block submission.
		slugInput.required = manual;

		if (manual) {
			slugInput.focus();
		} else {
			syncFromName();
		}
	});

	// Prime the preview from any value already in the name field
	// (e.g. a validation bounce-back or browser autofill).
	syncFromName();
});
