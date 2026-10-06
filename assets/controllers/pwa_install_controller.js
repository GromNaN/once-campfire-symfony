import { Controller } from "@hotwired/stimulus"

// Offers to install the application as a web app.
//
// A browser only fires the install event once it has decided the application
// can be installed, which is why the button is revealed by the event rather
// than by the reader. The event is held back so that the install prompt is
// shown when the reader asks for it, and not in the middle of what they were
// doing.
export default class extends Controller {
  static classes = [ "prompting" ]

  #prompt

  connect() {
    if (!this.#canInstall || this.#isInstalled) return

    window.addEventListener("beforeinstallprompt", this.#holdPrompt)
    window.addEventListener("appinstalled", this.#installed)
  }

  promptInstall() {
    this.#prompt?.prompt()
  }

  #holdPrompt = (event) => {
    event.preventDefault()
    this.#prompt = event
    this.element.classList.add(this.promptingClass)
  }

  #installed = () => {
    this.element.classList.remove(this.promptingClass)
  }

  get #canInstall() {
    return "serviceWorker" in navigator
  }

  get #isInstalled() {
    return window.matchMedia("(display-mode: standalone)").matches
  }
}
