import { Controller } from '@hotwired/stimulus';

/**
 * Says that the reader is watching the room.
 *
 * Mercure only pushes, so the "I am here" signal the original application gets
 * from opening a subscription is an explicit post instead. It is sent when the
 * page opens, renewed on a timer, and taken back when the reader leaves the
 * tab, which is what decides whether a message leaves the room unread.
 */
export default class extends Controller {
    static values = { url: String, interval: { type: Number, default: 45000 } };

    connect() {
        this.present();
        this.timer = setInterval(() => this.#refresh(), this.intervalValue);
    }

    disconnect() {
        clearInterval(this.timer);
        this.#post('absent');
    }

    visibilityChanged() {
        if (document.hidden) {
            this.#post('absent');
        } else {
            this.present();
        }
    }

    present() {
        this.#post('present');
    }

    #refresh() {
        if (!document.hidden) {
            this.#post('refresh');
        }
    }

    #post(action) {
        if (!this.hasUrlValue) {
            return;
        }

        fetch(this.urlValue, {
            method: 'POST',
            body: new URLSearchParams({ action }),
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            keepalive: true,
        });
    }
}
