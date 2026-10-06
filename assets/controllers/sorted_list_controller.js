import { Controller } from '@hotwired/stimulus';

/**
 * Keeps a list in order as items are added to it.
 *
 * A conversation that receives a message goes back to the top of the list, and
 * the rooms of the sidebar stay in name order. Only the elements are moved, so
 * a stream that adds one item is enough to put the list right.
 */
export default class extends Controller {
    connect() {
        this.update();
    }

    update() {
        const items = [...this.element.children];

        if (items.length < 2) {
            return;
        }

        // A list either carries a number to sort by, or a name to sort by.
        const byNumber = items.some((item) => item.dataset.sortedListNumber !== undefined);
        const compare = byNumber
            ? (a, b) => Number(b.dataset.sortedListNumber || 0) - Number(a.dataset.sortedListNumber || 0)
            : (a, b) => (a.dataset.sortedListName || '').localeCompare(b.dataset.sortedListName || '', undefined, { sensitivity: 'base' });

        items.sort(compare).forEach((item) => this.element.append(item));
    }
}
