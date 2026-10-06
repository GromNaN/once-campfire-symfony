import { insertHTMLFragment, parseHTMLFragment, keepScroll, trimChildren } from "../helpers/dom_helpers.js"
import { ThreadStyle } from "./message_formatter.js"

// Reading the message list a page at a time.
//
// A room holds more messages than are worth keeping in a page, so the newest
// page is loaded and the rest is fetched as the reader scrolls to either end.
// The list is also trimmed once it grows past a few hundred messages, so that a
// room left open for a long time does not keep every message it ever showed.

const MAX_MESSAGES = 300
const MAX_MESSAGES_LEEWAY = 20

// Watches the first and last message and says when either becomes visible.
//
// Adding a page always reveals the element the list was extended from, so the
// first element is only reported when it had been out of sight before. The last
// element is reported every time, because a page may not fill the screen.
class ScrollTracker {
    #container
    #callback
    #intersectionObserver
    #mutationObserver
    #firstChildWasHidden

    constructor(container, callback) {
        this.#container = container
        this.#callback = callback
        this.#intersectionObserver = new IntersectionObserver(this.#handleIntersection.bind(this), { root: container })
        this.#mutationObserver = new MutationObserver(this.#childrenChanged.bind(this))

        this.#mutationObserver.observe(container, { childList: true })
    }

    connect() {
        this.#childrenChanged()
    }

    disconnect() {
        this.#intersectionObserver.disconnect()
    }

    #childrenChanged() {
        this.disconnect()

        if (this.#container.firstElementChild) {
            this.#firstChildWasHidden = false

            this.#intersectionObserver.observe(this.#container.firstElementChild)
            this.#intersectionObserver.observe(this.#container.lastElementChild)
        }
    }

    #handleIntersection(entries) {
        for (const entry of entries) {
            const isFirst = entry.target === this.#container.firstElementChild
            const significantReveal = (isFirst && this.#firstChildWasHidden) || !isFirst

            if (entry.isIntersecting) {
                if (significantReveal) this.#callback(entry.target)
            } else {
                if (isFirst) this.#firstChildWasHidden = true
            }
        }
    }
}

export default class MessagePaginator {
    #container
    #url
    #messageFormatter
    #allContentViewedCallback
    #scrollTracker
    #upToDate = true

    constructor(container, url, messageFormatter, allContentViewedCallback) {
        this.#container = container
        this.#url = url
        this.#messageFormatter = messageFormatter
        this.#allContentViewedCallback = allContentViewedCallback
        this.#scrollTracker = new ScrollTracker(container, this.#messageBecameVisible.bind(this))
    }

    monitor() {
        this.#scrollTracker.connect()
    }

    disconnect() {
        this.#scrollTracker.disconnect()
    }

    get upToDate() {
        return this.#upToDate
    }

    set upToDate(value) {
        this.#upToDate = value
    }

    async resetToLastPage() {
        this.upToDate = true
        await this.#showLastPage()
    }

    trimExcessMessages(top) {
        const overage = this.#container.children.length - MAX_MESSAGES

        if (overage > MAX_MESSAGES_LEEWAY) {
            trimChildren(overage, this.#container, top)

            if (!top) this.upToDate = false
        }
    }

    #messageBecameVisible(element) {
        const messageId = element.dataset.messageId
        const firstMessage = element === this.#container.firstElementChild
        const lastMessage = element === this.#container.lastElementChild

        if (messageId) {
            if (firstMessage) this.#addPage({ before: messageId }, true)

            if (lastMessage && !this.upToDate) {
                this.#addPage({ after: messageId }, false)
            }

            if (lastMessage && this.upToDate) {
                this.#allContentViewedCallback?.()
            }
        }
    }

    async #showLastPage() {
        const response = await this.#fetchPage()

        if (response.statusCode === 200) {
            const page = this.#formatPage(response)
            this.#container.replaceChildren(page)
        }
    }

    async #addPage(params, top) {
        const response = await this.#fetchPage(params)

        if (response.statusCode === 204 && !top) {
            this.upToDate = true
            this.#allContentViewedCallback?.()
        }

        if (response.statusCode === 200) {
            const page = this.#formatPage(response)
            const lastNewElement = page.lastElementChild

            keepScroll(this.#container, top, () => {
                insertHTMLFragment(page, this.#container, top)

                // The message the page was joined to keeps its threading.
                if (top && lastNewElement?.nextElementSibling) {
                    this.#messageFormatter.format(lastNewElement.nextElementSibling, ThreadStyle.thread)
                }
            })

            this.trimExcessMessages(!top)
        }
    }

    async #fetchPage(params = {}) {
        const url = new URL(this.#url, window.location.origin)

        for (const param in params) {
            url.searchParams.set(param, params[param])
        }

        const response = await fetch(url, { headers: { Accept: "text/html" } })

        return { statusCode: response.status, html: await response.text() }
    }

    #formatPage(response) {
        const fragment = parseHTMLFragment(response.html)

        for (const message of fragment.querySelectorAll(".message")) {
            this.#messageFormatter.format(message, ThreadStyle.thread)
        }

        return fragment
    }
}
