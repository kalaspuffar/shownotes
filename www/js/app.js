/* ============================================================
   Cozy News Corner — app.js
   Vanilla JS module: state management, API layer, rendering,
   episode meta auto-save, add item panel, inline edit, delete,
   drag-and-drop reordering, author suggestions, generate/copy/reset.
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

// Tracks which section a drag originated from so dragover can reject
// cross-section drops before the drop event fires.
let dragSourceSection = null;

/* ----------------------------------------------------------
   10.2 — apiCall(action, payload)
   Single AJAX boundary for all server communication.
   ---------------------------------------------------------- */
async function apiCall(action, payload = {}, options = {}) {
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

    // raw: true bypasses the success check — caller handles the full response object.
    if (options.raw) return json;

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
   showConfirmDialog({ title, body, cancelLabel, confirmLabel })
   Renders a spec-compliant .modal-backdrop > .modal-dialog and
   returns a Promise that resolves to true (confirm) or false (cancel).
   ---------------------------------------------------------- */
function showConfirmDialog({ title, body, cancelLabel, confirmLabel }) {
    return new Promise((resolve) => {
        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';
        backdrop.setAttribute('role', 'dialog');
        backdrop.setAttribute('aria-modal', 'true');
        backdrop.setAttribute('aria-labelledby', 'modal-heading');

        const dialog = document.createElement('div');
        dialog.className = 'modal-dialog';

        const heading = document.createElement('h2');
        heading.id = 'modal-heading';
        heading.textContent = title;

        const bodyEl = document.createElement('p');
        bodyEl.textContent = body;

        const actions = document.createElement('div');
        actions.className = 'modal-dialog__actions';

        const cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'modal-dialog__cancel';
        cancelBtn.textContent = cancelLabel;

        const confirmBtn = document.createElement('button');
        confirmBtn.type = 'button';
        confirmBtn.className = 'modal-dialog__confirm';
        confirmBtn.textContent = confirmLabel;

        function close(result) {
            document.removeEventListener('keydown', onKeydown);
            backdrop.remove();
            resolve(result);
        }

        function onKeydown(e) {
            if (e.key === 'Escape') close(false);
        }

        cancelBtn.addEventListener('click', () => close(false));
        confirmBtn.addEventListener('click', () => close(true));

        // Dismiss on backdrop click (outside dialog)
        backdrop.addEventListener('click', (e) => {
            if (e.target === backdrop) close(false);
        });

        document.addEventListener('keydown', onKeydown);

        actions.appendChild(cancelBtn);
        actions.appendChild(confirmBtn);
        dialog.appendChild(heading);
        dialog.appendChild(bodyEl);
        dialog.appendChild(actions);
        backdrop.appendChild(dialog);
        document.body.appendChild(backdrop);

        // Move focus into the dialog for keyboard accessibility
        cancelBtn.focus();
    });
}

/* ----------------------------------------------------------
   10.5 — renderEpisodeMeta()
   Syncs episode meta inputs from state.
   ---------------------------------------------------------- */
function renderEpisodeMeta() {
    document.getElementById('ep-week').value    = state.episode.week_number ?? '';
    document.getElementById('ep-year').value    = state.episode.year        ?? '';
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
            state.episode = data.episode;
            ['ep-week', 'ep-year', 'ep-youtube'].forEach(id =>
                document.getElementById(id).classList.remove('unsaved')
            );
            setStatusIndicator('Saved');
            setTimeout(() => setStatusIndicator(''), 2500);
        } catch {
            setStatusIndicator('Save failed', true);
        }
    }, 800);

    ['ep-week', 'ep-year', 'ep-youtube'].forEach(id => {
        document.getElementById(id).addEventListener('input', () => {
            document.getElementById(id).classList.add('unsaved');
            setStatusIndicator('Saving…');
            saveEpisode();
        });
    });
}

/* ----------------------------------------------------------
   updateStartRecordingButton()
   Enables the "Start Recording" button when the episode has at
   least one item; disables it when both sections are empty.
   ---------------------------------------------------------- */
