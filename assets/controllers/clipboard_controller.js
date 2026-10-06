import { Controller } from '@hotwired/stimulus';

/**
 * Copies a value to the clipboard.
 *
 * The value is read from the controller rather than from the element it is
 * attached to, so the same controller works for a read only field and for a
 * command shown in a list. The button says so briefly once the copy is done.
 */
export default class extends Controller {
    static values = { value: String };
    static classes = ['success'];

    async copy() {
        try {
            await navigator.clipboard.writeText(this.valueValue);
        } catch {
            // A browser that refuses the clipboard leaves the field selected so
            // the value can still be copied by hand.
            this.element.querySelector('input')?.select();
            return;
        }

        if (this.hasSuccessClass) {
            this.element.classList.add(...this.successClasses);
            setTimeout(() => this.element.classList.remove(...this.successClasses), 1500);
        }
    }
}
