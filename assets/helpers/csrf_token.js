// The token a fetch request sends to a state-changing route.
//
// The stateless protection accepts a double-submit token: a random value sent
// with the request and repeated in a cookie of the same name. The script that
// watches form submissions does this for the forms of a page; this is the same
// thing for the controllers that post with fetch instead of a form.
//
// The value is written in hexadecimal so the cookie name only holds characters
// a cookie name may carry.
export function csrfToken() {
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    const token = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
    const cookie = `csrf-token_${token}=csrf-token; path=/; samesite=strict`;

    document.cookie = window.location.protocol === 'https:' ? `__Host-${cookie}; secure` : cookie;

    return token;
}
