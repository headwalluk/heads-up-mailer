/**
 * Heads Up Mailer admin JS.
 *
 * Currently provides one delegated click handler: any element with a
 * `data-hum-confirm` attribute prompts the user before the default
 * action proceeds. Used for destructive links like "Delete group".
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
