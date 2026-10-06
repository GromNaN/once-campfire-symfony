import { Controller } from "@hotwired/stimulus"
import { csrfToken } from "../helpers/csrf_token.js"

// Turns notifications on for the room the reader is looking at.
//
// A browser hands out a subscription once, and asking for permission is a
// decision the reader only gets to make once: a browser that is asked out of
// the blue refuses for good. So the bell is what asks, and it only asks when
// the reader clicks it. Until notifications are on, the bell is a bell that
// offers to turn them on; once they are on, the frame loads and the bell
// becomes the setting that says how much the reader wants to hear about this
// room. A reader who has refused is shown how to allow them again, in the
// menus of the browser and the system they are actually using.
export default class extends Controller {
  static values = { subscriptionsUrl: String }
  static targets = [ "notAllowedNotice", "bell", "details" ]
  static classes = [ "attention" ]

  async connect() {
    if (this.#isTurboPreview) return

    if (window.notificationsPreviouslyReady) {
      this.#onNextTick(() => this.dispatch("ready"))
      return
    }

    const alreadyOn = await this.#isEnabled()

    this.#pulseBell()

    if (alreadyOn) {
      this.#onNextTick(() => this.dispatch("ready"))
      window.notificationsPreviouslyReady = true
    } else {
      this.#showBellAlert()
    }
  }

  async attemptToSubscribe() {
    if (this.#isAllowed) {
      const registration = await this.#serviceWorkerRegistration || await this.#registerServiceWorker()

      switch (Notification.permission) {
        case "denied":
          this.#revealNotAllowedNotice()
          break
        case "granted":
          await this.#subscribe(registration)
          break
        default:
          await this.#requestPermissionAndSubscribe(registration)
      }
    } else {
      this.#revealNotAllowedNotice()
    }

    this.#endFirstRun()
  }

  // Sends the endpoint to the server, which is what makes the reader
  // reachable. A browser that could not be registered is unsubscribed rather
  // than left subscribed to nothing.
  async #syncPushSubscription(subscription) {
    const { endpoint, keys: { p256dh, auth } } = subscription.toJSON()

    const response = await fetch(this.subscriptionsUrlValue, {
      method: "POST",
      headers: { "Content-Type": "application/json", "Accept": "application/json" },
      body: JSON.stringify({ endpoint, p256dh_key: p256dh, auth_key: auth, _csrf_token: csrfToken() })
    })

    if (!response.ok) await subscription.unsubscribe()
  }

  async #subscribe(registration) {
    const subscription = await registration.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: this.#vapidPublicKey
    })

    await this.#syncPushSubscription(subscription)
    this.dispatch("ready")
  }

  async #requestPermissionAndSubscribe(registration) {
    const permission = Notification.permission === "granted" ? "granted" : await Notification.requestPermission()

    if (permission === "granted") await this.#subscribe(registration)
  }

  async #isEnabled() {
    if (!this.#isAllowed) return false

    const registration = await this.#serviceWorkerRegistration
    const subscription = await registration?.pushManager?.getSubscription()

    return Notification.permission === "granted" && Boolean(registration) && Boolean(subscription)
  }

  #revealNotAllowedNotice() {
    this.notAllowedNoticeTarget.showModal()
    this.#openSingleOption()
  }

  // When only one set of steps applies to this reader, it is opened for them
  // rather than left folded away.
  #openSingleOption() {
    const visible = this.detailsTargets.filter((item) => item.offsetParent !== null)

    if (visible.length === 1) {
      this.detailsTargets.forEach((item) => item.toggleAttribute("open", item === visible[0]))
    }
  }

  // The bell has two pictures, the plain one and the one with an alert badge,
  // and shows one at a time.
  #showBellAlert() {
    this.bellTarget.querySelectorAll("img").forEach((img) => img.toggleAttribute("hidden"))
  }

  // The first time a reader sees a room, the bell calls attention to itself.
  // It stops as soon as the reader has answered.
  #pulseBell() {
    if (!this.#hasSeenFirstRun) this.bellTarget.classList.add(this.attentionClass)
  }

  #endFirstRun() {
    this.bellTarget.classList.remove(this.attentionClass)
    this.#markFirstRunSeen()
  }

  get #isAllowed() {
    return "serviceWorker" in navigator && "Notification" in window
  }

  get #serviceWorkerRegistration() {
    return navigator.serviceWorker.getRegistration(window.location.origin)
  }

  #registerServiceWorker() {
    return navigator.serviceWorker.register("/service-worker.js")
  }

  get #vapidPublicKey() {
    const encoded = document.querySelector('meta[name="vapid-public-key"]')?.content || ""
    const padding = "=".repeat((4 - encoded.length % 4) % 4)
    const base64 = (encoded + padding).replace(/-/g, "+").replace(/_/g, "/")
    const raw = window.atob(base64)
    const key = new Uint8Array(raw.length)

    for (let i = 0; i < raw.length; ++i) key[i] = raw.charCodeAt(i)

    return key
  }

  get #hasSeenFirstRun() {
    return getCookie(this.#firstRunCookie)
  }

  #markFirstRunSeen() {
    setCookie(this.#firstRunCookie, "1")
  }

  get #firstRunCookie() {
    return this.#isPwa ? "notifications-pwa-first-run-seen" : "notifications-first-run-seen"
  }

  get #isPwa() {
    return window.matchMedia("(display-mode: standalone)").matches
  }

  get #isTurboPreview() {
    return document.documentElement.hasAttribute("data-turbo-preview")
  }

  #onNextTick(callback) {
    setTimeout(callback, 0)
  }
}

function getCookie(name) {
  const entry = document.cookie.split("; ").find((cookie) => cookie.startsWith(`${name}=`))

  return entry?.slice(name.length + 1)
}

function setCookie(name, value) {
  document.cookie = `${name}=${value}; path=/; max-age=${60 * 60 * 24 * 365}; SameSite=Lax`
}