function updateStartRecordingButton() {
    const btn = document.getElementById('btn-start-recording');
    if (!btn) return;
    const hasItems = (state.items.vulnerability.length + state.items.news.length) > 0;
    btn.disabled = !hasItems;
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
    // draggable is NOT set on the row — only the handle initiates a drag,
    // so that clicking into text fields never accidentally starts a drag gesture.

    // Drag handle — visually indicates draggability; hidden from assistive tech
    const handle = document.createElement('span');
    handle.className = 'drag-handle';
    handle.textContent = '⠿';
    handle.setAttribute('aria-hidden', 'true');
    handle.draggable = true;
    row.appendChild(handle);

    // Drag events are bound to the handle, not the row.
    // setDragImage forces the browser to use the full item row as the ghost
    // image even though the drag source is just the small handle element.
    handle.addEventListener('dragstart', (e) => {
        dragSourceSection = section; // captured at module scope for dragover guards
        e.dataTransfer.setData('text/plain', String(item.id));
        e.dataTransfer.effectAllowed = 'move';
        const rect = row.getBoundingClientRect();
        e.dataTransfer.setDragImage(row, e.clientX - rect.left, e.clientY - rect.top);
        // Defer the class addition by one animation frame so Chrome captures the
        // ghost before the opacity reduction from .dragging is applied. Without this,
        // Chrome snapshots the element after the handler finishes (already with
        // opacity: 0.4), producing a semi-transparent ghost image.
        requestAnimationFrame(() => row.classList.add('dragging'));
    });
    handle.addEventListener('dragend', () => {
        row.classList.remove('dragging');
        dragSourceSection = null;
    });

    const fieldsEl = document.createElement('div');
    fieldsEl.className = 'item-fields';

    // Determine which fields to show
    const fieldDefs = section === 'vulnerability'
        ? [
            { key: 'title', label: 'Title' },
            { key: 'url',   label: 'URL' },
          ]
        : [
            { key: 'title',       label: 'Title' },
            { key: 'url',         label: 'URL' },
            { key: 'author_name', label: 'Author' },
            { key: 'author_url',  label: 'Author URL' },
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

    // Click-to-edit
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
   For News author_name fields, also attaches a suggestion dropdown.
   ---------------------------------------------------------- */
function startInlineEdit(valueEl, item, key, section) {
    if (valueEl.querySelector('input')) return; // already editing

    const originalValue     = item[key]       || '';
    // Capture original author_url so we detect suggestion-only URL changes (Issue 1 fix)
    const originalAuthorUrl = item.author_url || '';

    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'inline-edit';
    input.value = originalValue;

    // Replace the text content with the input
    valueEl.textContent = '';
    valueEl.appendChild(input);
    input.focus();
    input.select();

    // When Escape is pressed, restoreValue() removes the input from the DOM,
    // which fires a blur event. This flag prevents the debounced save from
    // running after an explicit cancellation.
    let cancelled = false;

    // Inner save function — called directly for an immediate save (e.g. after
    // selecting an author suggestion) or via the debounced wrapper for blur/Enter.
    async function doSave() {
        if (cancelled) return;

        const newValue = input.value.trim();
        // No-op if neither the display value nor the author_url changed (Issue 1 fix:
        // compare against the captured original rather than a phantom _pendingAuthorUrl)
        if (newValue === originalValue && item.author_url === originalAuthorUrl) {
            restoreValue();
            return;
        }

        // Build the full fields payload (all fields must be sent)
        const fields = collectItemFields(item, key, newValue);

        try {
            const data = await apiCall('update_item', { id: item.id, ...fields });
            // Patch the item in state and re-render
            patchItemInState(section, data.item);
            if (section === 'vulnerability') renderVulnerabilityList();
            else renderNewsList();
        } catch {
            // Toast already shown by apiCall; restore original
            restoreValue();
        }
    }

    // Debounced wrapper used by blur and keyboard Enter
    const saveEdit = createDebounce(doSave, 800);

    function restoreValue() {
        valueEl.textContent = originalValue;
    }

    input.addEventListener('blur', saveEdit);

    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            saveEdit();
        } else if (e.key === 'Escape') {
            // Cancel: set flag first so the blur-triggered save no-ops
            cancelled = true;
            restoreValue();
        }
    });

    // Attach author suggestion dropdown for News author_name field.
    // Pass doSave (not the debounced wrapper) so selecting a suggestion
    // triggers an immediate save without the 800 ms delay (Issue 2 fix).
    if (key === 'author_name' && section === 'news') {
        attachAuthorSuggestions(input, item, valueEl, doSave);
    }
}

