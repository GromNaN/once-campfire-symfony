import { Controller } from "@hotwired/stimulus"
import { nextEventLoopTick } from "../helpers/timing_helpers.js"
import ClientMessage from "../models/client_message.js"
import MessageFormatter, { ThreadStyle } from "../models/message_formatter.js"
import MessagePaginator from "../models/message_paginator.js"
import ScrollManager from "../models/scroll_manager.js"

// The list of messages of a room.
//
// It is read a page at a time as the reader scrolls, it keeps the reader where
// they were when a message arrives, and it shows a message the reader just
// wrote before the server has answered. A message that arrives over the stream
// is appended without anybody knowing what it is, so the controller works out
// its classes from the element and asks its sound to play.
export default class extends Controller {
  static targets = [ "latest", "message", "body", "messages", "template" ]
  static classes = [ "firstOfDay", "formatted", "me", "mentioned", "threaded" ]
  static values = { pageUrl: String, userId: Number }

  #clientMessage
  #paginator
  #formatter
  #scrollManager

  initialize() {
    this.#formatter = new MessageFormatter(this.userIdValue, {
      firstOfDay: this.firstOfDayClass,
      formatted: this.formattedClass,
      me: this.meClass,
      mentioned: this.mentionedClass,
      threaded: this.threadedClass,
    })
  }

  connect() {
    this.#clientMessage = new ClientMessage(this.templateTarget)
    this.#paginator = new MessagePaginator(this.messagesTarget, this.pageUrlValue, this.#formatter, this.#allContentViewed.bind(this))
    this.#scrollManager = new ScrollManager(this.messagesTarget)

    if (this.#hasSearchResult) {
      this.#highlightSearchResult()
    } else {
      this.#scrollManager.autoscroll(true)
    }

    this.#paginator.monitor()
  }

  disconnect() {
    this.#paginator.disconnect()
  }

  messageTargetConnected(target) {
    this.#formatter.format(target, ThreadStyle.thread)
  }

  // Actions

  async beforeStreamRender(event) {
    const stream = event.detail.newStream
    const target = stream.getAttribute("target") ?? stream.getAttribute("targets")

    if (target !== this.messagesTarget.id) return

    const render = event.detail.render
    const upToDate = this.#paginator.upToDate

    if (upToDate) {
      event.detail.render = async (streamElement) => {
        const didScroll = await this.#scrollManager.autoscroll(false, async () => {
          await render(streamElement)
          await nextEventLoopTick()

          this.#positionLastMessage()
          this.#playSoundForLastMessage()
          this.#paginator.trimExcessMessages(true)
        })

        if (!didScroll) this.latestTarget.hidden = false
      }
    } else {
      this.latestTarget.hidden = false
    }
  }

  async returnToLatest() {
    this.latestTarget.hidden = true
    await this.#ensureUpToDate()
    this.#scrollManager.autoscroll(true)
  }

  // Pressing the up arrow on an empty composer edits what the reader wrote
  // last, which is how a message is corrected without reaching for the menu.
  async editMyLastMessage() {
    if (this.#composerIsEmpty && this.#paginator.upToDate) {
      this.#myLastMessage?.querySelector(".message__edit-btn")?.click()
    }
  }

  // Outlet actions, called by the composer.

  async insertPendingMessage(clientMessageId, node) {
    await this.#ensureUpToDate()

    return this.#scrollManager.autoscroll(true, async () => {
      const message = this.#clientMessage.render(clientMessageId, node)
      this.messagesTarget.insertAdjacentHTML("beforeend", message)
    })
  }

  updatePendingMessage(clientMessageId, body) {
    this.#clientMessage.update(clientMessageId, body)
  }

  failPendingMessage(clientMessageId) {
    this.#clientMessage.failed(clientMessageId)
  }

  #allContentViewed() {
    this.latestTarget.hidden = true
  }

  async #ensureUpToDate() {
    if (!this.#paginator.upToDate) {
      await this.#paginator.resetToLastPage()
    }
  }

  // A link that points at one message puts it in the middle of the page and
  // marks it, and the list is no longer known to be at the newest page.
  #highlightSearchResult() {
    const highlightId = location.pathname.split("@").pop()
    const highlightMessage = this.messagesTarget.querySelector(`.message[data-message-id="${highlightId}"]`)

    if (highlightMessage) {
      highlightMessage.classList.add("search-highlight")
      highlightMessage.scrollIntoView({ behavior: "instant", block: "center" })
    }

    this.#paginator.upToDate = false
  }

  get #hasSearchResult() {
    return location.pathname.includes("@")
  }

  get #composerIsEmpty() {
    const editor = document.querySelector("#composer [data-composer-target='text']")

    return !editor || editor.value.trim() === ""
  }

  get #lastMessage() {
    return this.messagesTarget.children[this.messagesTarget.children.length - 1]
  }

  get #myLastMessage() {
    const myMessages = this.messagesTarget.querySelectorAll(`.${this.meClass}`)

    return myMessages[myMessages.length - 1]
  }

  // A message is written in the order the server gave it, which can differ from
  // the order it arrived when a page was fetched in between. The new one is put
  // where it belongs rather than at the end.
  #positionLastMessage() {
    const followingMessage = this.#followingMessage(this.#lastMessage)

    if (followingMessage) followingMessage.before(this.#lastMessage)
  }

  #playSoundForLastMessage() {
    const soundTarget = this.#lastMessage.querySelector(".sound")

    if (soundTarget) this.dispatch("play", { target: soundTarget })
  }

  #followingMessage(message) {
    const messageSortValue = this.#sortValue(message)
    let followingMessage = null
    let previousMessage = message.previousElementSibling

    while (messageSortValue < this.#sortValue(previousMessage)) {
      followingMessage = previousMessage
      previousMessage = previousMessage.previousElementSibling
    }

    return followingMessage
  }

  #sortValue(node) {
    return (node && parseInt(node.dataset.sortValue)) || 0
  }
}
