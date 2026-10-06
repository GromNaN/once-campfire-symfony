import { Controller } from '@hotwired/stimulus';

/**
 * Plays the sound of a message.
 *
 * The controller is on the sound itself, so the button and the automatic play
 * that follows an arriving message both go through the same action. A browser
 * refuses to play audio until the reader has interacted with the page, so a
 * refused play is swallowed: the button is there and works.
 */
export default class extends Controller {
    static values = { url: String };

    play() {
        const audio = new Audio(this.urlValue);

        audio.play().catch(() => {});
    }
}
