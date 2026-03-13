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

// Tracks the parent_id of the item currently being dragged.
// null  → top-level primary or standalone item
// <int> → secondary item; value is its parent primary's ID
// Used by the group-level dragover handler to route same-group reorders
// to reorder_group instead of letting them bubble up to extract_item.
let dragSourceParentId = null;

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
    const debounced = function (...args) {
        clearTimeout(timer);
        timer = setTimeout(() => fn.apply(this, args), delay);
    };
    debounced.cancel = function () {
        clearTimeout(timer);
    };
    return debounced;
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
        dragSourceSection  = section; // captured at module scope for dragover guards
        dragSourceParentId = item.parent_id ?? null;
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
        dragSourceSection  = null;
        dragSourceParentId = null;
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
   Article Input Modal
   Consolidates URL entry, metadata fetch, section selection,
   talking-point notes, and submission into a single dialog.
   ---------------------------------------------------------- */
const articleInputModal = (() => {
    let backdropEl = null;
    let abortController = null;

    /* ---------- DOM construction ---------- */

    function renderModal() {
        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop article-input-backdrop';
        backdrop.setAttribute('role', 'presentation');

        const dialog = document.createElement('div');
        dialog.className = 'modal-dialog article-input-modal';
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('aria-labelledby', 'aim-title');

        // --- Header ---
        const header = document.createElement('div');
        header.className = 'aim-header';

        const title = document.createElement('h2');
        title.id = 'aim-title';
        title.textContent = 'Add Article';

        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'aim-close';
        closeBtn.setAttribute('aria-label', 'Close');
        closeBtn.textContent = '×';

        header.appendChild(title);
        header.appendChild(closeBtn);

        // --- Body ---
        const body = document.createElement('div');
        body.className = 'aim-body';

        // URL field with fetch button
        const urlField = document.createElement('div');
        urlField.className = 'aim-field';
        const urlLabel = document.createElement('label');
        urlLabel.setAttribute('for', 'aim-url');
        urlLabel.textContent = 'URL';
        const urlRow = document.createElement('div');
        urlRow.className = 'aim-url-row';
        const urlInput = document.createElement('input');
        urlInput.type = 'url';
        urlInput.id = 'aim-url';
        urlInput.placeholder = 'https://example.com/article';
        urlInput.autocomplete = 'off';
        const fetchBtn = document.createElement('button');
        fetchBtn.type = 'button';
        fetchBtn.className = 'aim-fetch-btn';
        fetchBtn.textContent = 'Fetch Metadata';
        urlRow.appendChild(urlInput);
        urlRow.appendChild(fetchBtn);
        urlField.appendChild(urlLabel);
        urlField.appendChild(urlRow);

        // Status area (loading / warning)
        const statusArea = document.createElement('div');
        statusArea.id = 'aim-status';

        // Section selector
        const sectionField = document.createElement('div');
        sectionField.className = 'aim-field';
        const sectionLabel = document.createElement('label');
        sectionLabel.setAttribute('for', 'aim-section');
        sectionLabel.textContent = 'Section';
        const sectionSelect = document.createElement('select');
        sectionSelect.id = 'aim-section';
        const newsOption = document.createElement('option');
        newsOption.value = 'news';
        newsOption.textContent = 'News';
        const vulnOption = document.createElement('option');
        vulnOption.value = 'vulnerability';
        vulnOption.textContent = 'Vulnerability';
        sectionSelect.appendChild(newsOption);
        sectionSelect.appendChild(vulnOption);
        sectionField.appendChild(sectionLabel);
        sectionField.appendChild(sectionSelect);

        // Title field
        const titleField = document.createElement('div');
        titleField.className = 'aim-field';
        const titleFieldLabel = document.createElement('label');
        titleFieldLabel.setAttribute('for', 'aim-title-field');
        titleFieldLabel.textContent = 'Title';
        const titleInput = document.createElement('input');
        titleInput.type = 'text';
        titleInput.id = 'aim-title-field';
        titleInput.autocomplete = 'off';
        titleField.appendChild(titleFieldLabel);
        titleField.appendChild(titleInput);

        // Author name + author URL row
        const metaRow = document.createElement('div');
        metaRow.className = 'aim-meta-row';

        const authorField = document.createElement('div');
        authorField.className = 'aim-field';
        const authorLabel = document.createElement('label');
        authorLabel.setAttribute('for', 'aim-author-name');
        authorLabel.textContent = 'Author';
        const authorInput = document.createElement('input');
        authorInput.type = 'text';
        authorInput.id = 'aim-author-name';
        authorInput.autocomplete = 'off';
        authorField.appendChild(authorLabel);
        authorField.appendChild(authorInput);

        const authorUrlField = document.createElement('div');
        authorUrlField.className = 'aim-field';
        const authorUrlLabel = document.createElement('label');
        authorUrlLabel.setAttribute('for', 'aim-author-url');
        authorUrlLabel.textContent = 'Author URL';
        const authorUrlInput = document.createElement('input');
        authorUrlInput.type = 'url';
        authorUrlInput.id = 'aim-author-url';
        authorUrlInput.autocomplete = 'off';
        authorUrlField.appendChild(authorUrlLabel);
        authorUrlField.appendChild(authorUrlInput);

        metaRow.appendChild(authorField);
        metaRow.appendChild(authorUrlField);

        // Notes area (visible only for News section)
        const notesArea = document.createElement('div');
        notesArea.className = 'aim-notes';
        notesArea.id = 'aim-notes-section';
        const notesLabel = document.createElement('label');
        notesLabel.setAttribute('for', 'aim-talking-points');
        notesLabel.appendChild(document.createTextNode('Recording Notes'));
        const notesHint = document.createElement('span');
        notesHint.className = 'aim-notes-hint';
        notesHint.textContent = ' — not included in show notes';
        notesLabel.appendChild(notesHint);
        const notesTextarea = document.createElement('textarea');
        notesTextarea.id = 'aim-talking-points';
        notesTextarea.placeholder = 'One talking point per line…';
        notesTextarea.rows = 6;
        notesTextarea.spellcheck = true;
        notesArea.appendChild(notesLabel);
        notesArea.appendChild(notesTextarea);

        body.appendChild(urlField);
        body.appendChild(statusArea);
        body.appendChild(sectionField);
        body.appendChild(titleField);
        body.appendChild(metaRow);
        body.appendChild(notesArea);

        // --- Footer ---
        const footer = document.createElement('div');
        footer.className = 'aim-footer';

        const addNextBtn = document.createElement('button');
        addNextBtn.type = 'button';
        addNextBtn.className = 'aim-btn-secondary';
        addNextBtn.id = 'aim-add-next';
        addNextBtn.textContent = 'Add & Next';
        addNextBtn.disabled = true;

        const addBtn = document.createElement('button');
        addBtn.type = 'button';
        addBtn.className = 'aim-btn-primary';
        addBtn.id = 'aim-add';
        addBtn.textContent = 'Add Article';
        addBtn.disabled = true;

        footer.appendChild(addBtn);
        footer.appendChild(addNextBtn);

        dialog.appendChild(header);
        dialog.appendChild(body);
        dialog.appendChild(footer);
        backdrop.appendChild(dialog);

        return backdrop;
    }

    /* ---------- Helpers ---------- */

    function hasContent() {
        if (!backdropEl) return false;
        const url     = backdropEl.querySelector('#aim-url').value.trim();
        const title   = backdropEl.querySelector('#aim-title-field').value.trim();
        const author  = backdropEl.querySelector('#aim-author-name').value.trim();
        const notes   = backdropEl.querySelector('#aim-talking-points').value.trim();
        return !!(url || title || author || notes);
    }

    function clearFields(preserveSection) {
        if (!backdropEl) return;
        backdropEl.querySelector('#aim-url').value = '';
        backdropEl.querySelector('#aim-title-field').value = '';
        backdropEl.querySelector('#aim-author-name').value = '';
        backdropEl.querySelector('#aim-author-url').value = '';
        backdropEl.querySelector('#aim-talking-points').value = '';
        if (!preserveSection) {
            backdropEl.querySelector('#aim-section').value = 'news';
            // Reset notes visibility
            backdropEl.querySelector('.aim-notes').classList.remove('hidden');
        }
        // Clear status area
        backdropEl.querySelector('#aim-status').innerHTML = '';
        // Disable submit buttons
        updateSubmitButtons();
    }

    function updateSubmitButtons() {
        if (!backdropEl) return;
        const urlValue = backdropEl.querySelector('#aim-url').value.trim();
        const disabled = urlValue.length === 0;
        backdropEl.querySelector('#aim-add').disabled = disabled;
        backdropEl.querySelector('#aim-add-next').disabled = disabled;
    }

    function showLoading() {
        const statusArea = backdropEl.querySelector('#aim-status');
        statusArea.innerHTML = '';
        const loading = document.createElement('div');
        loading.className = 'aim-loading';
        const spinner = document.createElement('span');
        spinner.className = 'aim-spinner';
        const text = document.createElement('span');
        text.textContent = 'Fetching metadata…';
        loading.appendChild(spinner);
        loading.appendChild(text);
        statusArea.appendChild(loading);
    }

    function showWarning(message) {
        const statusArea = backdropEl.querySelector('#aim-status');
        statusArea.innerHTML = '';
        const warning = document.createElement('div');
        warning.className = 'aim-warning';
        warning.textContent = message;
        statusArea.appendChild(warning);
    }

    function clearStatus() {
        if (!backdropEl) return;
        backdropEl.querySelector('#aim-status').innerHTML = '';
    }

    /* ---------- Auto-fetch on paste ---------- */

    async function fetchMetadata(url) {
        // Abort any previous in-flight request
        if (abortController) {
            abortController.abort();
        }
        abortController = new AbortController();

        showLoading();

        try {
            const response = await fetch('api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'scrape_url', url }),
                signal: abortController.signal,
            });

            const json = await response.json();

            if (!json.success) {
                showWarning(json.error || 'Could not fetch metadata.');
                return;
            }

            const data = json.data;
            clearStatus();

            // Populate fields only if still empty — user edits are not overwritten
            if (data.title && !backdropEl.querySelector('#aim-title-field').value.trim()) {
                backdropEl.querySelector('#aim-title-field').value = data.title;
            }
            if (data.author_name && !backdropEl.querySelector('#aim-author-name').value.trim()) {
                backdropEl.querySelector('#aim-author-name').value = data.author_name;
            }
            if (data.author_url && !backdropEl.querySelector('#aim-author-url').value.trim()) {
                backdropEl.querySelector('#aim-author-url').value = data.author_url;
            }

            if (data.scrape_error) {
                showWarning(data.scrape_error);
            }
        } catch (err) {
            if (err.name === 'AbortError') return; // Silently ignore aborted requests
            showWarning('Could not fetch metadata — check the URL and try again.');
        } finally {
            abortController = null;
        }
    }

    function handleUrlPaste(e) {
        // Use microtask delay so the pasted value populates the input first
        Promise.resolve().then(() => {
            const value = e.target.value.trim();
            if (/^https?:\/\//.test(value)) {
                fetchMetadata(value);
            }
        });
    }

    /* ---------- Author suggestions inside modal ---------- */

    function attachModalAuthorSuggestions() {
        const authorInput = backdropEl.querySelector('#aim-author-name');
        const authorUrlInput = backdropEl.querySelector('#aim-author-url');
        const urlInput = backdropEl.querySelector('#aim-url');

        let dropdown = null;
        let requestSeq = 0;

        function onSelect(author) {
            authorInput.value = author.author_name;
            authorUrlInput.value = author.author_url || '';
            closeSuggestionDropdown(dropdown);
            dropdown = null;
        }

        async function fetchAndRenderSuggestions(query) {
            const domain = extractDomain(urlInput.value.trim());
            const seq = ++requestSeq;
            try {
                const data = await apiCall('get_author_suggestions', { domain, query });
                if (seq !== requestSeq) return;
                closeSuggestionDropdown(dropdown);
                dropdown = renderSuggestionDropdown(
                    authorInput,
                    data.domain_authors || [],
                    data.other_authors  || [],
                    onSelect
                );
            } catch {
                // Suggestions are best-effort
            }
        }

        const debouncedFetch = createDebounce((q) => fetchAndRenderSuggestions(q), 300);

        authorInput.addEventListener('focus', () => fetchAndRenderSuggestions(''));
        authorInput.addEventListener('input', () => debouncedFetch(authorInput.value));
        authorInput.addEventListener('blur', () => {
            setTimeout(() => {
                closeSuggestionDropdown(dropdown);
                dropdown = null;
            }, 150);
        });
    }

    /* ---------- Submit flows ---------- */

    async function submitArticle(keepOpen) {
        const section = backdropEl.querySelector('#aim-section').value;
        const url     = backdropEl.querySelector('#aim-url').value.trim();
        const titleVal   = backdropEl.querySelector('#aim-title-field').value.trim();
        const authorName = backdropEl.querySelector('#aim-author-name').value.trim();
        const authorUrl  = backdropEl.querySelector('#aim-author-url').value.trim();
        const talkingPoints = backdropEl.querySelector('#aim-talking-points').value.trim();

        const payload = {
            section,
            url,
            title: titleVal,
            author_name: authorName,
            author_url: authorUrl,
        };

        // Only include talking_points for news section when there is content
        if (section === 'news' && talkingPoints) {
            payload.talking_points = talkingPoints;
        }

        try {
            const data = await apiCall('add_item', payload);

            // Update local state and re-render the affected section
            const newItem = data.item;
            state.items[section].push(newItem);

            if (section === 'vulnerability') {
                renderVulnerabilityList();
            } else {
                renderNewsList();
            }
            updateStartRecordingButton();

            const sectionLabel = section === 'news' ? 'News' : 'Vulnerability';
            showToast('success', `Article added to ${sectionLabel}`);

            if (keepOpen) {
                clearFields(true);
                backdropEl.querySelector('#aim-url').focus();
            } else {
                closeModal();
            }
        } catch {
            // Error toast already displayed by apiCall
        }
    }

    /* ---------- Focus trapping ---------- */

    function getFocusableElements() {
        if (!backdropEl) return [];
        return Array.from(
            backdropEl.querySelectorAll(
                'input, select, textarea, button, [tabindex]:not([tabindex="-1"])'
            )
        ).filter(el => !el.disabled && el.offsetParent !== null);
    }

    function handleFocusTrap(e) {
        if (e.key !== 'Tab') return;

        const focusable = getFocusableElements();
        if (focusable.length === 0) return;

        const first = focusable[0];
        const last  = focusable[focusable.length - 1];

        if (e.shiftKey) {
            if (document.activeElement === first) {
                e.preventDefault();
                last.focus();
            }
        } else {
            if (document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }
    }

    /* ---------- Dismiss handling ---------- */

    async function attemptClose() {
        if (hasContent()) {
            const confirmed = await showConfirmDialog({
                title: 'Discard unsaved changes?',
                body:  'The article has not been added. Discard all entered data?',
                cancelLabel:  'Keep Editing',
                confirmLabel: 'Discard',
            });
            if (!confirmed) return;
        }
        closeModal();
    }

    function handleKeydown(e) {
        if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            attemptClose();
            return;
        }
        handleFocusTrap(e);
    }

    function handleBackdropClick(e) {
        if (e.target === backdropEl) {
            attemptClose();
        }
    }

    /* ---------- Public API ---------- */

    function isOpen() {
        return backdropEl !== null;
    }

    function openModal() {
        if (isOpen()) return;

        backdropEl = renderModal();
        document.body.appendChild(backdropEl);

        // Wire events
        const urlInput    = backdropEl.querySelector('#aim-url');
        const sectionSel  = backdropEl.querySelector('#aim-section');
        const closeBtn    = backdropEl.querySelector('.aim-close');
        const fetchBtn    = backdropEl.querySelector('.aim-fetch-btn');
        const addBtn      = backdropEl.querySelector('#aim-add');
        const addNextBtn  = backdropEl.querySelector('#aim-add-next');

        // URL input events
        urlInput.addEventListener('paste', handleUrlPaste);
        urlInput.addEventListener('input', updateSubmitButtons);

        // Fetch button
        fetchBtn.addEventListener('click', () => {
            const url = urlInput.value.trim();
            if (/^https?:\/\//.test(url)) {
                fetchMetadata(url);
            }
        });

        // Section selector toggles notes visibility
        let savedNotes = '';
        sectionSel.addEventListener('change', () => {
            const notesArea = backdropEl.querySelector('.aim-notes');
            const textarea  = backdropEl.querySelector('#aim-talking-points');
            if (sectionSel.value === 'vulnerability') {
                savedNotes = textarea.value;
                notesArea.classList.add('hidden');
            } else {
                notesArea.classList.remove('hidden');
                textarea.value = savedNotes;
            }
        });

        // Close button
        closeBtn.addEventListener('click', () => attemptClose());

        // Backdrop click
        backdropEl.addEventListener('click', handleBackdropClick);

        // Keyboard: Escape + focus trap
        backdropEl.addEventListener('keydown', handleKeydown);

        // Submit buttons
        addBtn.addEventListener('click', () => submitArticle(false));
        addNextBtn.addEventListener('click', () => submitArticle(true));

        // Author suggestions
        attachModalAuthorSuggestions();

        // Auto-focus URL input
        urlInput.focus();
    }

    function closeModal() {
        if (!backdropEl) return;

        // Abort any in-flight fetch
        if (abortController) {
            abortController.abort();
            abortController = null;
        }

        backdropEl.remove();
        backdropEl = null;

        // Restore focus to the trigger button
        const triggerBtn = document.getElementById('btn-add-article');
        if (triggerBtn) triggerBtn.focus();
    }

    return { openModal, closeModal, isOpen };
})();

