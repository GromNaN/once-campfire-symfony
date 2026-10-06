import { Controller } from '@hotwired/stimulus';

/**
 * Hands a value to the share sheet of the device.
 *
 * Not every browser has one, so a button that asks for one hides itself rather
 * than fail when it is missing. The value is either the single URL the
 * controller carries or the richer set of a title, a text and a URL.
 */
export default class extends Controller {
    static values = { value: String, url: String, title: String, text: String };

    connect() {
        this.element.hidden = !navigator.canShare;
    }

    async share() {
        if (!navigator.share) {
            return;
        }

        const data = { title: this.titleValue, text: this.textValue };
        const url = this.urlValue || this.valueValue;

        if (url) {
            data.url = url;
        }

        await navigator.share(data);
    }
}
