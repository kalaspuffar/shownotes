/* ============================================================
   Cozy News Corner — app.js
   Vanilla JS module: state management, API layer, rendering,
   episode meta auto-save, add item panel, inline edit, delete.
   ============================================================ */

'use strict';

/* ----------------------------------------------------------
   Module-scope state — cloned from PHP-injected INITIAL_STATE
   ---------------------------------------------------------- */
const state = {
    episode: null,
    items:   { vulnerability: [], news: [] },
    config:  null,
};

/* ----------------------------------------------------------
   10.2 — apiCall(action, payload)
   Single AJAX boundary for all server communication.
   ---------------------------------------------------------- */
async function apiCall(action, payload = {}) {
    const body = JSON.stringify({ action, ...payload });

    let response;
    try {
        response = await fetch('api.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body,
        });
    } catch (networkError) {
        showToast('error', 'Network error — could not reach the server.');
        throw networkError;
    }

    let json;
    try {
        json = await response.json();
    } catch {
        showToast('error', 'Server returned an unexpected response.');
        throw new Error('Non-JSON response from server');
    }

    if (!json.success) {
        const message = json.error || 'An unknown error occurred.';
        showToast('error', message);
        throw new Error(message);
    }

    return json.data;
}

/* ----------------------------------------------------------
   10.3 — createDebounce(fn, delay)
   Returns a debounced wrapper that delays execution.
   ---------------------------------------------------------- */
function createDebounce(fn, delay) {
    let timer;
    return function (...args) {
        clearTimeout(timer);
        timer = setTimeout(() => fn.apply(this, args), delay);
    };
}

/* ----------------------------------------------------------
   10.4 — showToast(type, message)
   Appends a self-removing toast to #toast-container.
   ---------------------------------------------------------- */
function showToast(type, message) {
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.textContent = message;
    container.appendChild(toast);

    setTimeout(() => toast.remove(), 4000);
}

/* ----------------------------------------------------------
   10.5 — renderEpisodeMeta()
   Syncs episode meta inputs from state.
   ---------------------------------------------------------- */
function renderEpisodeMeta() {
    document.getElementById('ep-week').value   = state.episode.week_number ?? '';
    document.getElementById('ep-year').value   = state.episode.year        ?? '';
    document.getElementById('ep-youtube').value = state.episode.youtube_url ?? '';
}

/* ----------------------------------------------------------
   10.6 — Episode meta auto-save (800 ms debounce)
   ---------------------------------------------------------- */
function bindEpisodeMetaListeners() {
    const saveEpisode = createDebounce(async () => {
        const weekNumber = parseInt(document.getElementById('ep-week').value, 10);
        const year       = parseInt(document.getElementById('ep-year').value, 10);
        const youtubeUrl = document.getElementById('ep-youtube').value.trim();

        try {
            const data = await apiCall('update_episode', {
                week_number: weekNumber,
                year,
                youtube_url: youtubeUrl,
            });
            state.episode = data;
            setStatusIndicator('Saved');
        } catch {
            setStatusIndicator('Save failed', true);
        }
    }, 800);

    ['ep-week', 'ep-year', 'ep-youtube'].forEach(id => {
        document.getElementById(id).addEventListener('input', () => {
            setStatusIndicator('Saving…');
            saveEpisode();
        });
    });
}

/* Status indicator helper */
function setStatusIndicator(message, isError = false) {
    const el = document.getElementById('status-indicator');
    if (!el) return;
    el.textContent = message;
    el.style.color = isError ? 'var(--color-danger)' : 'var(--color-text-muted)';
}

/* ----------------------------------------------------------
   10.7 — renderItem(item, section)
   Returns a DOM element for one item row.
   Vulnerability: title + url fields.
   News: title + url + author_name + author_url fields.
   ---------------------------------------------------------- */