/* ----------------------------------------------------------
   Keyboard shortcut: Ctrl+Shift+A opens Article Input Modal
   ---------------------------------------------------------- */
document.addEventListener('keydown', (e) => {
    if (e.ctrlKey && e.shiftKey && (e.key === 'A' || e.key === 'a')) {
        // Guard: recording mode active
        if (document.body.classList.contains('recording-mode')) return;
        // Guard: article modal already open
        if (articleInputModal.isOpen()) return;
        // Guard: another modal is visible
        if (document.querySelector('.modal-backdrop')) return;

        e.preventDefault();
        articleInputModal.openModal();
    }
});

/* ----------------------------------------------------------
   10.11 — Add Article button — opens Article Input Modal
   ---------------------------------------------------------- */
function bindAddArticleButton() {
    const btn = document.getElementById('btn-add-article');
    if (!btn) return;

    btn.addEventListener('click', () => {
        articleInputModal.openModal();
    });

    // Tooltip showing keyboard shortcut
    btn.title = 'Add Article (Ctrl+Shift+A)';
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
            // Both standalone .item-row and .story-group containers can be nest targets.
            // Merge and sort into DOM order so hit-testing is position-accurate.
            const nestCandidates = [
                ...container.querySelectorAll(':scope > .item-row:not(.dragging)'),
                ...container.querySelectorAll(':scope > .story-group:not(.dragging)'),
            ].sort((a, b) =>
                a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1
            );
            let foundNestTarget = null;

            for (const el of nestCandidates) {
                const rect = el.getBoundingClientRect();
                if (e.clientY >= rect.top && e.clientY <= rect.bottom) {
                    const ratio = (e.clientY - rect.top) / rect.height;
                    if (ratio > 0.25 && ratio < 0.75) {
                        foundNestTarget = el;
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

        const draggedId    = parseInt(e.dataTransfer.getData('text/plain'), 10);
        const sectionItems = state.items[section] || [];

        // Cross-section drop guard
        if (!sectionItems.find(item => item.id === draggedId)) return;

        const draggedItem = sectionItems.find(item => item.id === draggedId);

        // --- Secondary extraction: promote secondary to top-level ---
        // Within-group reorders are intercepted by the group's own drop listener
        // (which calls e.stopPropagation()), so this branch only fires when the
        // secondary is dragged to a position outside its parent group.
        if (section === 'news' && draggedItem && draggedItem.parent_id !== null) {
            const topLevel        = getTopLevelDraggables();
            const topLevelIds     = topLevel.map(el => getDraggableId(el));
            const insertIdx       = insertBefore ? topLevel.indexOf(insertBefore) : -1;
            const pos             = insertIdx < 0 ? topLevelIds.length : insertIdx;
            const newTopLevelOrder = [...topLevelIds];
            newTopLevelOrder.splice(pos, 0, draggedId);

            try {
                const data = await apiCall('extract_item', { itemId: draggedId, newTopLevelOrder });
                state.items.news = data.items.news;
                renderNewsList();
            } catch {
                // Error toast shown by apiCall
            }
            return;
        }

        // --- Nest drop ---
        if (nestTarget) {
            // Story groups expose their primary ID via data-primary-id;
            // standalone item rows use data-id.
            const targetId = nestTarget.classList.contains('story-group')
                ? parseInt(nestTarget.dataset.primaryId, 10)
                : parseInt(nestTarget.dataset.id, 10);
            await handleDropOnItem(draggedId, targetId);
            return;
        }

        // --- Reorder drop ---
        // Send only the top-level (primary/standalone) IDs; the server keeps
        // secondaries attached to their primaries via parent_id unchanged.
        const otherEls     = getTopLevelDraggables().filter(el => getDraggableId(el) !== draggedId);
        const insertIdx    = insertBefore ? otherEls.indexOf(insertBefore) : -1;
        const effectiveIdx = insertIdx < 0 ? otherEls.length : insertIdx;
        const topLevelIds  = otherEls.map(el => getDraggableId(el));
        topLevelIds.splice(effectiveIdx, 0, draggedId);

        try {
            await apiCall('reorder_items', { section, order: topLevelIds });

            // Update sort_order for top-level items then rebuild state in new order
            const lookup = new Map(sectionItems.map(item => [item.id, item]));
            topLevelIds.forEach((id, idx) => {
                const item = lookup.get(id);
                if (item) item.sort_order = idx;
            });

            if (section === 'news') {
                // Interleave each primary with its secondaries in their existing order
                const reordered = [];
                for (const primaryId of topLevelIds) {
                    const primary = lookup.get(primaryId);
                    if (primary) {
                        reordered.push(primary);
                        const secs = sectionItems
                            .filter(i => i.parent_id === primaryId)
                            .sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0));
                        reordered.push(...secs);
                    }
                }
                state.items.news = reordered;
                renderNewsList();
            } else {
                state.items[section] = topLevelIds.map(id => lookup.get(id)).filter(Boolean);
                renderVulnerabilityList();
            }
        } catch {
            // Error toast already shown by apiCall; leave list unchanged
        }
    });
}

