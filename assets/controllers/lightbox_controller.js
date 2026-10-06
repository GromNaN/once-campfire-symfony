import { Controller } from '@hotwired/stimulus';

/**
 * Opens a picture full screen.
 *
 * A picture in a message is a link to the file itself, so the viewer takes over
 * that click and shows the thumbnail enlarged instead. The link stays a link,
 * which is what a browser without the dialog element falls back to.
 */
export default class extends Controller {
    static targets = ['dialog', 'zoomedImage', 'download'];

    open(event) {
        event.preventDefault();

        const link = event.currentTarget;
        const image = link.querySelector('img');

        if (!image || !this.hasDialogTarget) {
            window.location.href = link.href;

            return;
        }

        this.zoomedImageTarget.src = image.src;
        this.zoomedImageTarget.alt = link.dataset.lightboxAlt || '';

        const url = link.dataset.lightboxUrlValue || link.href;

        if (this.hasDownloadTarget) {
            this.downloadTarget.href = url;
        }

        this.element.querySelector('.lightbox__btn--share')?.setAttribute('data-share-value', url);
        this.dialogTarget.showModal();
    }

    reset() {
        this.zoomedImageTarget.src = '';
    }
}
