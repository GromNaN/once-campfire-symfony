import { Controller } from '@hotwired/stimulus';

/**
 * Takes a flashed notice off the page once its animation has run.
 *
 * The notice slides out on its own, and the controller is what removes the
 * element afterwards, so nothing invisible stays in the way of a click.
 */
export default class extends Controller {
    remove() {
        this.element.remove();
    }
}
