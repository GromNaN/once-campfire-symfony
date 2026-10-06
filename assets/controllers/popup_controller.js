import { Controller } from '@hotwired/stimulus';

// The options menu of a message.
//
// The menu is a details element, which the browser already opens and closes on
// its own when its summary is clicked. What the browser does not do is close it
// when the reader clicks somewhere else or presses escape, and it does not keep
// it on the page: a menu opened near the bottom of the window would run off it.
const BOTTOM_THRESHOLD = 90;

export default class extends Controller {
    static targets = ['menu'];
    static classes = ['orientationTop'];

    close() {
        this.element.open = false;
    }

    toggle() {
        this.#orient();
    }

    closeOnClickOutside(event) {
        if (!this.element.contains(event.target)) {
            this.close();
        }
    }

    // A menu with less than a hundred pixels under it opens upwards, and it is
    // never wider than the room left between it and the edge of the window.
    #orient() {
        this.element.classList.toggle(this.orientationTopClass, this.#distanceToBottom < BOTTOM_THRESHOLD);
        this.menuTarget.style.setProperty('--max-width', `${this.#maxWidth}px`);
    }

    get #distanceToBottom() {
        return window.innerHeight - this.#boundingClientRect.bottom;
    }

    get #maxWidth() {
        return window.innerWidth - this.#boundingClientRect.left;
    }

    get #boundingClientRect() {
        return this.menuTarget.getBoundingClientRect();
    }
}