/* Collects all editable field values from an item for the update_item call. */
function collectItemFields(item, key, changedValue) {
    const fields = {
        url:         item.url         || '',
        title:       item.title       || '',
        author_name: item.author_name || '',
        author_url:  item.author_url  || '',
    };
    fields[key] = changedValue;
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
    const container = document.getElementById('news-list');
    if (!container) return;

    // Remove all existing item rows and story-group containers; preserve the <h2> heading
    container.querySelectorAll('.item-row, .story-group').forEach(el => el.remove());

    container.appendChild(storyGroupModule.renderNewsSection(state.items.news));
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

        // Remove deleted item from state
        state.items[section] = state.items[section].filter(i => i.id !== id);

        // For news: promote any secondaries whose primary was just deleted to standalone
        if (section === 'news') {
            const survivingIds = new Set(state.items.news.map(i => i.id));
            state.items.news = state.items.news.map(item => {
                if (item.parent_id !== null && !survivingIds.has(item.parent_id)) {
                    return { ...item, parent_id: null };
                }
                return item;
            });
        }

        // Re-render affected section
        if (section === 'vulnerability') renderVulnerabilityList();
        else renderNewsList();

        updateStartRecordingButton();
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

            titleInput.value = data.title       || '';
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
    const btnAdd = document.getElementById('btn-add');

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
            state.items[section].push(data.item);
            if (section === 'vulnerability') renderVulnerabilityList();
            else renderNewsList();

            updateStartRecordingButton();

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
   Domain extraction helper
   Parses the hostname from a URL and strips the www. prefix.
   Returns empty string on invalid or missing URL.
   ---------------------------------------------------------- */
function extractDomain(url) {
    if (!url) return '';
    try {
        const host = new URL(url).hostname;
        return host.replace(/^www\./, '');
    } catch {
        return '';
    }
}

/* ----------------------------------------------------------
   Author suggestion dropdown — rendering helpers
   ---------------------------------------------------------- */

/* Builds a single clickable suggestion list item. */
function buildSuggestionItem(author, onSelect) {
    const li = document.createElement('li');
    li.setAttribute('role', 'option');
    li.className = 'suggest-item';
    li.textContent = author.author_name;

    // mousedown (not click) fires before blur so the dropdown is not
    // prematurely closed before the selection is registered.
    li.addEventListener('mousedown', (e) => {
        e.preventDefault(); // keep focus on the input
        onSelect(author);
    });

    return li;
}

/*
 * Renders a grouped suggestion dropdown below inputEl.
 * Appends the <ul> to inputEl's parent element.
 * Returns the <ul> element, or null if both suggestion arrays are empty.
 */
function renderSuggestionDropdown(inputEl, domainAuthors, otherAuthors, onSelect) {
    // Remove any existing dropdown attached to this input's parent
    const parent = inputEl.parentElement;
    parent.querySelectorAll('.author-suggest-list').forEach(el => el.remove());

    if (domainAuthors.length === 0 && otherAuthors.length === 0) return null;

    // Ensure the parent is positioned so the dropdown can be absolutely placed
    if (getComputedStyle(parent).position === 'static') {
        parent.style.position = 'relative';
    }

    const ul = document.createElement('ul');
    ul.setAttribute('role', 'listbox');
    ul.className = 'author-suggest-list';

    if (domainAuthors.length > 0) {
        const groupLabel = document.createElement('li');
        groupLabel.className = 'suggest-group-label';
        groupLabel.textContent = 'From this site';
        groupLabel.setAttribute('aria-hidden', 'true');
        ul.appendChild(groupLabel);

        for (const author of domainAuthors) {
            ul.appendChild(buildSuggestionItem(author, onSelect));
        }
    }

    if (domainAuthors.length > 0 && otherAuthors.length > 0) {
        const divider = document.createElement('li');
        divider.className = 'suggest-divider';
        divider.setAttribute('aria-hidden', 'true');
        ul.appendChild(divider);
    }

    if (otherAuthors.length > 0) {
        const groupLabel = document.createElement('li');
        groupLabel.className = 'suggest-group-label';
        groupLabel.textContent = 'Other authors';
        groupLabel.setAttribute('aria-hidden', 'true');
        ul.appendChild(groupLabel);

        for (const author of otherAuthors) {
            ul.appendChild(buildSuggestionItem(author, onSelect));
        }
    }

    parent.appendChild(ul);
    return ul;
}

/* Removes a suggestion dropdown from the DOM if it is still present. */
function closeSuggestionDropdown(dropdownEl) {
    if (dropdownEl && dropdownEl.parentElement) {
        dropdownEl.remove();
    }
}

/*
 * Attaches author suggestion dropdown behaviour to an inline-edit input.
 * Used for the author_name field of News item rows.
 * When a suggestion is selected, also patches author_url on the item and in the DOM,
 * then calls immediateSave() so the update is persisted without waiting for blur.
 */
function attachAuthorSuggestions(inputEl, item, valueEl, immediateSave) {
    const domain = extractDomain(item.url);
    let dropdown = null;
    // Sequence counter ensures only the latest in-flight suggestion fetch is applied (Issue 5 fix)
    let requestSeq = 0;

    function onSelect(author) {
        inputEl.value = author.author_name;

        // Also update the author_url field of this item row
        const row = valueEl.closest('.item-row');
        const authorUrlValueEl = row?.querySelector('.item-field[data-field="author_url"] .item-field-value');
        if (authorUrlValueEl) {
            authorUrlValueEl.textContent = author.author_url;
        }
        // Patch item so collectItemFields picks up the new author_url on save
        item.author_url = author.author_url;

        closeSuggestionDropdown(dropdown);
        dropdown = null;

        // Trigger an immediate save so the changed URL is not lost if the user
        // selects a suggestion whose display name matches the current value (Issue 2 fix)
        immediateSave();
    }

    async function fetchAndRenderSuggestions(query) {
        const seq = ++requestSeq;
        try {
            const data = await apiCall('get_author_suggestions', { domain, query });
            // Discard stale responses from earlier requests (Issue 5 fix)
            if (seq !== requestSeq) return;
            closeSuggestionDropdown(dropdown);
            dropdown = renderSuggestionDropdown(
                inputEl,
                data.domain_authors || [],
                data.other_authors  || [],
                onSelect
            );
        } catch {
            // Suggestions are best-effort; silently swallow errors
        }
    }

    const debouncedFetch = createDebounce((query) => fetchAndRenderSuggestions(query), 300);

    inputEl.addEventListener('focus', () => fetchAndRenderSuggestions(''));
    inputEl.addEventListener('input', () => debouncedFetch(inputEl.value));
    inputEl.addEventListener('blur', () => {
        // Delay to allow mousedown on a suggestion to fire first
        setTimeout(() => {
            closeSuggestionDropdown(dropdown);
            dropdown = null;
        }, 150);
    });
}

/*
 * Binds author suggestion dropdown to the Add Item panel's author_name input.
 */
function bindAuthorSuggestionsForAddPanel() {
    const authorNameInput = document.getElementById('add-author-name');
    const authorUrlInput  = document.getElementById('add-author-url');
    const addUrlInput     = document.getElementById('add-url');

    let dropdown = null;
    // Sequence counter to discard stale responses when the user types quickly (Issue 5 fix)
    let requestSeq = 0;

    function currentDomain() {
        return extractDomain(addUrlInput.value);
    }

    function onSelect(author) {
        authorNameInput.value = author.author_name;
        authorUrlInput.value  = author.author_url;
        closeSuggestionDropdown(dropdown);
        dropdown = null;
    }

    async function fetchAndRenderSuggestions(query) {
        const seq = ++requestSeq;
        try {
            const data = await apiCall('get_author_suggestions', {
                domain: currentDomain(),
                query,
            });
            // Discard stale responses (Issue 5 fix)
            if (seq !== requestSeq) return;
            closeSuggestionDropdown(dropdown);
            dropdown = renderSuggestionDropdown(
                authorNameInput,
                data.domain_authors || [],
                data.other_authors  || [],
                onSelect
            );
        } catch {
            // Suggestions are best-effort; silently swallow errors
        }
    }

    const debouncedFetch = createDebounce((query) => fetchAndRenderSuggestions(query), 300);

    authorNameInput.addEventListener('focus', () => fetchAndRenderSuggestions(''));
    authorNameInput.addEventListener('input', () => debouncedFetch(authorNameInput.value));
    authorNameInput.addEventListener('blur', () => {
        setTimeout(() => {
            closeSuggestionDropdown(dropdown);
            dropdown = null;
        }, 150);
    });
}

/* ----------------------------------------------------------
   handleDropOnItem(draggedId, targetId)
   Calls the nest_item API. Handles the 409 requiresConfirmation
   response with a confirmation dialog before re-issuing the call
   with transferTalkingPoints: true.
   ---------------------------------------------------------- */
async function handleDropOnItem(draggedId, targetId) {
    const json = await apiCall(
        'nest_item',
        { itemId: draggedId, targetId, transferTalkingPoints: false },
        { raw: true }
    );

    if (!json.success && json.requiresConfirmation) {
        // 409: primary has talking points — ask the user before transferring
        const confirmed = await showConfirmDialog({
            title:        'Transfer Recording Notes?',
            body:         json.warning || 'This item has recording notes. Transfer them to the new primary?',
            cancelLabel:  'Cancel',
            confirmLabel: 'Transfer & Continue',
        });
        if (!confirmed) return;

        try {
            const data = await apiCall('nest_item', {
                itemId: draggedId,
                targetId,
                transferTalkingPoints: true,
            });
            state.items.news = data.items.news;
            renderNewsList();
        } catch {
            // Error toast shown by apiCall
        }
        return;
    }

    if (!json.success) {
        showToast('error', json.error || 'Nesting failed.');
        return;
    }

    state.items.news = json.data.items.news;
    renderNewsList();
}

/* ----------------------------------------------------------
   Drag-and-drop — section container bindings
   Binds dragover/dragleave/drop on the given list container.
   Only accepts drops from items belonging to the same section.

   For the news section, also supports drop-on-item nesting:
   - Center 50% of an item height → .drop-target-nest ring → nest_item
   - Top / bottom 25% → horizontal bar indicator → reorder_items
   Story-group containers are treated as single draggable units;
   their full expanded ID list (primary + secondaries) is sent to
   reorder_items so the server-side count validation passes.
   ---------------------------------------------------------- */
function bindDragAndDrop(containerId, section) {
    const container = document.getElementById(containerId);
    if (!container) return;

    let dropIndicator = null;
    let dropBeforeRow = null; // top-level element to insert before (reorder mode)
    let nestTargetEl  = null; // standalone .item-row highlighted for nesting (news only)

    function removeIndicator() {
        if (dropIndicator) {
            dropIndicator.remove();
            dropIndicator = null;
        }
    }

    function removeNestHighlight() {
        if (nestTargetEl) {
            nestTargetEl.classList.remove('drop-target-nest');
            nestTargetEl = null;
        }
    }

    // Returns top-level draggable elements in DOM order.
    // For news: mix of .story-group containers and standalone .item-row elements.
    // For vulnerability: flat list of .item-row elements.
    function getTopLevelDraggables() {
        if (section !== 'news') {
            return [...container.querySelectorAll('.item-row:not(.dragging)')];
        }
        const groups     = [...container.querySelectorAll(':scope > .story-group:not(.dragging)')];
        const standalones = [...container.querySelectorAll(':scope > .item-row:not(.dragging)')];
        return [...groups, ...standalones].sort((a, b) =>
            a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1
        );
    }

    // Gets the representative ID for a top-level draggable element.
    // Groups use data-primary-id; standalone rows use data-id.
    function getDraggableId(el) {
        return parseInt(
            el.classList.contains('story-group') ? el.dataset.primaryId : el.dataset.id,
            10
        );
    }

    container.addEventListener('dragover', (e) => {
        // Reject cross-section drags so the browser shows a "no-drop" cursor
        if (dragSourceSection !== null && dragSourceSection !== section) return;

        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';

        const topLevel = getTopLevelDraggables();

        // --- News section: detect drop-on center zone for nesting ---
        if (section === 'news') {
            // Only direct-child standalone .item-row elements can be nest targets
            const standalones = [...container.querySelectorAll(':scope > .item-row:not(.dragging)')];
            let foundNestTarget = null;

            for (const row of standalones) {
                const rect = row.getBoundingClientRect();
                if (e.clientY >= rect.top && e.clientY <= rect.bottom) {
                    const ratio = (e.clientY - rect.top) / rect.height;
                    if (ratio > 0.25 && ratio < 0.75) {
                        foundNestTarget = row;
                    }
                    break;
                }
            }

            if (foundNestTarget) {
                removeIndicator();
                if (nestTargetEl !== foundNestTarget) {
                    removeNestHighlight();
                    nestTargetEl = foundNestTarget;
                    foundNestTarget.classList.add('drop-target-nest');
                }
                dropBeforeRow = null;
                return;
            }
        }

        // Not in nest zone — clear any nest highlight and show reorder bar
        removeNestHighlight();

        dropBeforeRow = null;
        for (const el of topLevel) {
            const rect = el.getBoundingClientRect();
            if (e.clientY < rect.top + rect.height / 2) {
                dropBeforeRow = el;
                break;
            }
        }

        removeIndicator();
        dropIndicator = document.createElement('div');
        dropIndicator.className = 'drop-indicator';

        if (dropBeforeRow) {
            container.insertBefore(dropIndicator, dropBeforeRow);
        } else {
            container.appendChild(dropIndicator);
        }
    });

    container.addEventListener('dragleave', (e) => {
        // Only clear when the mouse truly leaves the container
        if (!container.contains(e.relatedTarget)) {
            removeIndicator();
            removeNestHighlight();
            dropBeforeRow = null;
        }
    });

    container.addEventListener('drop', async (e) => {
        e.preventDefault();

        const insertBefore = dropBeforeRow;
        const nestTarget   = nestTargetEl;
        removeIndicator();
        removeNestHighlight();
        dropBeforeRow = null;

        const draggedId = parseInt(e.dataTransfer.getData('text/plain'), 10);

        // Cross-section drop guard
        const sectionItems = state.items[section] || [];
        if (!sectionItems.find(item => item.id === draggedId)) return;

        // TODO (Phase 4): Secondary items should support re-nesting (§9.2) and
        // extraction (§9.3). For now we discard secondary drops explicitly so the
        // intent is clear; do NOT silently swallow — return early here.
        if (section === 'news') {
            const draggedItem = sectionItems.find(item => item.id === draggedId);
            if (draggedItem && draggedItem.parent_id !== null) return;
        }

        // --- Nest drop ---
        if (nestTarget) {
            const targetId = parseInt(nestTarget.dataset.id, 10);
            await handleDropOnItem(draggedId, targetId);
            return;
        }

        // --- Reorder drop ---
        const otherEls = getTopLevelDraggables().filter(el => getDraggableId(el) !== draggedId);
        const insertIdx = insertBefore ? otherEls.indexOf(insertBefore) : -1;
        const effectiveIdx = insertIdx < 0 ? otherEls.length : insertIdx;

        const topLevelIds = otherEls.map(el => getDraggableId(el));
        topLevelIds.splice(effectiveIdx, 0, draggedId);

        // For news: expand to full list (primary + its secondaries in sort order)
        // so the server-side count validation passes.
        const orderedIds = section !== 'news'
            ? topLevelIds
            : topLevelIds.flatMap(id => {
                const secs = sectionItems
                    .filter(i => i.parent_id === id)
                    .sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0));
                return [id, ...secs.map(s => s.id)];
            });

        try {
            await apiCall('reorder_items', { section, order: orderedIds });

            // Update sort_order in state and re-render
            const lookup = new Map(sectionItems.map(item => [item.id, item]));
            orderedIds.forEach((id, idx) => {
                const item = lookup.get(id);
                if (item) item.sort_order = idx;
            });
            state.items[section] = orderedIds.map(id => lookup.get(id)).filter(Boolean);

            if (section === 'vulnerability') renderVulnerabilityList();
            else renderNewsList();
        } catch {
            // Error toast already shown by apiCall; leave list unchanged
        }
    });
}

