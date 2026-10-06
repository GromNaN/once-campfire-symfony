import { Controller } from '@hotwired/stimulus';

/**
 * A few extra things a Turbo Frame can be asked to do.
 *
 * A frame can drop the mark that keeps it across a navigation, which is what
 * makes it load again, or be given the address it should load once the rest of
 * the page has settled.
 */
export default class extends Controller {
    unpermanize() {
        delete this.element.dataset.turboPermanent;
    }

    reload() {
        this.element.reload();
    }

    load({ params: { url } }) {
        setTimeout(() => (this.element.src = url), 0);
    }
}