function renderItem(item, section) {
    const row = document.createElement('div');
    row.className = 'item-row';
    row.dataset.id      = item.id;
    row.dataset.section = section;

    const fieldsEl = document.createElement('div');
    fieldsEl.className = 'item-fields';

    // Determine which fields to show
    const fieldDefs = section === 'vulnerability'
        ? [
            { key: 'title', label: 'Title' },
            { key: 'url',   label: 'URL' },
          ]
        : [
            { key: 'title',      label: 'Title' },
            { key: 'url',        label: 'URL' },
            { key: 'author_name', label: 'Author' },
            { key: 'author_url', label: 'Author URL' },
          ];

    for (const { key, label } of fieldDefs) {
        const fieldEl = buildItemField(item, key, label, section);
        fieldsEl.appendChild(fieldEl);
    }

    // Delete button
    const deleteBtn = document.createElement('button');
    deleteBtn.type = 'button';
    deleteBtn.className = 'item-delete';
    deleteBtn.textContent = '✕';
    deleteBtn.setAttribute('aria-label', `Delete item: ${item.title || item.url}`);
    deleteBtn.addEventListener('click', () => handleDeleteItem(item.id, section));

    row.appendChild(fieldsEl);
    row.appendChild(deleteBtn);

    return row;
}

/* Builds a single labelled field within an item row. */
function buildItemField(item, key, label, section) {
    const fieldEl = document.createElement('div');
    fieldEl.className = 'item-field';
    fieldEl.dataset.field = key;

    const labelEl = document.createElement('span');
    labelEl.className = 'item-field-label';
    labelEl.textContent = label + ':';

    const valueEl = document.createElement('span');
    valueEl.className = 'item-field-value';
    valueEl.textContent = item[key] || '';

    // Click-to-edit (task 10.9)
    valueEl.addEventListener('click', () => {
        startInlineEdit(valueEl, item, key, section);
    });

    fieldEl.appendChild(labelEl);
    fieldEl.appendChild(valueEl);

    return fieldEl;
}

/* ----------------------------------------------------------
   10.9 — Inline click-to-edit
   Replaces a value span with a focused input; saves on
   blur/Enter (debounced), restores on Escape.
   ---------------------------------------------------------- */
function startInlineEdit(valueEl, item, key, section) {
    if (valueEl.querySelector('input')) return; // already editing

    const originalValue = item[key] || '';

    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'inline-edit';
    input.value = originalValue;

    // Replace the text content with the input
    valueEl.textContent = '';
    valueEl.appendChild(input);
    input.focus();
    input.select();

    const saveEdit = createDebounce(async () => {
        const newValue = input.value.trim();
        if (newValue === originalValue) {
            restoreValue();
            return;
        }

        // Build the full fields payload (all fields must be sent)
        const row = valueEl.closest('.item-row');
        const fields = collectItemFields(row, item, key, newValue);

        try {
            const data = await apiCall('update_item', { id: item.id, ...fields });
            // Patch the item in state
            patchItemInState(section, data);
            // Re-render section (simplest approach to keep state + DOM in sync)
            if (section === 'vulnerability') renderVulnerabilityList();
            else renderNewsList();
        } catch {
            // Toast already shown by apiCall; restore original
            restoreValue();
        }
    }, 800);

    function restoreValue() {
        valueEl.textContent = originalValue;
    }

    input.addEventListener('blur', saveEdit);

    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            saveEdit();
        } else if (e.key === 'Escape') {
            // Cancel: restore without saving
            restoreValue();
        }
    });
}

/* Collects all editable field values from an item row for the update_item call. */
function collectItemFields(row, item, changedKey, changedValue) {
    const fields = {
        url:         item.url         || '',
        title:       item.title       || '',
        author_name: item.author_name || '',
        author_url:  item.author_url  || '',
    };
    fields[changedKey] = changedValue;
    return fields;
}

/* Patches a single item object inside state.items[section]. */
function patchItemInState(section, updatedItem) {
    const list = state.items[section] || [];
    const idx  = list.findIndex(i => i.id === updatedItem.id);
    if (idx !== -1) list[idx] = updatedItem;
}

/* ----------------------------------------------------------
   10.8 — renderVulnerabilityList() / renderNewsList()
   Clear and re-render the respective section containers.
   ---------------------------------------------------------- */
function renderVulnerabilityList() {
    renderSectionList('vulnerability-list', state.items.vulnerability, 'vulnerability');
}

function renderNewsList() {
    renderSectionList('news-list', state.items.news, 'news');
}

function renderSectionList(containerId, items, section) {
    const container = document.getElementById(containerId);
    if (!container) return;

    // Remove existing item rows (preserve the heading element)
    container.querySelectorAll('.item-row').forEach(el => el.remove());

    for (const item of (items || [])) {
        container.appendChild(renderItem(item, section));
    }
}

