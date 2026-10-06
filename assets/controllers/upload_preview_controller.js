import { Controller } from '@hotwired/stimulus';

/**
 * Shows the picture that was just picked, before it is uploaded.
 *
 * The file is turned into an address the browser can read, which is what makes
 * the picture appear straight away. That address is released once the picture
 * has been read, because it holds the whole file in memory until then.
 */
export default class extends Controller {
    static targets = ['image', 'input'];

    previewImage() {
        const file = this.inputTarget.files[0];

        if (!file) {
            return;
        }

        this.imageTarget.src = URL.createObjectURL(file);
        this.imageTarget.onload = () => URL.revokeObjectURL(this.imageTarget.src);
    }
}
