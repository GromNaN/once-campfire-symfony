import { Controller } from '@hotwired/stimulus';

/**
 * Takes a file dropped anywhere on the page.
 *
 * Dropping a file on the window is what people try first, so the controller
 * hands it to the file control of the composer, which is the one that knows
 * what to do with it.
 */
export default class extends Controller {
    allow(event) {
        if (event.dataTransfer?.types?.includes('Files')) {
            event.preventDefault();
        }
    }

    drop(event) {
        const files = event.dataTransfer?.files;

        if (!files?.length) {
            return;
        }

        const input = document.querySelector('#composer input[type="file"]');

        if (!input) {
            return;
        }

        event.preventDefault();
        input.files = files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }
}
