import { Controller } from '@hotwired/stimulus';

/**
 * The room list of the sidebar.
 *
 * The unread state belongs to the server: a room that receives a message is
 * redrawn with its dot by the Turbo Stream that arrives over Mercure, and a
 * room that is opened is redrawn without it. The one thing the server cannot
 * know is which room the reader has open right now, so the controller takes
 * the dot back off that room whenever a stream puts one on, which is what a
 * message arriving in the open room would otherwise do.
 */
export default class extends Controller {
    connect() {
        this.observer = new MutationObserver(() => this.#clearCurrent());
        this.observer.observe(this.element, { childList: true, subtree: true });
        this.#clearCurrent();
    }

    disconnect() {
        this.observer?.disconnect();
    }

    /**
     * The room the reader is looking at, as the message area declares it.
     */
    get #currentRoomId() {
        const area = document.querySelector('#message-area[data-room-id]');

        return area ? Number(area.dataset.roomId) : null;
    }

    #clearCurrent() {
        const roomId = this.#currentRoomId;

        if (roomId === null || Number.isNaN(roomId)) {
            return;
        }

        this.element.querySelector(`[data-room-id="${roomId}"]`)?.classList.remove('unread');
    }
}
