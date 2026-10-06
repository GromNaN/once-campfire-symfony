import { Controller } from '@hotwired/stimulus';

const DEBOUNCE_MS = 300;

/**
 * Picks people by name and keeps them as pills.
 *
 * The names that are typed are looked up on the server, and the ones that are
 * picked are written into the select of the form, which is what is sent when
 * the form is saved. The select itself is hidden: what the reader sees is the
 * pills, one per picked person.
 *
 * People who are already picked, and the reader themselves, are left out of the
 * suggestions, because a conversation with oneself is not a conversation.
 */
export default class extends Controller {
    static targets = ['select', 'input', 'results'];
    static values = { url: String, self: Number };

    #pills = [];
    #options = [];
    #selectedIndex = -1;
    #timeout = null;

    connect() {
        this.inputTarget.focus();
    }

    disconnect() {
        clearTimeout(this.#timeout);
    }

    search() {
        clearTimeout(this.#timeout);
        this.#timeout = setTimeout(() => this.#load(this.inputTarget.value), DEBOUNCE_MS);
    }

    didPressKey(event) {
        switch (event.key) {
            case 'Backspace':
                if (this.inputTarget.value === '') {
                    this.#removeLast();
                }
                break;
            case 'ArrowDown':
                this.#move(1);
                event.preventDefault();
                break;
            case 'ArrowUp':
                this.#move(-1);
                event.preventDefault();
                break;
            case 'Enter':
                if (this.#commit()) {
                    event.preventDefault();
                }
                break;
            case 'Escape':
                this.#hide();
                break;
        }
    }

    pick(event) {
        const option = event.target.closest('suggestion-option');

        if (option) {
            this.#add(option.getAttribute('value'), option.dataset.label, option.dataset.avatarUrl);
        }
    }

    remove(event) {
        this.#remove(event.currentTarget.dataset.value);
        this.inputTarget.focus();
    }

    async #load(query) {
        const url = new URL(this.urlValue, window.location.origin);

        if (query) {
            url.searchParams.set('query', query);
        }

        const response = await fetch(url, { headers: { Accept: 'application/json' } });
        const people = response.ok ? await response.json() : [];

        this.#render(people);
    }

    #render(people) {
        const taken = this.#values();
        const shown = people.filter((person) => person.value !== this.selfValue && !taken.includes(String(person.value)));

        this.#options = shown;
        this.#selectedIndex = shown.length > 0 ? 0 : -1;
        this.resultsTarget.innerHTML = shown.map((person, index) => this.#optionMarkup(person, index === 0)).join('');

        if (shown.length > 0) {
            this.resultsTarget.removeAttribute('hidden');
        } else {
            this.#hide();
        }
    }

    #optionMarkup(person, selected) {
        // The name is written in a text node of its own, and the attribute that
        // carries it is escaped, so a name cannot break out of the markup.
        const name = escapeHtml(person.name);

        return `
            <suggestion-option class="autocomplete__item flex align-center gap unpad" role="option"
                value="${person.value}" data-label="${name}" data-avatar-url="${escapeHtml(person.avatar_url)}"
                ${selected ? 'selected' : ''}>
                <button type="button" class="autocomplete__btn btn btn--borderless btn--transparent min-width flex-item-grow justify-start">
                    <span class="avatar">
                        <img src="${escapeHtml(person.avatar_url)}" alt="" role="presentation">
                    </span>
                    <span class="autocompletable__name">${name}</span>
                </button>
            </suggestion-option>
        `;
    }

    #move(delta) {
        if (this.#options.length === 0) {
            return;
        }

        this.#selectedIndex = Math.max(0, Math.min(this.#options.length - 1, this.#selectedIndex + delta));
        this.#highlight();
    }

    #highlight() {
        const options = this.resultsTarget.querySelectorAll('suggestion-option');

        options.forEach((option, index) => {
            option.toggleAttribute('selected', index === this.#selectedIndex);
        });

        this.resultsTarget.removeAttribute('hidden');
    }

    #commit() {
        const person = this.#options[this.#selectedIndex];

        if (!person) {
            return false;
        }

        this.#add(person.value, person.name, person.avatar_url);

        return true;
    }

    #add(value, label, avatarUrl) {
        if (this.#values().includes(String(value))) {
            return;
        }

        const option = document.createElement('option');
        option.value = value;
        option.textContent = label;
        option.selected = true;
        option.dataset.avatarUrl = avatarUrl;
        this.selectTarget.append(option);

        this.#pills.push(this.#renderPill(value, label, avatarUrl));

        this.inputTarget.value = '';
        this.#hide();
    }

    #remove(value) {
        const option = Array.from(this.selectTarget.options).find((candidate) => candidate.value === String(value));

        if (!option) {
            return;
        }

        const index = this.#values().indexOf(String(value));
        option.remove();
        this.#pills.splice(index, 1)[0]?.remove();
    }

    #removeLast() {
        const option = this.selectTarget.options[this.selectTarget.options.length - 1];

        if (option) {
            this.#remove(option.value);
        }
    }

    #renderPill(value, label, avatarUrl) {
        const template = this.element.querySelector('template#autocompletable-user');

        if (!template) {
            return null;
        }

        const pill = template.content.firstElementChild.cloneNode(true);

        pill.querySelectorAll('[data-value]').forEach((element) => {
            element.dataset.value = value;
        });
        pill.querySelector('[data-content="label"]').textContent = label;
        pill.querySelector('[data-content="label"]').title = value;
        pill.querySelector('[data-content="screenReaderLabel"]').textContent = label;
        pill.querySelector('[data-content="avatar"]').src = avatarUrl;

        template.before(pill);

        return pill;
    }

    #values() {
        return Array.from(this.selectTarget.options).map((option) => String(option.value));
    }

    #hide() {
        this.resultsTarget.setAttribute('hidden', '');
        this.#selectedIndex = -1;
    }
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
    })[character]);
}
