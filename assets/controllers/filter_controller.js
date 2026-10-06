import { Controller } from '@hotwired/stimulus';

/**
 * Narrows a long list of people down to the ones a query matches.
 *
 * The list is a menu of rows, each carrying the name it is looked up by. A
 * non-empty query marks the matching rows, and the list is put in its active
 * state, which the stylesheet uses to hide the rows that were not marked.
 */
export default class extends Controller {
    static targets = ['list'];
    static classes = ['active', 'selected'];

    #timer;

    initialize() {
        this.filter = this.filter.bind(this);
    }

    disconnect() {
        clearTimeout(this.#timer);
    }

    filter(event) {
        clearTimeout(this.#timer);
        this.#timer = setTimeout(() => this.#apply(event.target.value), 300);
    }

    #apply(query) {
        this.#reset();

        if ('' === query) {
            return;
        }

        this.#matches(query).forEach((row) => row.classList.add(this.selectedClass));
        this.listTarget.classList.add(this.activeClass);
    }

    #reset() {
        this.listTarget.classList.remove(this.activeClass);

        this.listTarget.querySelectorAll(`.${this.selectedClass}`).forEach((row) => {
            row.classList.remove(this.selectedClass);
        });
    }

    #matches(query) {
        return this.listTarget.querySelectorAll(`[data-value*="${CSS.escape(query.toLowerCase())}"]`);
    }
}
