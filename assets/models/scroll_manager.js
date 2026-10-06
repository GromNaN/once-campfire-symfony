// Moving the message list without losing the reader.
//
// A message appended at the bottom should carry the list along with it only
// when the reader was already at the bottom; otherwise they are reading an
// older message and the page must stay where it is.
//
// The operations are chained: a scroll adjustment measured before a page is
// added is only correct once the previous adjustment has finished, so each one
// waits for the last.

const AUTO_SCROLL_THRESHOLD = 100

export default class ScrollManager {
    static #pendingOperations = Promise.resolve()

    #container

    constructor(container) {
        this.#container = container
    }

    async autoscroll(forceScroll, render = () => {}) {
        return this.#appendOperation(async () => {
            const wasNearEnd = this.#scrolledNearEnd

            await render()

            if (wasNearEnd || forceScroll) {
                this.#container.scrollTop = this.#container.scrollHeight
                return true
            } else {
                return false
            }
        })
    }

    async keepScroll(top, render) {
        return this.#appendOperation(async () => {
            const scrollTop = this.#container.scrollTop
            const scrollHeight = this.#container.scrollHeight

            await render()

            if (top) {
                this.#container.scrollTop = scrollTop + (this.#container.scrollHeight - scrollHeight)
            } else {
                this.#container.scrollTop = scrollTop
            }
        })
    }

    #appendOperation(operation) {
        ScrollManager.#pendingOperations = ScrollManager.#pendingOperations.then(operation)
        return ScrollManager.#pendingOperations
    }

    get #scrolledNearEnd() {
        return this.#distanceScrolledFromEnd <= AUTO_SCROLL_THRESHOLD
    }

    get #distanceScrolledFromEnd() {
        return this.#container.scrollHeight - this.#container.scrollTop - this.#container.clientHeight
    }
}
