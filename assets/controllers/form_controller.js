import { Controller } from '@hotwired/stimulus';

/**
 * Saves a form as soon as one of its controls changes.
 *
 * A switch that has to be followed by a save button is a switch people forget
 * to save, so the account page submits the form itself when the value moves.
 * The same controller carries the escape key of the forms that can be left,
 * which clicks the link that leads out of them.
 */
export default class extends Controller {
    static targets = ['cancel'];

    submit() {
        this.element.requestSubmit();
    }

    cancel() {
        this.cancelTarget?.click();
    }

    preventAttachment(event) {
        event.preventDefault();
    }
}
