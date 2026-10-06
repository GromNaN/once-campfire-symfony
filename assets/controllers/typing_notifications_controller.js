import { Controller } from '@hotwired/stimulus';

/**
 * Tells the other people of a room that the reader is writing.
 *
 * The notice is posted once when the composer becomes non empty, and taken back
 * when it is emptied or sent, so a room is not flooded with notices for every
 * keystroke.
 */
export default class extends Controller {
    static targets = ['indicator', 'author'];
    static classes = ['active'];
    static values = { url: String };

    connect() {
        this.field = this.element.querySelector('textarea, input[type="text"]');
        this.writing = false;

        this.field?.addEventListener('input', () => this.#typed());
    }

    stop() {
        if (this.writing) {
            this.writing = false;
            this.#post('stop');
        }
    }

    #typed() {
        const writing = this.field.value.trim().length > 0;

        if (writing === this.writing) {
            return;
        }

        this.writing = writing;
        this.#post(writing ? 'start' : 'stop');
    }

    #post(action) {
        if (!this.hasUrlValue) {
            return;
        }

        fetch(this.urlValue, {
            method: 'POST',
            body: new URLSearchParams({ action }),
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
    }
}