/* ----------------------------------------------------------
   Generate Show Notes button
   ---------------------------------------------------------- */
function bindGenerateButton() {
    const btn         = document.getElementById('btn-generate');
    const outputPanel = document.getElementById('output-panel');
    const textarea    = document.getElementById('output-markdown');

    btn.addEventListener('click', async () => {
        btn.disabled = true;
        btn.textContent = 'Generating…';
        btn.classList.add('loading'); // mirrors Fetch button loading pattern (Issue 6 fix)

        try {
            const data = await apiCall('generate_markdown');

            textarea.value = data.markdown || '';
            outputPanel.removeAttribute('hidden');

            if (Array.isArray(data.warnings)) {
                for (const warning of data.warnings) {
                    showToast('warning', warning);
                }
            }
        } catch {
            // Error toast already shown by apiCall
        } finally {
            btn.disabled = false;
            btn.textContent = 'Generate Show Notes';
            btn.classList.remove('loading');
        }
    });
}

/* ----------------------------------------------------------
   Copy to Clipboard — tries the modern Clipboard API first,
   falls back to the legacy execCommand approach for HTTP
   deployments where navigator.clipboard is unavailable.
   ---------------------------------------------------------- */
async function copyTextToClipboard(text) {
    // Modern API — only available in secure contexts (HTTPS or localhost)
    if (navigator.clipboard && navigator.clipboard.writeText) {
        try {
            await navigator.clipboard.writeText(text);
            return true;
        } catch {
            // Fall through to the legacy path
        }
    }

    // Legacy fallback: create a temporary off-screen textarea, select all,
    // then use the deprecated execCommand so it works on plain HTTP origins.
    const temp = document.createElement('textarea');
    temp.value = text;
    temp.setAttribute('readonly', '');
    temp.style.cssText = 'position:absolute;left:-9999px;top:-9999px';
    document.body.appendChild(temp);
    temp.select();
    try {
        return document.execCommand('copy');
    } finally {
        temp.remove();
    }
}

