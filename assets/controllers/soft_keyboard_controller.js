import { Controller } from '@hotwired/stimulus';

/**
 * Brings up the on screen keyboard of a phone.
 *
 * A button that is about to show a field focuses it, so the keyboard is already
 * up when the field appears rather than a tap later.
 */
export default class extends Controller {
    open() {
        const field = this.element.closest('.message')?.querySelector('input, textarea')
            || document.querySelector('#composer textarea, #composer input[type="text"]');

        field?.focus();
    }
}
