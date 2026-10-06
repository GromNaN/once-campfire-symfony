import { Controller } from '@hotwired/stimulus';

/**
 * Signs the reader out.
 *
 * A device that signs out also stops receiving notifications, so the endpoint
 * of its push subscription is put in the form before it is sent, and the
 * subscription is dropped in the browser. The server removes the record it
 * belongs to, which is what keeps a signed out device from being notified.
 */
export default class extends Controller {
    static targets = ['pushSubscriptionEndpoint'];

    async logout(event) {
        event.preventDefault();
        await this.#unsubscribeFromWebPush();
        this.element.requestSubmit();
    }

    async #unsubscribeFromWebPush() {
        if (!('serviceWorker' in navigator)) {
            return;
        }

        const registration = await navigator.serviceWorker.getRegistration(window.location.origin);
        const subscription = await registration?.pushManager?.getSubscription();

        if (!subscription) {
            return;
        }

        this.pushSubscriptionEndpointTarget.value = subscription.endpoint;
        await subscription.unsubscribe();
    }
}
