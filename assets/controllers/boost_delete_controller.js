import { Controller } from '@hotwired/stimulus';

/**
 * Takes a boost back off a message.
 *
 * The delete button stays out of the way until the reader touches the boost.
 * The removal is sent as a fetch so the element can be taken off the page
 * without a reload, which is what the server answers with: no content.
 */
export default class extends Controller {
    static targets = ['content', 'button'];
    static classes = ['reveal'];

    reveal() {
        this.element.classList.add(this.revealClass);
    }

    async perform(event) {
        event.preventDefault();

        const form = event.target.closest('form');

        if (!form) {
            return;
        }

        await fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });

        this.element.remove();
    }
}
