import { Controller } from '@hotwired/stimulus';

/**
 * Toggles a class on the element the controller sits on.
 *
 * The sidebar is what uses it: the button that opens the menu is inside the
 * sidebar, and the class that slides it in belongs on the sidebar itself.
 */
export default class extends Controller {
    static classes = ['toggle'];

    toggle() {
        this.element.classList.toggle(this.toggleClass);
    }
}