function bindCopyButton() {
    const btn      = document.getElementById('btn-copy');
    const textarea = document.getElementById('output-markdown');

    btn.addEventListener('click', async () => {
        const success = await copyTextToClipboard(textarea.value);

        if (success) {
            btn.textContent = '✓ Copied!';
            btn.classList.add('copied');

            setTimeout(() => {
                btn.textContent = '📋 Copy to Clipboard';
                btn.classList.remove('copied');
            }, 2000);
        } else {
            showToast('error', 'Could not copy to clipboard — please copy the text manually.');
        }
    });
}

/* ----------------------------------------------------------
   New Episode button
   ---------------------------------------------------------- */
function bindNewEpisodeButton() {
    const btn         = document.getElementById('btn-new-episode');
    const outputPanel = document.getElementById('output-panel');

    btn.addEventListener('click', async () => {
        const confirmed = window.confirm(
            'Start a new episode? All current items will be deleted. This cannot be undone.'
        );
        if (!confirmed) return;

        try {
            const data = await apiCall('reset_episode');

            state.episode        = data.episode;
            state.items          = data.items;

            renderEpisodeMeta();
            renderVulnerabilityList();
            renderNewsList();
            updateStartRecordingButton();

            // Hide the output panel so stale markdown is not shown
            outputPanel.setAttribute('hidden', '');
        } catch {
            // Error toast already shown by apiCall
        }
    });
}

