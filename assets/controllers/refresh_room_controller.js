import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';

/**
 * Catches the room up after the connection was lost.
 *
 * A browser that comes back to the tab, or back online, cannot replay what it
 * missed on the hub, so it asks the server for everything that changed since
 * the last time it heard from it and lets Turbo render the answer.
 */
export default class extends Controller {
    static values = { url: String, loadedAt: Number };

    visibilityChanged() {
        if (!document.hidden) {
            this.reload();
        }
    }

    online() {
        this.reload();
    }

    async reload() {
        if (!this.hasUrlValue) {
            return;
        }

        const url = new URL(this.urlValue, window.location.origin);
        url.searchParams.set('since', String((this.loadedAtValue || 0) * 1000));

        const response = await fetch(url, { headers: { Accept: 'text/vnd.turbo-stream.html' } });

        if (!response.ok) {
            return;
        }

        Turbo.renderStreamMessage(await response.text());
    }
}
