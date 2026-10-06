import { Controller } from "@hotwired/stimulus"

// A form with nothing to fill in, sent as soon as the page is ready.
//
// The transfer page is reached from a link and has no question to ask, so the
// form it carries is submitted on its own rather than waiting for a click.
export default class extends Controller {
  connect() {
    this.element.requestSubmit()
  }
}