/* ----------------------------------------------------------
   storyGroupModule
   Renders the news section with story-group containers (grouped
   items) and standalone item rows. Exposes renderNewsSection()
   which is called by renderNewsList().
   ---------------------------------------------------------- */
const storyGroupModule = (() => {

    /* Renders the entire news section as a DocumentFragment.
       Items with parent_id === null are primaries or standalones.
       Items with a non-null parent_id are secondaries, grouped
       under their primary. */
    function renderNewsSection(items) {
        const fragment = document.createDocumentFragment();

        // Index secondaries by their parent_id
        const secondariesByParent = new Map();
        for (const item of items) {
            if (item.parent_id !== null && item.parent_id !== undefined) {
                if (!secondariesByParent.has(item.parent_id)) {
                    secondariesByParent.set(item.parent_id, []);
                }
                secondariesByParent.get(item.parent_id).push(item);
            }
        }

        // Iterate top-level items in their existing sort order
        for (const item of items) {
            if (item.parent_id !== null && item.parent_id !== undefined) continue;

            const secondaries = secondariesByParent.get(item.id) || [];

            if (secondaries.length > 0) {
                fragment.appendChild(renderGroupContainer(item, secondaries));
            } else {
                // Standalone item: render with talking-points panel
                const row = renderItem(item, 'news');
                row.appendChild(talkingPointsModule.renderTalkingPointsPanel(item));
                fragment.appendChild(row);
            }
        }

        return fragment;
    }

    /* Builds a .story-group container for one primary and its secondaries. */
    function renderGroupContainer(primary, secondaries) {
        const groupDiv = document.createElement('div');
        groupDiv.className = 'story-group';
        groupDiv.draggable = true;
        groupDiv.dataset.primaryId = primary.id;

        // Group-level drag events — move the whole group as a unit
        groupDiv.addEventListener('dragstart', (e) => {
            // If the event originates from any element inside an .item-row, let the
            // item-row's own drag handle take over. Without this broad guard, dragging
            // a secondary item's text content would bubble up and overwrite dataTransfer
            // with the primary's ID (DnD bubbling hazard).
            if (e.target !== groupDiv && e.target.closest('.item-row')) return;
            dragSourceSection = 'news';
            e.dataTransfer.setData('text/plain', String(primary.id));
            e.dataTransfer.effectAllowed = 'move';
            const rect = groupDiv.getBoundingClientRect();
            e.dataTransfer.setDragImage(groupDiv, e.clientX - rect.left, e.clientY - rect.top);
            requestAnimationFrame(() => groupDiv.classList.add('dragging'));
        });

        groupDiv.addEventListener('dragend', () => {
            groupDiv.classList.remove('dragging');
            dragSourceSection = null;
        });

        // Visual group drag handle (not draggable itself — drag propagates to container)
        const handle = document.createElement('span');
        handle.className = 'story-group__drag-handle';
        handle.textContent = '⠿';
        handle.setAttribute('aria-hidden', 'true');
        groupDiv.appendChild(handle);

        // Primary item row with badge
        const primaryWrapper = document.createElement('div');
        primaryWrapper.className = 'story-group__primary';

        const primaryRow = renderItem(primary, 'news');

        const badge = document.createElement('span');
        badge.className = 'story-group__primary-badge';
        badge.textContent = 'PRIMARY';

        const rowHandle = primaryRow.querySelector('.drag-handle');
        if (rowHandle) {
            rowHandle.insertAdjacentElement('afterend', badge);
        } else {
            primaryRow.prepend(badge);
        }

        primaryWrapper.appendChild(primaryRow);

        // Talking-points panel attached to the primary item (task 9.6)
        primaryWrapper.appendChild(talkingPointsModule.renderTalkingPointsPanel(primary));

        groupDiv.appendChild(primaryWrapper);

        // Indented secondaries container
        const secondariesContainer = document.createElement('div');
        secondariesContainer.className = 'story-group__secondaries';

        for (const sec of secondaries) {
            secondariesContainer.appendChild(renderSecondaryItem(sec));
        }

        groupDiv.appendChild(secondariesContainer);

        return groupDiv;
    }

    /* Builds a secondary item row with a SECONDARY badge.
       Secondary items do not get a talking-points panel (spec §talking-points-ui). */
    function renderSecondaryItem(item) {
        const row = renderItem(item, 'news');

        const badge = document.createElement('span');
        badge.className = 'story-group__secondary-badge';
        badge.textContent = 'SECONDARY';

        const rowHandle = row.querySelector('.drag-handle');
        if (rowHandle) {
            rowHandle.insertAdjacentElement('afterend', badge);
        } else {
            row.prepend(badge);
        }

        return row;
    }

    // Only renderNewsSection is part of the public API (spec §3.4).
    // renderGroupContainer and renderSecondaryItem are internal helpers.
    return { renderNewsSection };
})();