/* ----------------------------------------------------------
   Markdown Overlay — modal for viewing and copying generated
   show notes markdown, replacing the old static output panel.
   ---------------------------------------------------------- */
const markdownOverlay = (() => {
    let backdropEl = null;

    /**
     * Build and display the overlay with the provided markdown content.
     */
    function openOverlay(markdown) {
        if (backdropEl) return;

        // Backdrop
        backdropEl = document.createElement('div');
        backdropEl.className = 'modal-backdrop markdown-overlay-backdrop';
        backdropEl.setAttribute('role', 'presentation');

        // Dialog
        const dialog = document.createElement('div');
        dialog.className = 'markdown-overlay';
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('aria-labelledby', 'mo-title');

        // Header
        const header = document.createElement('div');
        header.className = 'mo-header';

        const title = document.createElement('h2');
        title.id = 'mo-title';
        title.textContent = 'Generated Show Notes';

        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'mo-close';
        closeBtn.setAttribute('aria-label', 'Close overlay');
        closeBtn.textContent = '\u00d7';
        closeBtn.addEventListener('click', closeOverlay);

        header.appendChild(title);
        header.appendChild(closeBtn);

        // Body — read-only textarea with markdown content
        const body = document.createElement('div');
        body.className = 'mo-body';

        const textarea = document.createElement('textarea');
        textarea.id = 'mo-markdown';
        textarea.readOnly = true;
        textarea.setAttribute('aria-label', 'Generated markdown content');
        textarea.value = markdown;

        body.appendChild(textarea);

        // Footer — copy button
        const footer = document.createElement('div');
        footer.className = 'mo-footer';

        const copyBtn = document.createElement('button');
        copyBtn.type = 'button';
        copyBtn.id = 'mo-copy';
        copyBtn.className = 'btn-primary';
        copyBtn.textContent = 'Copy to Clipboard';
        copyBtn.addEventListener('click', handleCopy);

        footer.appendChild(copyBtn);

        // Assemble dialog
        dialog.appendChild(header);
        dialog.appendChild(body);
        dialog.appendChild(footer);
        backdropEl.appendChild(dialog);
        document.body.appendChild(backdropEl);

        // Dismiss handlers
        backdropEl.addEventListener('click', handleBackdropClick);
        document.addEventListener('keydown', handleKeydown);

        // Focus the copy button — the primary action
        copyBtn.focus();
    }

    /**
     * Close the overlay and clean up DOM and listeners.
     */
    function closeOverlay() {
        if (!backdropEl) return;

        document.removeEventListener('keydown', handleKeydown);
        backdropEl.remove();
        backdropEl = null;

        // Return focus to the generate button
        const generateBtn = document.getElementById('btn-generate');
        if (generateBtn) generateBtn.focus();
    }

    /**
     * Copy textarea content to clipboard with visual feedback.
     */
    async function handleCopy() {
        const textarea = document.getElementById('mo-markdown');
        const copyBtn = document.getElementById('mo-copy');
        if (!textarea || !copyBtn) return;

        const copied = await copyTextToClipboard(textarea.value);

        if (copied) {
            copyBtn.textContent = 'Copied!';
            copyBtn.classList.add('copied');

            setTimeout(() => {
                copyBtn.textContent = 'Copy to Clipboard';
                copyBtn.classList.remove('copied');
            }, 2000);
        } else {
            showToast('warning', 'Could not copy — check browser permissions.');
        }
    }

    /**
     * Close when clicking the backdrop (outside the dialog).
     */
    function handleBackdropClick(e) {
        if (e.target === backdropEl) {
            closeOverlay();
        }
    }

    /**
     * Close on Escape key.
     */
    function handleKeydown(e) {
        if (e.key === 'Escape') {
            closeOverlay();
        }
    }

    return { openOverlay, closeOverlay };
})();


