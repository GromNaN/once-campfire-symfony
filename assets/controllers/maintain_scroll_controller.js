import { Controller } from '@hotwired/stimulus';

/**
 * Keeps the reader where they were.
 *
 * A message appended at the bottom would otherwise carry the list along with
 * it. When the reader is not already at the bottom, the controller puts the
 * scroll position back once the stream has been rendered, so reading an older
 * message is not interrupted by a new one.
 */
export default class extends Controller {
    beforeStreamRender(event) {
        const element = this.element;
        const atBottom = element.scrollHeight - element.scrollTop - element.clientHeight < 40;
        const top = element.scrollTop;
        const render = event.detail.render;

        event.detail.render = (stream) => {
            render(stream);

            if (!atBottom) {
                element.scrollTop = top;
            }
        };
    }
}
