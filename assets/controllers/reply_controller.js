import { Controller } from '@hotwired/stimulus';

/**
 * Answers a message.
 *
 * Replying is a mention of the author written in the composer, so the field is
 * prefilled with the name and focused. A name already written is left alone,
 * which is what makes a second click harmless.
 */
export default class extends Controller {
    static targets = ['author'];

    reply() {
        const field = document.querySelector('#composer textarea, #composer input[type="text"]');

        if (!field) {
            return;
        }

        const author = this.hasAuthorTarget ? this.authorTarget.textContent.trim() : '';
        const mention = author ? `@${author}` : '';

        if (mention && !field.value.includes(mention)) {
            field.value = field.value.trim() ? `${field.value.trim()} ${mention} ` : `${mention} `;
        }

        field.focus();
    }
}