/* ----------------------------------------------------------
   Generate Show Notes button
   ---------------------------------------------------------- */
function bindGenerateButton() {
    const btn = document.getElementById('btn-generate');

    btn.addEventListener('click', async () => {
        btn.disabled = true;
        btn.textContent = 'Generating…';
        btn.classList.add('loading');

        try {
            const data = await apiCall('generate_markdown');
            const markdown = data.markdown || '';

            markdownOverlay.openOverlay(markdown);

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


/* ----------------------------------------------------------
   New Episode button
   ---------------------------------------------------------- */
function bindNewEpisodeButton() {
    const btn = document.getElementById('btn-new-episode');

    btn.addEventListener('click', async () => {
        const confirmed = window.confirm(
            'Start a new episode? All current items will be deleted. This cannot be undone.'
        );
        if (!confirmed) return;

        try {
            const data = await apiCall('reset_episode');

            state.episode = data.episode;
            state.items   = data.items;

            renderEpisodeMeta();
            renderVulnerabilityList();
            renderNewsList();
            updateStartRecordingButton();
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

    /* Binds drag-and-drop handlers on a .story-group__secondaries container so
       that secondary items can be reordered within their parent group.
       Only activates when dragSourceParentId matches this group's primaryId,
       which prevents interference with whole-group or top-level drags.
       e.stopPropagation() in both dragover and drop keeps the section-level
       handler from treating within-group drops as extract_item operations. */
    function bindSecondaryDragAndDrop(secondariesContainer, primaryId) {
        let dropBeforeRow = null;
        let dropIndicator = null;

        function clearIndicator() {
            if (dropIndicator) { dropIndicator.remove(); dropIndicator = null; }
            dropBeforeRow = null;
        }

        secondariesContainer.addEventListener('dragover', (e) => {
            if (dragSourceSection !== 'news' || dragSourceParentId !== primaryId) return;
            e.preventDefault();
            e.stopPropagation(); // prevent section-level dragover from overwriting dropBeforeRow

            const secRows = [...secondariesContainer.querySelectorAll('.item-row:not(.dragging)')];
            dropBeforeRow = null;
            for (const row of secRows) {
                const rect = row.getBoundingClientRect();
                if (e.clientY < rect.top + rect.height / 2) {
                    dropBeforeRow = row;
                    break;
                }
            }

            if (dropIndicator) dropIndicator.remove();
            dropIndicator = document.createElement('div');
            dropIndicator.className = 'drop-indicator';
            if (dropBeforeRow) {
                secondariesContainer.insertBefore(dropIndicator, dropBeforeRow);
            } else {
                secondariesContainer.appendChild(dropIndicator);
            }
        });

        secondariesContainer.addEventListener('dragleave', (e) => {
            if (!secondariesContainer.contains(e.relatedTarget)) clearIndicator();
        });

        // Clean up indicator if the drag ends without a valid drop (e.g. Escape key)
        secondariesContainer.addEventListener('dragend', clearIndicator);

        secondariesContainer.addEventListener('drop', async (e) => {
            if (dragSourceSection !== 'news' || dragSourceParentId !== primaryId) return;
            e.preventDefault();
            e.stopPropagation(); // prevent section-level drop from also firing

            const savedBefore = dropBeforeRow;
            clearIndicator();

            const draggedId = parseInt(e.dataTransfer.getData('text/plain'), 10);
            const secRows   = [...secondariesContainer.querySelectorAll('.item-row:not(.dragging)')];
            const otherIds  = secRows.map(row => parseInt(row.dataset.id, 10));
            const insertIdx = savedBefore
                ? otherIds.indexOf(parseInt(savedBefore.dataset.id, 10))
                : -1;
            const effectiveIdx = insertIdx < 0 ? otherIds.length : insertIdx;

            const newSecondaryOrder = [...otherIds];
            newSecondaryOrder.splice(effectiveIdx, 0, draggedId);

            try {
                const data = await apiCall('reorder_group', { primaryId, newSecondaryOrder });
                state.items.news = data.items.news;
                renderNewsList();
            } catch {
                // Error toast shown by apiCall
            }
        });
    }

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
            dragSourceSection  = 'news';
            dragSourceParentId = null; // whole-group drag is a top-level operation
            e.dataTransfer.setData('text/plain', String(primary.id));
            e.dataTransfer.effectAllowed = 'move';
            const rect = groupDiv.getBoundingClientRect();
            e.dataTransfer.setDragImage(groupDiv, e.clientX - rect.left, e.clientY - rect.top);
            requestAnimationFrame(() => groupDiv.classList.add('dragging'));
        });

        groupDiv.addEventListener('dragend', () => {
            groupDiv.classList.remove('dragging');
            dragSourceSection  = null;
            dragSourceParentId = null;
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

        // Wire within-group secondary reordering on the secondaries container.
        bindSecondaryDragAndDrop(secondariesContainer, primary.id);

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
   Renders and manages the textarea-based editor for per-item
   recording notes (talking points). Each line in the textarea
   represents one talking point (newline-separated).
   ---------------------------------------------------------- */
const talkingPointsModule = (() => {

    /* Parses textarea value into the storage format: split on \n,
       trim each line, filter empty lines, re-join with \n. */
    function parseTextareaValue(textarea) {
        return textarea.value
            .split('\n')
            .map(line => line.trim())
            .filter(line => line !== '')
            .join('\n');
    }

    /* Calls update_talking_points. Best-effort — errors are already
       surfaced by apiCall's toast; we do not re-throw here. */
    async function saveTalkingPoints(itemId, textarea) {
        const talkingPoints = parseTextareaValue(textarea);
        try {
            const data = await apiCall('update_talking_points', { itemId, talkingPoints });
            // Keep in-memory state in sync with the saved value
            const item = (state.items.news || []).find(i => i.id === itemId);
            if (item) item.talking_points = data.item.talking_points;
        } catch {
            // Error toast already shown by apiCall
        }
    }

    /* Returns a .talking-points-panel DOM node for the given item.
       Renders a <textarea> with talking_points lines as content. */
    function renderTalkingPointsPanel(item) {
        const panel = document.createElement('div');
        panel.className = 'talking-points-panel';
        panel.dataset.itemId = item.id;

        const label = document.createElement('label');
        label.className = 'talking-points-panel__label';
        label.textContent = 'Recording Notes — not included in show notes';

        const textarea = document.createElement('textarea');
        textarea.className = 'talking-points-textarea';
        textarea.dataset.itemId = item.id;
        textarea.setAttribute('aria-label', `Recording notes for item ${item.id}`);
        textarea.placeholder = 'One talking point per line...';
        textarea.rows = 3;
        textarea.spellcheck = true;

        // Populate from existing data — join lines with \n
        const raw = item.talking_points || '';
        const lines = raw.split('\n').filter(l => l.trim() !== '');
        textarea.value = lines.join('\n');

        // Associate label with textarea
        const textareaId = `talking-points-${item.id}`;
        textarea.id = textareaId;
        label.setAttribute('for', textareaId);

        panel.appendChild(label);
        panel.appendChild(textarea);

        // Debounced auto-save on input (800ms)
        const debouncedSave = createDebounce(
            () => saveTalkingPoints(item.id, textarea),
            800
        );
        textarea.addEventListener('input', debouncedSave);

        // Immediate save on blur (cancel pending debounce by saving now)
        textarea.addEventListener('blur', () => {
            debouncedSave.cancel();
            saveTalkingPoints(item.id, textarea);
        });

        return panel;
    }

    return { renderTalkingPointsPanel };
})();

/* ----------------------------------------------------------
   recordingModule
   Manages recording mode: run order, host view, keyboard nav,
   audience window lifecycle, and WS status indicator.
   Exposes: start(), exit(), buildRunOrder(appState)
   ---------------------------------------------------------- */
const recordingModule = (() => {
    let runOrder            = [];
    let currentIndex        = 0;
    let audienceWindow      = null;
    let keyHandler          = null;
    let audienceWindowPollInterval = null;

    /* ---- Run order ---- */

    function buildRunOrder(appState) {
        const vulnItems = (appState.items.vulnerability || [])
            .slice()
            .sort((a, b) => a.sort_order - b.sort_order);

        // Only primary/standalone news items (parent_id === null) are navigation stops
        const newsItems = (appState.items.news || [])
            .filter(item => item.parent_id === null)
            .sort((a, b) => a.sort_order - b.sort_order);

        const order = [];

        for (const item of vulnItems) {
            order.push({ type: 'item', item, segment: 'vulnerability' });
        }

        // Segment break only when both sections have at least one item
        if (vulnItems.length > 0 && newsItems.length > 0) {
            order.push({ type: 'segment_break', segment: 'news' });
        }

        for (const item of newsItems) {
            order.push({ type: 'item', item, segment: 'news' });
        }

        return order;
    }

    /* ---- Audience window ---- */

    function openAudienceWindow() {
        return window.open(
            '/audience.php',
            'cncAudienceWindow',
            `width=${screen.availWidth},height=${screen.availHeight},left=0,top=0,menubar=no,toolbar=no,status=no,resizable=yes`
        );
    }

    function closeAudienceWindow() {
        if (audienceWindow && !audienceWindow.closed) {
            audienceWindow.close();
        }
        audienceWindow = null;
    }

    /* ---- Navigation ---- */

    /**
     * Blocks navigation to non-http(s) URLs and private/loopback IP ranges.
     * Defence-in-depth: item URLs come from the DB, but a corrupted row or
     * future code path could still produce a harmful URL.
     */
    function isNavigableUrl(url) {
        try {
            const { protocol, hostname } = new URL(url);
            if (!['http:', 'https:'].includes(protocol)) return false;
            const privateRanges = [
                /^127\./,
                /^10\./,
                /^172\.(1[6-9]|2\d|3[01])\./,
                /^192\.168\./,
                /^::1$/,
                /^localhost$/i,
                /^0\.0\.0\.0$/,
            ];
            return !privateRanges.some(re => re.test(hostname));
        } catch {
            return false;
        }
    }

    function navigate(index) {
        currentIndex = index;
        const entry  = runOrder[index];
        renderHostView(entry);
        if (entry.type === 'item' && audienceWindow && !audienceWindow.closed) {
            if (!isNavigableUrl(entry.item.url)) {
                console.warn('[recording] Blocked navigation to unsafe URL:', entry.item.url);
                return;
            }
            try {
                audienceWindow.location.href = entry.item.url;
            } catch (e) {
                console.error('[recording] Failed to navigate audience window:', e);
            }
        }
    }

    /* ---- Keyboard handler ---- */

    function handleRecordingKey(e) {
        switch (e.key) {
            case 'ArrowRight':
                if (currentIndex < runOrder.length - 1) navigate(currentIndex + 1);
                break;
            case ' ':
                e.preventDefault();
                if (currentIndex < runOrder.length - 1) navigate(currentIndex + 1);
                break;
            case 'ArrowLeft':
                if (currentIndex > 0) navigate(currentIndex - 1);
                break;
            case 'Home':
                navigate(0);
                break;
            case 'End':
                navigate(runOrder.length - 1);
                break;
            case 'Escape':
                exit();
                break;
        }
    }

    /* ---- Host view rendering ---- */

    function renderHostView(entry) {
        const hostView      = document.getElementById('host-view');
        const contentEl     = hostView.querySelector('.hv-content');
        const segBreakEl    = hostView.querySelector('.hv-segment-break');
        const counterEl     = hostView.querySelector('.hv-nav-counter');
        const prevBtn       = hostView.querySelector('#hv-btn-prev');
        const nextBtn       = hostView.querySelector('#hv-btn-next');
        const totalItems    = runOrder.filter(e => e.type === 'item').length;

        if (entry.type === 'segment_break') {
            counterEl.textContent    = 'Segment break';
            contentEl.style.display  = 'none';
            segBreakEl.style.display = 'flex';
        } else {
            // Count how many item entries we have reached (1-indexed)
            let itemNumber = 0;
            for (let i = 0; i <= currentIndex; i++) {
                if (runOrder[i].type === 'item') itemNumber++;
            }
            counterEl.textContent = `Item ${itemNumber} of ${totalItems}`;

            contentEl.style.display  = '';
            segBreakEl.style.display = 'none';

            const item = entry.item;
            contentEl.querySelector('.hv-segment-label').textContent =
                (entry.segment || '').toUpperCase();
            contentEl.querySelector('.hv-title').textContent  = item.title || '';
            contentEl.querySelector('.hv-url').textContent    = item.url   || '';

            const tpContainer = contentEl.querySelector('.hv-talking-points');
            const points = item.talking_points
                ? item.talking_points.split('\n').filter(p => p.trim())
                : [];

            if (points.length > 0) {
                tpContainer.style.display = '';
                const ul = tpContainer.querySelector('ul');
                ul.innerHTML = '';
                for (const point of points) {
                    const li = document.createElement('li');
                    li.textContent = point;
                    ul.appendChild(li);
                }
            } else {
                tpContainer.style.display = 'none';
            }
        }

        if (prevBtn) prevBtn.disabled = currentIndex === 0;
        if (nextBtn) nextBtn.disabled = currentIndex === runOrder.length - 1;
    }

    /* ---- Audience window status indicator ---- */

    function updateAudienceIndicator(isOpen) {
        const hostView = document.getElementById('host-view');
        if (!hostView) return;
        const dot  = hostView.querySelector('.hv-ws-indicator');
        const text = hostView.querySelector('.hv-ws-text');
        if (!dot || !text) return;
        if (isOpen) {
            dot.classList.add('connected');
            text.textContent = 'Audience: Open';
        } else {
            dot.classList.remove('connected');
            text.textContent = 'Audience: Closed';
        }
    }

    /* ---- Build host view DOM ---- */

    function buildHostViewHTML() {
        const hostView = document.getElementById('host-view');
        hostView.innerHTML = `
            <div class="hv-topbar">
                <div class="hv-topbar__left">
                    <span class="hv-ws-indicator" aria-hidden="true"></span>
                    <span class="hv-ws-text"></span>
                </div>
                <div class="hv-topbar__center">
                    <button class="hv-exit-btn" type="button" id="hv-btn-exit">Exit Recording</button>
                </div>
                <div class="hv-topbar__right">
                    <span class="hv-nav-counter" aria-live="polite"></span>
                </div>
            </div>
            <div class="hv-content">
                <div class="hv-segment-label" aria-label="Section"></div>
                <h2 class="hv-title"></h2>
                <div class="hv-url"></div>
                <div class="hv-talking-points"><ul></ul></div>
            </div>
            <div class="hv-segment-break" style="display:none">
                <div class="hv-segment-break__word" aria-label="Segment break: News">NEWS</div>
                <div class="hv-segment-break__hint">← press Next to continue →</div>
            </div>
            <div class="hv-nav-bar">
                <button class="hv-nav-btn" id="hv-btn-prev" type="button">← Prev</button>
                <button class="hv-nav-btn" id="hv-btn-next" type="button">Next →</button>
            </div>
        `;

        hostView.querySelector('#hv-btn-exit').addEventListener('click', exit);
        hostView.querySelector('#hv-btn-prev').addEventListener('click', () => {
            if (currentIndex > 0) navigate(currentIndex - 1);
        });
        hostView.querySelector('#hv-btn-next').addEventListener('click', () => {
            if (currentIndex < runOrder.length - 1) navigate(currentIndex + 1);
        });
    }

    /* ---- Popup blocked inline message ---- */

    function showPopupBlockedMessage() {
        const existing = document.getElementById('popup-blocked-msg');
        if (existing) existing.remove();

        const msg = document.createElement('p');
        msg.id = 'popup-blocked-msg';
        msg.setAttribute('role', 'alert');
        msg.style.cssText =
            'color:var(--color-danger);padding:8px var(--spacing-lg);font-size:0.9rem;margin:0;';
        msg.textContent =
            'Popup blocked — please allow popups for this page, then try again.';

        const appFooter = document.getElementById('app-footer');
        if (appFooter) appFooter.insertAdjacentElement('beforebegin', msg);
        setTimeout(() => msg.remove(), 6000);
    }

    /* ---- Entry and exit flow ---- */

    function start() {
        const btn = document.getElementById('btn-start-recording');
        btn.disabled = true;

        // 1. Open audience window (popup blocked → abort with inline message)
        audienceWindow = openAudienceWindow();
        if (!audienceWindow) {
            showPopupBlockedMessage();
            btn.disabled = false;
            updateStartRecordingButton();
            return;
        }

        // 2. Enter recording mode
        buildHostViewHTML();
        runOrder = buildRunOrder(state);

        // Drive indicator from real state immediately, then keep polling every second
        updateAudienceIndicator(audienceWindow && !audienceWindow.closed);
        audienceWindowPollInterval = setInterval(() => {
            updateAudienceIndicator(audienceWindow && !audienceWindow.closed);
        }, 1000);

        document.body.classList.add('recording-mode');

        keyHandler = handleRecordingKey;
        document.addEventListener('keydown', keyHandler);

        navigate(0);
    }

    function exit() {
        document.body.classList.remove('recording-mode');

        if (keyHandler) {
            document.removeEventListener('keydown', keyHandler);
            keyHandler = null;
        }

        if (audienceWindowPollInterval) {
            clearInterval(audienceWindowPollInterval);
            audienceWindowPollInterval = null;
        }

        closeAudienceWindow();

        const btn = document.getElementById('btn-start-recording');
        if (btn) {
            btn.disabled = false;
            updateStartRecordingButton();
        }
    }

    return { start, exit, buildRunOrder };
})();

/* ----------------------------------------------------------
   Global plain-text paste handler
   Strips rich-text formatting from clipboard content in all
   editable fields (input, textarea, contenteditable).
   ---------------------------------------------------------- */
function handleGlobalPaste(e) {
    const target = e.target;

    // Only intercept paste in editable contexts
    const isEditable =
        target.tagName === 'TEXTAREA' ||
        (target.tagName === 'INPUT' && !target.readOnly && !target.disabled) ||
        target.isContentEditable;

    if (!isEditable) return;

    // Do not intercept read-only textareas
    if (target.tagName === 'TEXTAREA' && target.readOnly) return;

    const text = e.clipboardData.getData('text/plain');
    e.preventDefault();

    if (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA') {
        const start = target.selectionStart;
        const end = target.selectionEnd;
        target.value = target.value.slice(0, start) + text + target.value.slice(end);
        target.selectionStart = target.selectionEnd = start + text.length;
        target.dispatchEvent(new Event('input', { bubbles: true }));
    } else if (target.isContentEditable) {
        document.execCommand('insertText', false, text);
    }
}

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
    bindAddArticleButton();
    bindDragAndDrop('vulnerability-list', 'vulnerability');
    bindDragAndDrop('news-list', 'news');
    bindGenerateButton();
    bindNewEpisodeButton();

    // Wire "Start Recording" button to recordingModule
    const btnStartRecording = document.getElementById('btn-start-recording');
    if (btnStartRecording) {
        btnStartRecording.addEventListener('click', () => recordingModule.start());
    }

    // Global plain-text paste: strip formatting from all editable fields
    document.addEventListener('paste', handleGlobalPaste);

    // Ensure buttons start in correct disabled state
    updateStartRecordingButton();
});
