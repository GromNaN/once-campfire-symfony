import { Controller } from "@hotwired/stimulus"
import { onNextEventLoopTick, nextFrame } from "../helpers/timing_helpers.js"

// The composer.
//
// A message is shown in the list as soon as the reader sends it, before the
// server has answered: the browser picks an identifier, the messages list is
// asked to show the message under it, and the form is submitted with the same
// identifier, so the saved message replaces the one shown. What the reader
// wrote is also kept as a draft, so that leaving the room does not lose it.
export default class extends Controller {
  static targets = [ "text", "clientid" ]
  static values = { roomId: Number }
  static outlets = [ "messages" ]

  connect() {
    this.#restoreDraft()

    if (!this.#usingTouchDevice) {
      onNextEventLoopTick(() => this.textTarget.focus())
    }
  }

  saveDraft() {
    if (this.#validInput()) {
      localStorage.setItem(this.#draftKey, this.textTarget.value)
    } else {
      localStorage.removeItem(this.#draftKey)
    }
  }

  // Sending is intercepted so the message can be shown before it is saved. The
  // form is submitted a frame later, once the browser has drawn it.
  submit(event) {
    event.preventDefault()

    this.#submitMessage()
    this.textTarget.focus()
  }

  submitEnd(event) {
    if (!event.detail.success) {
      this.messagesOutlet.failPendingMessage(this.clientidTarget.value)
    }
  }

  // Enter sends the message and Shift+Enter writes another line, which is what
  // the composer of a chat is expected to do.
  submitByKeyboard(event) {
    if (event.key !== "Enter" || event.shiftKey || event.isComposing) return

    event.stopPropagation()
    this.submit(event)
  }

  async #submitMessage() {
    if (!this.#validInput()) return

    const clientMessageId = this.#generateClientId()

    await this.messagesOutlet.insertPendingMessage(clientMessageId, this.textTarget.value)
    await nextFrame()

    this.clientidTarget.value = clientMessageId
    this.element.requestSubmit()
    this.#reset()
  }

  #validInput() {
    return this.textTarget.value.trim() !== ""
  }

  #generateClientId() {
    return crypto.randomUUID()
  }

  #reset() {
    this.textTarget.value = ""
    localStorage.removeItem(this.#draftKey)
  }

  #restoreDraft() {
    const draft = localStorage.getItem(this.#draftKey)

    if (draft) this.textTarget.value = draft
  }

  get #draftKey() {
    return `composer-draft-${this.roomIdValue}`
  }

  get #usingTouchDevice() {
    return "ontouchstart" in window || navigator.maxTouchPoints > 0
  }
}
