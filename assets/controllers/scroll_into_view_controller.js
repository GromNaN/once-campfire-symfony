import { Controller } from '@hotwired/stimulus';

/**
 * Brings the element it sits on into view.
 *
 * The editor of a message takes the place of the message itself, which may be
 * far up the list, so opening it scrolls back to where the writing happens.
 * The scroll waits for the next frame, so it runs once the editor is drawn.
 */
export default class extends Controller {
    connect() {
        requestAnimationFrame(() => {
            this.element.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    }
}
