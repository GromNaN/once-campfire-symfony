// Reading the classes a message carries off the element itself.
//
// A message that arrives over the stream is appended without anybody knowing
// what it is: whether the reader wrote it, whether it mentions them, whether it
// carries on from the message above. The classes are worked out here from the
// element and the one before it.

const THREADING_TIME_WINDOW_MILLISECONDS = 5 * 60 * 1000 // 5 minutes

export const ThreadStyle = {
    none: 0,
    thread: 1,
}

export default class MessageFormatter {
    #userId
    #classes
    #dateFormatter = new Intl.DateTimeFormat(undefined, { dateStyle: "short" })

    constructor(userId, classes) {
        this.#userId = userId
        this.#classes = classes
    }

    format(message, threadstyle) {
        this.#setMeClass(message)
        this.#highlightMentions(message)

        if (threadstyle !== ThreadStyle.none) {
            this.#threadMessage(message)
            this.#setFirstOfDayClass(message)
        }

        this.#makeVisible(message)
    }

    #setMeClass(message) {
        message.classList.toggle(this.#classes.me, message.dataset.userId == this.#userId)
    }

    #makeVisible(message) {
        message.classList.add(this.#classes.formatted)
    }

    // The day separator is shown on the first message of a day, and folded away
    // on the ones that follow it on the same day.
    #setFirstOfDayClass(message) {
        let showSeparator = true

        if (message.dataset.messageTimestamp && message.previousElementSibling?.dataset?.messageTimestamp) {
            const previous = new Date(Number(message.previousElementSibling.dataset.messageTimestamp))
            const current = new Date(Number(message.dataset.messageTimestamp))

            showSeparator = this.#dateFormatter.format(previous) !== this.#dateFormatter.format(current)
        }

        message.classList.toggle(this.#classes.firstOfDay, showSeparator)
    }

    // A message reads as a continuation when the same person wrote the one
    // above it, not long before.
    #threadMessage(message) {
        if (message.previousElementSibling) {
            const sameUser = message.previousElementSibling.dataset.userId == message.dataset.userId

            message.classList.toggle(this.#classes.threaded, sameUser && this.#previousMessageIsRecent(message))
        }
    }

    #highlightMentions(message) {
        message.classList.toggle(this.#classes.mentioned, message.querySelector(this.#selectorForCurrentUser) !== null)
    }

    #previousMessageIsRecent(message) {
        const previousTimestamp = message.previousElementSibling.dataset.messageTimestamp
        const threadTimestamp = message.dataset.messageTimestamp

        return Math.abs(previousTimestamp - threadTimestamp) <= THREADING_TIME_WINDOW_MILLISECONDS
    }

    get #selectorForCurrentUser() {
        return `.mention[data-user-id="${this.#userId}"]`
    }
}
