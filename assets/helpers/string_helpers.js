// Turning text into something safe to put in a page.
//
// A message shown straight away is built from what the reader typed, which has
// not been through the server yet, so it has to be escaped here.

const HTML_ESCAPES = { "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "'": "&#39;" }

export function escapeHTML(string) {
    return String(string ?? "").replace(/[&<>"']/g, character => HTML_ESCAPES[character])
}