/* ----------------------------------------------------------
   10.10 — Delete item with confirmation
   ---------------------------------------------------------- */
async function handleDeleteItem(id, section) {
    const confirmed = window.confirm('Delete this item? This cannot be undone.');
    if (!confirmed) return;

    try {
        await apiCall('delete_item', { id });

        // Remove from state
        state.items[section] = state.items[section].filter(i => i.id !== id);

        // Re-render affected section
        if (section === 'vulnerability') renderVulnerabilityList();
        else renderNewsList();
    } catch {
        // Error toast already displayed by apiCall
    }
}

/* ----------------------------------------------------------
   10.11 — Fetch button: scrape URL and populate fields
   ---------------------------------------------------------- */
function bindFetchButton() {
    const btnFetch      = document.getElementById('btn-fetch');
    const addUrlInput   = document.getElementById('add-url');
    const titleInput    = document.getElementById('add-title');
    const authorName    = document.getElementById('add-author-name');
    const authorUrl     = document.getElementById('add-author-url');

    btnFetch.addEventListener('click', async () => {
        const url = addUrlInput.value.trim();
        if (!url) return;

        // Loading state
        btnFetch.disabled = true;
        btnFetch.classList.add('loading');
        btnFetch.textContent = 'Fetching…';

        try {
            const data = await apiCall('scrape_url', { url });

            titleInput.value = data.title      || '';
            authorName.value = data.author_name || '';
            authorUrl.value  = data.author_url  || '';

            if (data.scrape_error) {
                showToast('warning', `Partial metadata: ${data.scrape_error}`);
            }
        } catch {
            // Error toast shown by apiCall; leave fields empty
        } finally {
            btnFetch.disabled = false;
            btnFetch.classList.remove('loading');
            btnFetch.textContent = 'Fetch Metadata';
            updateAddButtonState();
        }
    });
}

/* ----------------------------------------------------------
   10.12 — Disable Fetch & Add buttons when URL is empty
   ---------------------------------------------------------- */
function updateAddButtonState() {
    const url      = document.getElementById('add-url').value.trim();
    const btnFetch = document.getElementById('btn-fetch');
    const btnAdd   = document.getElementById('btn-add');

    btnFetch.disabled = !url;
    btnAdd.disabled   = !url;
}

function bindAddUrlInput() {
    document.getElementById('add-url').addEventListener('input', updateAddButtonState);
}

/* ----------------------------------------------------------
   10.13 — Add Item button: submit and reset panel
   ---------------------------------------------------------- */
function bindAddButton() {
    const btnAdd    = document.getElementById('btn-add');

    btnAdd.addEventListener('click', async () => {
        const section    = document.getElementById('add-section').value;
        const url        = document.getElementById('add-url').value.trim();
        const title      = document.getElementById('add-title').value.trim();
        const authorName = document.getElementById('add-author-name').value.trim();
        const authorUrl  = document.getElementById('add-author-url').value.trim();

        if (!url) return;

        try {
            const data = await apiCall('add_item', {
                section,
                url,
                title,
                author_name: authorName,
                author_url:  authorUrl,
            });

            // Append to state and re-render
            state.items[section].push(data);
            if (section === 'vulnerability') renderVulnerabilityList();
            else renderNewsList();

            // Reset the add panel
            resetAddPanel();
        } catch {
            // Error toast shown by apiCall
        }
    });
}

function resetAddPanel() {
    document.getElementById('add-url').value         = '';
    document.getElementById('add-title').value       = '';
    document.getElementById('add-author-name').value = '';
    document.getElementById('add-author-url').value  = '';
    updateAddButtonState();
}

/* ----------------------------------------------------------
   Initialise on DOMContentLoaded
   ---------------------------------------------------------- */
document.addEventListener('DOMContentLoaded', () => {
    // Clone INITIAL_STATE into module-scope state (task 10.1)
    state.episode = structuredClone(INITIAL_STATE.episode);
    state.items   = structuredClone(INITIAL_STATE.items);
    state.config  = structuredClone(INITIAL_STATE.config);

    // Initial render
    renderEpisodeMeta();
    renderVulnerabilityList();
    renderNewsList();

    // Bind interactive behaviours
    bindEpisodeMetaListeners();
    bindFetchButton();
    bindAddUrlInput();
    bindAddButton();

    // Ensure buttons start in correct disabled state
    updateAddButtonState();
});