/* ----------------------------------------------------------
   talkingPointsModule
   Renders and manages the inline contenteditable bullet editor
   for per-item recording notes (talking points).
   ---------------------------------------------------------- */
const talkingPointsModule = (() => {

    /* Creates one <li contenteditable> bullet, wiring the keydown handler. */
    function createBullet(text) {
        const li = document.createElement('li');
        li.contentEditable = 'true';
        li.spellcheck = true;
        li.textContent = text;

        if (!text) {
            // data-placeholder is read by the CSS ::before pseudo-element
            li.dataset.placeholder = 'Add a talking point…';
        }

        li.addEventListener('keydown', handleBulletKeydown);
        return li;
    }

    /* Returns a .talking-points-panel DOM node for the given item.
       Splits talking_points on \n to populate the bullet list.
       Empty talking_points renders one placeholder bullet. */
    function renderTalkingPointsPanel(item) {
        const panel = document.createElement('div');
        panel.className = 'talking-points-panel';
        panel.dataset.itemId = item.id;

        const label = document.createElement('span');
        label.className = 'talking-points-panel__label';
        label.textContent = 'Recording Notes — not included in show notes';
        label.setAttribute('aria-hidden', 'true');
        panel.appendChild(label);

        const ul = document.createElement('ul');
        ul.setAttribute('aria-label', `Recording notes for item ${item.id}`);

        const raw     = item.talking_points || '';
        // Filter out embedded empty lines (spec §10.3: "ignore blank lines from old data")
        const lines   = raw.split('\n').filter(l => l.trim() !== '');
        const bullets = lines.length > 0 ? lines : [''];

        for (const line of bullets) {
            ul.appendChild(createBullet(line));
        }

        panel.appendChild(ul);

        // Attach debounced auto-save on any input inside the panel
        const debouncedSave = createDebounce(
            () => saveTalkingPoints(item.id, panel),
            800
        );
        panel.addEventListener('input', debouncedSave);

        return panel;
    }

    /* Joins all <li> textContent values with \n, trims each line,
       and drops trailing empty lines. */
    function parseBullets(panelEl) {
        const lines = [...panelEl.querySelectorAll('li')]
            .map(li => li.textContent.trim());

        let end = lines.length;
        while (end > 0 && lines[end - 1] === '') {
            end--;
        }

        return lines.slice(0, end).join('\n');
    }

    /* Enter → insert new <li> after current and focus it.
       Backspace on empty <li> → remove it and focus the previous. */
    function handleBulletKeydown(e) {
        const li = e.currentTarget;

        if (e.key === 'Enter') {
            e.preventDefault();
            const newLi = createBullet('');
            if (li.nextSibling) {
                li.parentNode.insertBefore(newLi, li.nextSibling);
            } else {
                li.parentNode.appendChild(newLi);
            }
            newLi.focus();

        } else if (e.key === 'Backspace' && li.textContent === '') {
            // Never remove the last remaining bullet — the editor must always
            // have at least one <li> to accept new input.
            if (li.parentNode.querySelectorAll('li').length <= 1) return;
            e.preventDefault();
            const prev = li.previousElementSibling;
            li.remove();

            if (prev) {
                prev.focus();
                // Move caret to end of previous bullet
                const range = document.createRange();
                const sel   = window.getSelection();
                range.selectNodeContents(prev);
                range.collapse(false);
                sel.removeAllRanges();
                sel.addRange(range);
            }
        }
    }

    /* Calls update_talking_points. Best-effort — errors are already
       surfaced by apiCall's toast; we do not re-throw here. */
    async function saveTalkingPoints(itemId, panelEl) {
        const talkingPoints = parseBullets(panelEl);
        try {
            const data = await apiCall('update_talking_points', { itemId, talkingPoints });
            // Keep in-memory state in sync with the saved value
            const item = (state.items.news || []).find(i => i.id === itemId);
            if (item) item.talking_points = data.item.talking_points;
        } catch {
            // Error toast already shown by apiCall
        }
    }

    return { renderTalkingPointsPanel };
})();

/* ----------------------------------------------------------
   Initialise on DOMContentLoaded
   ---------------------------------------------------------- */
document.addEventListener('DOMContentLoaded', () => {
    // Clone INITIAL_STATE into module-scope state
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
    bindAuthorSuggestionsForAddPanel();
    bindDragAndDrop('vulnerability-list', 'vulnerability');
    bindDragAndDrop('news-list', 'news');
    bindGenerateButton();
    bindCopyButton();
    bindNewEpisodeButton();

    // Ensure buttons start in correct disabled state
    updateAddButtonState();
    updateStartRecordingButton();
});
