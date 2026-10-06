import { escapeHTML } from "../helpers/string_helpers.js"

// A message the reader just wrote, shown before the server has answered.
//
// The element is built from the template the page carries, so it looks like a
// saved message. It is given the identifier the browser chose, which the saved
// message is given as well: when the answer arrives, the stream appends the
// real one and Turbo drops the element whose identifier it repeats.

const EMOJI_MATCHER = /^(\p{Emoji_Presentation}|\p{Extended_Pictographic}|\uFE0F)+$/gu

const SOUND_NAMES = [ "56k", "ballmer", "bell", "bezos", "bueller", "butts", "clowntown", "cottoneyejoe", "crickets", "curb", "dadgummit", "dangerzone", "danielsan", "deeper", "donotwant", "drama", "flawless", "glados", "gogogo", "greatjob", "greyjoy", "guarantee", "heygirl", "honk", "horn", "horror", "inconceivable", "letitgo", "live", "loggins", "makeitso", "noooo", "nyan", "ohmy", "ohyeah", "pushit", "rimshot", "rollout", "rumble", "sax", "secret", "sexyback", "story", "tada", "tmyk", "totes", "trololo", "trombone", "unix", "vuvuzela", "what", "whoomp", "wups", "yay", "yeah", "yodel" ]

export default class ClientMessage {
    #template

    constructor(template) {
        this.#template = template
    }

    render(clientMessageId, node) {
        const now = new Date()
        const body = this.#contentFromNode(node)

        return this.#createFromTemplate({
            clientMessageId,
            body,
            messageTimestamp: Math.floor(now.getTime()),
            messageDatetime: now.toISOString(),
            messageClasses: this.#messageClassesFromNode(node),
        })
    }

    update(clientMessageId, body) {
        const element = this.#findWithId(clientMessageId)?.querySelector(".message__body-content")

        if (element) {
            element.innerHTML = body
        }
    }

    failed(clientMessageId) {
        this.#findWithId(clientMessageId)?.classList.add("message--failed")
    }

    #findWithId(clientMessageId) {
        return document.querySelector(`#message_${clientMessageId}`)
    }

    // What the reader wrote. A play command is answered straight away so the
    // message reads as being played; anything else is the text itself, wrapped
    // like the rich text the editor produces.
    #contentFromNode(node) {
        if (this.#isPlayCommand(node)) {
            return `<span class="pending">Playing ${this.#matchPlayCommand(node)}&#8230;</span>`
        } else if (this.#isRichText(node)) {
            return this.#richTextContent(node)
        } else {
            return this.#plainTextContent(node)
        }
    }

    #messageClassesFromNode(node) {
        return this.#containsOnlyEmoji(this.#plainTextFromNode(node)) ? "message--emoji" : ""
    }

    #isPlayCommand(node) {
        return Boolean(this.#matchPlayCommand(node))
    }

    #matchPlayCommand(node) {
        return this.#plainTextFromNode(node)?.match(new RegExp(`^/play (${SOUND_NAMES.join("|")})`))?.[1]
    }

    #plainTextFromNode(node) {
        return this.#isRichText(node) ? node.toString().trim() : node
    }

    #isRichText(node) {
        return typeof node !== "string"
    }

    #richTextContent(node) {
        return `<div class="lexxy-content">${node.value}</div>`
    }

    // The text comes straight from the composer and has not been through the
    // server, so it is escaped here rather than trusted.
    #plainTextContent(node) {
        return `<div class="lexxy-content">${escapeHTML(node).replace(/\n/g, "<br>")}</div>`
    }

    #createFromTemplate(data) {
        let html = this.#template.innerHTML

        for (const key in data) {
            html = html.replaceAll(`$${key}$`, data[key])
        }

        return html
    }

    #containsOnlyEmoji(text) {
        return text?.match(EMOJI_MATCHER)
    }
}
