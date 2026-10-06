import { Controller } from '@hotwired/stimulus';

/**
 * Shows a timestamp in the time zone of the reader.
 *
 * The server writes every timestamp in UTC, which is the wrong hour for almost
 * everyone. The controller rewrites each time element from the machine readable
 * value it carries: the clock of a message, and the date above a day of
 * messages.
 */
export default class extends Controller {
    static targets = ['time', 'date'];

    connect() {
        this.#format(this.timeTargets, { hour: '2-digit', minute: '2-digit' });
        this.#format(this.dateTargets, { year: 'numeric', month: 'long', day: 'numeric' });
    }

    #format(elements, options) {
        const format = new Intl.DateTimeFormat(undefined, options);

        elements.forEach((element) => {
            const date = new Date(element.dateTime);

            if (!Number.isNaN(date.getTime())) {
                element.textContent = format.format(date);
            }
        });
    }
}
