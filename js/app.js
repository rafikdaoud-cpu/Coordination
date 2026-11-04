/**
 * Coordination - Application Nextcloud
 * CSM Taksit
 */

(function () {
    'use strict';

    // Ouverte depuis l'application Android Coordination : barre Nextcloud masquée, déconnexion dans l'en-tête.
    const IN_ANDROID_APP = /CoordinationAndroid\//.test(navigator.userAgent);
    if (IN_ANDROID_APP) document.documentElement.classList.add('co-android');

    const APP_VERSION = '1.7.0';
    let initialized = false, dossierRequest = 0;
    let mutationBusy = false;
    let lastModalKey = '';
    /** Scan en cours : { itemId, pages:[{jpeg,width,height,preview}], busy } — pages déjà réduites en JPEG. */
    let scanSession = null;

    const state = {
        dossiers: [],
        hasMore: false, nextOffset: 0, pageSize: 15,
        selectedDossierIds: new Set(),
        currentDossier: null,
        activeTab: 'all',
        filterWilaya: 'all',
        searchQuery: '',
        wilayas: [],
        documentTemplates: [],
        customFields: [],
        banks: [],
        users: [],
        coordinators: [],
        currentUser: {
            uid: '',
            display_name: '',
            groups: [],
            is_admin: false,
            is_rcoordinateur: false,
            is_coordinateur: false,
            is_backoffice: false,
            is_commercial: false,
            is_readonly: true,
        },
        apiError: '',
        loading: false,
        dirty: false,
        modal: null, // 'detail', 'new', 'import', 'settings'
        activeModalTab: 'info', // 'info', 'checklist', 'appointment', 'history'
        scanMaxBytes: 20 * 1024 * 1024,
        scanDocMode: false,
    };

    const statusMap = {
        'new': { label: 'Nouveau', class: 'co-badge-new' },
        'assigned': { label: 'Attribué (À appeler)', class: 'co-badge-assigned' },
        'appointment_set': { label: 'RDV fixé (Scanner & valider les pièces)', class: 'co-badge-appointment_set' },
        'collected': { label: 'Collecté (En contrôle BO)', class: 'co-badge-collected' },
        'validated': { label: 'Validé & Conforme', class: 'co-badge-validated' },
        'rejected': { label: 'Rejeté / Incomplet', class: 'co-badge-rejected' },
    };

    async function apiRequest(endpoint, method = 'GET', body = null, isFormData = false, timeoutMs = 0) {
        const url = OC.generateUrl(`apps/coordination/${endpoint}`);
        const headers = {
            'requesttoken': OC.requestToken || ''
        };

        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), timeoutMs || (isFormData ? 60000 : 20000));
        const options = { method, headers, signal:controller.signal };

        if (body) {
            if (isFormData) {
                options.body = body;
            } else {
                headers['Content-Type'] = 'application/json';
                options.body = JSON.stringify(body);
            }
        }

        try {
            const res = await fetch(url, options);
            const text = await res.text();
            try {
                const result = JSON.parse(text);
                return res.ok ? result : {success:false,error:result.error || 'Erreur serveur ' + res.status};
            } catch (jsonErr) {
                return { success: false, error: 'Réponse serveur inattendue. Rechargez la page ou contactez le responsable.' };
            }
        } catch (err) {
            console.error('API Error:', err);
            return { success: false, error: err.name === 'AbortError' ? 'Délai dépassé. Vérifiez le dossier avant de réessayer.' : 'Connexion indisponible. Vos champs restent affichés ; réessayez une fois le réseau revenu.' };
        } finally { clearTimeout(timeout); }
    }

    async function init() {
        if (initialized) return; initialized = true;
        state.apiError = '';
        await loadConfig();
        if (!state.currentUser.uid) { render(); return; }
        state.activeTab = state.currentUser.is_rcoordinateur ? 'new' : state.currentUser.is_backoffice && !state.currentUser.is_coordinateur ? 'collected' : state.currentUser.is_coordinateur ? 'assigned' : 'all';
        await loadUsers();
        await loadDossiers();
        render();
    }

    async function loadConfig() {
        const res = await apiRequest('api/config');
        if (res.success) {
            state.wilayas = res.wilayas || [];
            state.documentTemplates = res.document_templates || [];
            state.customFields = res.custom_fields || [];
            state.banks = res.banks || [];
            if (res.scan_max_bytes) state.scanMaxBytes = Number(res.scan_max_bytes);
            if (res.current_user) {
                state.currentUser = res.current_user;
            }
        } else {
            state.apiError = 'Configuration : ' + (res.error || 'réponse invalide du serveur');
        }
    }

    async function loadUsers() {
        const res = await apiRequest('api/users-groups');
        if (res.success) {
            state.users = res.all_users || [];
            state.coordinators = res.coordinators || res.all_users || [];
        }
    }

    function userLabel(uid) {
        if (!uid) {
            return 'Non assigné';
        }
        const found = (state.users || []).find(u => u.uid === uid)
            || (state.coordinators || []).find(u => u.uid === uid);
        return found ? found.display_name : uid;
    }

    function communesOf(wilayaValue) {
        const code = String(wilayaValue || '').trim().slice(0, 2);
        return (window.CO_COMMUNES && window.CO_COMMUNES[code]) || [];
    }

    function communeOptionsHtml(wilayaValue, currentValue) {
        const list = communesOf(wilayaValue);
        const current = (currentValue || '').trim();
        const isOther = current !== '' && list.indexOf(current) === -1;
        return '<option value="">-- Sélectionner une commune --</option>'
            + list.map(c => `<option value="${escapeHtml(c)}" ${(!isOther && current === c) ? 'selected' : ''}>${escapeHtml(c)}</option>`).join('')
            + `<option value="__other__" ${isOther ? 'selected' : ''}>✏️ Autre commune…</option>`;
    }

    function communeFieldHtml(id, wilayaValue, currentValue, disabled) {
        const current = (currentValue || '').trim();
        const isOther = current !== '' && communesOf(wilayaValue).indexOf(current) === -1;
        return `
            <select class="co-input" id="${id}" ${disabled ? 'disabled' : ''}>${communeOptionsHtml(wilayaValue, current)}</select>
            <input type="text" class="co-input" id="${id}-other" value="${isOther ? escapeHtml(current) : ''}" placeholder="Saisir le nom de la commune" style="margin-top:0.5rem;${isOther ? '' : ' display:none;'}" ${disabled ? 'disabled' : ''}>
        `;
    }

    function readCommune(id) {
        const sel = document.getElementById(id);
        if (!sel) return '';
        if (sel.value === '__other__') {
            const other = document.getElementById(id + '-other');
            return other ? other.value.trim() : '';
        }
        return sel.value;
    }

    function bindCommune(selectId, wilayaId) {
        const sel = document.getElementById(selectId);
        const other = document.getElementById(selectId + '-other');
        const wil = document.getElementById(wilayaId);
        if (sel && other) {
            sel.addEventListener('change', () => {
                const isOther = sel.value === '__other__';
                other.style.display = isOther ? '' : 'none';
                if (isOther) other.focus();
            });
        }
        if (wil && sel) {
            wil.addEventListener('change', () => {
                sel.innerHTML = communeOptionsHtml(wil.value, '');
                if (other) { other.value = ''; other.style.display = 'none'; }
            });
        }
    }

    async function loadDossiers(append = false) {
        const requestId = ++dossierRequest;
        state.loading = true;
        const offset = append ? state.nextOffset : 0;
        const res = await apiRequest(`api/dossiers?status=${state.activeTab}&wilaya=${encodeURIComponent(state.filterWilaya)}&search=${encodeURIComponent(state.searchQuery)}&limit=${state.pageSize}&offset=${offset}`);
        if (requestId !== dossierRequest) return;
        if (res.success) {
            const rows = res.data || [];
            state.dossiers = append ? state.dossiers.concat(rows.filter(d => !state.dossiers.some(x => x.id === d.id))) : rows;
            state.hasMore = !!res.has_more; state.nextOffset = Number(res.next_offset || 0);
            state.apiError = '';
            if (!append) state.selectedDossierIds.clear();
        } else state.apiError = 'Dossiers : ' + (res.error || 'réponse invalide du serveur');
        state.loading = false;
    }
    function canCoordinate(d) {
        return state.currentUser.is_rcoordinateur || (state.currentUser.is_coordinateur && d.assigned_coordinator === state.currentUser.uid);
    }
    let feedbackTimer = null;
    function feedback(message, error = true) {
        const slot = document.querySelector('.co-modal .co-feedback') || document.getElementById('co-live-feedback');
        if (!slot) return;
        slot.textContent = message; slot.classList.toggle('is-error',error); slot.hidden = false;
        clearTimeout(feedbackTimer);
        // Sur téléphone le message reste collé en haut de la fiche ; un succès s'efface seul.
        if (!error) feedbackTimer = setTimeout(() => { if (slot.isConnected && slot.textContent === message) slot.hidden = true; }, 6000);
    }

    /** Onglet d'ouverture selon l'étape : RDV d'abord, puis pièces (scan + validation). */
    function defaultModalTab(d) {
        if (d && canCoordinate(d) && ['assigned','rejected'].includes(d.status)) return 'appointment';
        if (d && canCoordinate(d) && d.status === 'appointment_set') return 'checklist';
        return state.currentUser.is_backoffice || state.currentUser.is_coordinateur ? 'checklist' : 'info';
    }
    async function saveStatus(id, status, note) {
        if (mutationBusy) return;
        mutationBusy = true;
        const buttons = document.querySelectorAll('.co-modal-footer button'); buttons.forEach(b => b.disabled = true);
        try {
            const res = await apiRequest(`api/dossiers/${id}/status`,'POST',{status,note});
            if (!res.success) { feedback(res.error || 'Enregistrement impossible.'); return; }
            await openDossierDetail(Number(id)); await loadDossiers();
        } finally { mutationBusy = false; buttons.forEach(b => b.disabled = false); }
    }

    async function openDossierDetail(id) {
        state.loading = true;
        render();
        // Utilise l'URL /api/dossiers?detail_id=X 100% stable
        const res = await apiRequest(`api/dossiers?detail_id=${id}`);
        state.loading = false;
        if (res.success) {
            const same = state.modal === 'detail' && Number(state.currentDossier?.dossier?.id) === Number(id);
            state.dirty = false;
            state.currentDossier = res.data;
            state.modal = 'detail';
            if (!same) state.activeModalTab = defaultModalTab(res.data.dossier);
            render();
        } else {
            console.error('Detail Error:', res);
            alert('Erreur: ' + (res.error || 'Erreur lors du chargement du dossier'));
            render();
        }
    }

    async function deleteDossier(id) {
        if (!confirm('Voulez-vous vraiment supprimer définitivement ce dossier et toutes ses données associées ?')) {
            return;
        }

        const res = await apiRequest(`api/dossiers/${id}/delete`, 'POST');
        if (res.success) {
            alert('Dossier supprimé avec succès.');
            state.modal = null;
            state.currentDossier = null;
            await loadDossiers();
            render();
        } else {
            alert('Erreur lors de la suppression : ' + (res.error || 'Action impossible'));
        }
    }

    function formatDate(timestamp) {
        if (!timestamp) return '-';
        const d = new Date(timestamp * 1000);
        return d.toLocaleDateString('fr-FR', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function render() {
        const root = document.getElementById('coord-app-root');
        if (!root) return;
        const search = document.activeElement;
        const editingSearch = search?.id === 'search-input' ? {value:search.value,start:search.selectionStart,end:search.selectionEnd} : null;

        const modalKey = state.modal ? state.modal + ':' + state.activeModalTab + ':' + (state.currentDossier?.dossier?.id || '') : '';
        const previousBody = root.querySelector('.co-modal-body');
        const keepScroll = previousBody && modalKey === lastModalKey ? previousBody.scrollTop : null;

        const isRcoord = state.currentUser.is_rcoordinateur;
        const isReadonly = state.currentUser.is_readonly;
        root.classList.toggle('co-own-dossiers',state.currentUser.is_coordinateur && !isRcoord && !state.currentUser.is_backoffice);

        root.innerHTML = `
            <!-- HEADER -->
            <div class="co-header">
                <div class="co-title">
                    <h1>🤝 Coordination <span style="font-size:0.9rem; color:#718096; font-weight:normal;">(CSM Taksit)</span> <span style="font-size:0.7rem; color:#a0aec0; border:1px solid #e2e8f0; border-radius:10px; padding:1px 7px; vertical-align:middle;">v${APP_VERSION}</span></h1>
                    <p>
                        Gestion des dossiers, prises de rendez-vous client, collecte physique et validation Back-Office
                        ${state.currentUser.is_commercial ? '<span style="background:#fef3c7; color:#92400e; padding:2px 8px; border-radius:12px; font-size:0.78rem; font-weight:bold; margin-left:8px;">👁️ Commercial — lecture seule</span>' : (isReadonly ? '<span style="background:#e2e8f0; color:#475569; padding:2px 8px; border-radius:12px; font-size:0.78rem; font-weight:bold; margin-left:8px;">👁️ Consultation seule</span>' : '')}
                        ${state.currentUser.is_coordinateur && !isRcoord ? '<span style="background:#dbeafe; color:#1e40af; padding:2px 8px; border-radius:12px; font-size:0.78rem; font-weight:bold; margin-left:8px;">👤 Mes dossiers</span>' : ''}
                        ${state.currentUser.is_backoffice && !isRcoord ? '<span style="background:#f3e8ff; color:#6b21a8; padding:2px 8px; border-radius:12px; font-size:0.78rem; font-weight:bold; margin-left:8px;">🏢 Espace Back-Office</span>' : ''}
                        ${isRcoord ? '<span style="background:#dcfce7; color:#166534; padding:2px 8px; border-radius:12px; font-size:0.78rem; font-weight:bold; margin-left:8px;">⭐ Responsable Coordination</span>' : ''}
                    </p>
                </div>
                <div class="co-actions">
                    ${IN_ANDROID_APP ? `<a class="co-btn co-btn-secondary co-logout" href="${escapeHtml(OC.generateUrl('/logout') + '?requesttoken=' + encodeURIComponent(OC.requestToken || ''))}">⎋ Déconnexion</a>` : ''}
                    ${isRcoord ? `
                        <button class="co-btn co-btn-primary" id="btn-import-modal">📥 Importer Excel (.xlsx)</button>
                        <button class="co-btn co-btn-success" id="btn-new-dossier">➕ Nouveau Dossier</button>
                        <button class="co-btn co-btn-secondary" id="btn-settings-modal">⚙️ Paramètres</button>
                    ` : ''}
                </div>
            </div>

            ${state.apiError ? `<div style="margin: 0 0 1rem; padding: 0.75rem 1rem; background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; border-radius: 8px; font-size: 0.9rem;">⚠️ Communication avec le serveur impossible — ${escapeHtml(state.apiError)}</div>` : ''}

            <div id="co-live-feedback" class="co-feedback" role="status" aria-live="polite" hidden></div>
            <div class="co-workspace-title">${isRcoord ? 'Pilotage des dossiers' : state.currentUser.is_backoffice ? 'Contrôle des pièces' : state.currentUser.is_coordinateur ? 'Mes dossiers sur le terrain' : 'Consultation des dossiers'}</div>
            <!-- FILTERS -->
            <div class="co-filters">
                <div class="co-tabs">
                    ${renderStatusTabs()}
                </div>
                <div class="co-filter-inputs">
                    <input type="text" class="co-input co-search" id="search-input" placeholder="🔍 Rechercher client, n° dossier, téléphone, banque..." value="${escapeHtml(state.searchQuery)}">
                    
                    ${renderWilayaFilter()}

                    <button class="co-btn co-btn-secondary" id="btn-refresh">🔄 Rafraîchir</button>
                </div>
            </div>

            <!-- BATCH BAR (Visible pour les responsables) -->
            ${isRcoord ? renderBatchBar() : ''}

            <!-- TABLE -->
            <div class="co-table-card">
                <div class="co-table-responsive">
                    <table class="co-table">
                        <thead>
                            <tr>
                                ${isRcoord ? '<th style="width: 40px;"><input type="checkbox" id="select-all-checkbox" ' + (state.selectedDossierIds.size > 0 && state.selectedDossierIds.size === state.dossiers.length ? 'checked' : '') + '></th>' : ''}
                                <th>Date Création</th>
                                <th>N° Dossier</th>
                                <th>Client & Téléphone</th>
                                <th>Localisation</th>
                                <th>Banque</th>
                                <th>Statut</th>
                                <th>Rendez-vous Client</th>
                                <th>Coordinateur</th>
                                <th style="text-align: right; white-space:nowrap;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${renderTableRows()}
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="co-pagination"><span>${state.dossiers.length} dossier(s) affiché(s)</span>${state.hasMore ? '<button class="co-btn co-btn-secondary" id="btn-load-more">Afficher les suivants</button>' : ''}</div>
            <!-- MODALS -->
            ${renderCurrentModal()}
        `;

        bindEvents();
        lastModalKey = modalKey;
        const body = root.querySelector('.co-modal-body');
        if (body && keepScroll !== null) body.scrollTop = keepScroll;
        // Onglet actif de la fiche toujours visible sur petit écran (barre d'onglets défilante).
        const activeModalTab = root.querySelector('.co-modal-tabs .co-tab.active');
        if (activeModalTab) {
            const bar = activeModalTab.parentElement, a = bar.getBoundingClientRect(), t = activeModalTab.getBoundingClientRect();
            if (t.left < a.left) bar.scrollLeft += t.left - a.left - 8;
            else if (t.right > a.right) bar.scrollLeft += t.right - a.right + 8;
        }
        const activeFilter = root.querySelector('.co-tabs .co-tab.active');
        if (activeFilter) {
            const tabs = activeFilter.parentElement;
            const area = tabs.getBoundingClientRect(), active = activeFilter.getBoundingClientRect();
            if (active.left < area.left || active.right > area.right) tabs.scrollLeft += active.left - area.left;
        }
        if (editingSearch) {
            const input = document.getElementById('search-input');
            input.value = editingSearch.value;
            input.focus({preventScroll:true});
            input.setSelectionRange(editingSearch.start,editingSearch.end);
        }
    }

    function renderStatusTabs() {
        const tabs = [
            { id: 'all', label: 'Tous' },
            { id: 'new', label: 'Nouveaux (À attribuer)' },
            { id: 'assigned', label: 'Attribués (À appeler)' },
            { id: 'appointment_set', label: 'RDV Fixés (À récupérer)' },
            { id: 'collected', label: 'Collectés (En contrôle BO)' },
            { id: 'validated', label: 'Validés' },
            { id: 'rejected', label: 'Rejetés' },
        ];

        return tabs.map(t => `
            <button class="co-tab ${state.activeTab === t.id ? 'active' : ''}" data-tab="${t.id}">
                ${t.label}
            </button>
        `).join('');
    }

    function renderWilayaFilter() {
        const current = state.filterWilaya;
        const label = current === 'all' ? '📍 Toutes les wilayas' : `📍 ${current}`;
        const options = [
            `<button type="button" class="co-dd-option ${current === 'all' ? 'selected' : ''}" data-value="all">Toutes les wilayas (58 Wilayas d'Algérie)</button>`,
            ...state.wilayas.map(w => {
                const val = w.full || w.name;
                return `<button type="button" class="co-dd-option ${current === val ? 'selected' : ''}" data-value="${escapeHtml(val)}">${escapeHtml(val)}</button>`;
            })
        ].join('');

        return `
            <div class="co-dd" id="wilaya-dd">
                <button type="button" class="co-dd-toggle" id="wilaya-dd-toggle" aria-haspopup="listbox" aria-expanded="false">
                    <span class="co-dd-label">${escapeHtml(label)}</span>
                    <span class="co-dd-chevron">▾</span>
                </button>
                <div class="co-dd-panel" id="wilaya-dd-panel" role="listbox" aria-label="Filtrer par wilaya">
                    <input type="text" class="co-input" id="wilaya-dd-search" placeholder="🔍 Rechercher une wilaya..." autocomplete="off">
                    <div class="co-dd-body">
                        <div class="co-dd-list" id="wilaya-dd-list">${options}</div>
                        <div class="co-dd-arrows">
                            <button type="button" class="co-dd-arrow" id="wilaya-dd-up" title="Monter" aria-label="Monter">▲</button>
                            <button type="button" class="co-dd-arrow" id="wilaya-dd-down" title="Descendre" aria-label="Descendre">▼</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    function renderBatchBar() {
        return `
            <div class="co-batch-bar">
                <div style="display:flex; align-items:center; gap:0.5rem; font-weight:600;">
                    <span>📌 Affectation Coordinateur (Groupe csm_coordinateur) :</span>
                    <span style="color:#0f766e;">${state.selectedDossierIds.size} sélectionné(s)</span>
                    ${state.filterWilaya !== 'all' ? `<span style="color:#718096; font-size:0.85rem;">(ou tous de ${state.filterWilaya})</span>` : ''}
                </div>
                <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
                    <select class="co-input" id="batch-coord-select" style="width:auto; min-width:260px;">
                        <option value="">-- Choisir un coordinateur --</option>
                        ${state.coordinators.map(u => `<option value="${escapeHtml(u.uid)}">${escapeHtml(u.display_name)} (${escapeHtml(u.uid)})</option>`).join('')}
                    </select>

                    <button class="co-btn co-btn-primary co-btn-sm" id="btn-batch-assign">Attribuer au coordinateur</button>
                </div>
            </div>
        `;
    }

    function renderTableRows() {
        const isRcoord = state.currentUser.is_rcoordinateur;

        if (state.dossiers.length === 0) {
            return `<tr><td colspan="${isRcoord ? 10 : 9}" style="text-align:center; padding:3rem; color:#718096;">Aucun dossier trouvé pour ces filtres.</td></tr>`;
        }

        return state.dossiers.map(d => {
            const st = statusMap[d.status] || { label: d.status, class: 'co-badge-new' };
            const isChecked = state.selectedDossierIds.has(d.id);
            const formattedCreationDate = formatDate(d.created_at);

            return `
                <tr>
                    ${isRcoord ? `<td class="co-col-check"><input type="checkbox" class="row-checkbox" data-id="${d.id}" ${isChecked ? 'checked' : ''}></td>` : ''}
                    <td class="co-col-optional" data-label="Créé le">
                        <span style="color:#4a5568; font-size:0.85rem; font-weight:600; white-space:nowrap;">📅 ${formattedCreationDate}</span>
                    </td>
                    <td data-label="Dossier">
                        <strong style="color:#0082c9; cursor:pointer;" class="open-detail" data-id="${d.id}">${escapeHtml(d.case_number)}</strong>
                    </td>
                    <td data-label="Client">
                        <div style="font-weight:600;">${escapeHtml(d.client_name)}</div>
                        ${d.phone ? `<a href="tel:${escapeHtml(d.phone)}" style="font-size:0.85rem; color:#0082c9; text-decoration:none;">📞 ${escapeHtml(d.phone)}</a>` : '<span style="color:#a0aec0; font-size:0.85rem;">Aucun tél</span>'}
                    </td>
                    <td data-label="Localisation">
                        <div>📍 ${escapeHtml(d.wilaya || '-')}</div>
                        <small style="color:#718096;">${escapeHtml(d.commune || '')}</small>
                    </td>
                    <td class="co-col-optional" data-label="Banque"><span style="font-weight:600; color:#4a5568;">🏦 ${escapeHtml(d.bank || '-')}</span></td>
                    <td data-label="Statut"><span class="co-badge ${st.class}">${escapeHtml(st.label)}</span></td>
                    <td data-label="Rendez-vous">
                        ${d.appointment_date ? `
                            <div style="font-weight:600; font-size:0.85rem;">📅 ${escapeHtml(d.appointment_date)} ${escapeHtml(d.appointment_time || '')}</div>
                        ` : '<span style="color:#a0aec0; font-size:0.85rem;">Non planifié</span>'}
                    </td>
                    <td data-label="Attribué à">
                        <span style="font-weight:600; color:#2b6cb0;">${escapeHtml(userLabel(d.assigned_coordinator))}</span>
                    </td>
                    <td class="co-col-actions" style="text-align: right; white-space:nowrap;">
                        <button class="co-btn co-btn-primary co-btn-sm open-detail" data-id="${d.id}">
                            ${state.currentUser.is_readonly ? '👁️ Consulter' : 'Ouvrir & Traiter'}
                        </button>
                        ${isRcoord ? `
                            <button class="co-btn co-btn-danger co-btn-sm btn-delete-row" data-id="${d.id}" title="Supprimer définitivement le dossier">🗑️</button>
                        ` : ''}
                    </td>
                </tr>
            `;
        }).join('');
    }

    function renderCurrentModal() {
        if (!state.modal) return '';
        if (state.modal === 'detail' && state.currentDossier) return renderDetailModal();
        if (state.modal === 'new') return renderNewDossierModal();
        if (state.modal === 'import') return renderImportModal();
        if (state.modal === 'settings') return renderSettingsModal();
        return '';
    }

    function renderDetailModal() {
        const d = state.currentDossier.dossier;
        const checklist = state.currentDossier.checklist || [];
        const history = state.currentDossier.history || [];
        const st = statusMap[d.status] || { label: d.status, class: 'co-badge-new' };
        const creationStr = formatDate(d.created_at);
        const isRcoord = state.currentUser.is_rcoordinateur;

        return `
            <div class="co-modal-backdrop" id="modal-backdrop">
                <div class="co-modal" role="dialog" aria-modal="true" aria-label="Dossier Coordination">
                    <div class="co-modal-header">
                        <div>
                            <h2>📁 Dossier ${escapeHtml(d.case_number || ('#' + d.id))}</h2>
                            <div style="margin-top:0.15rem; font-size:0.95rem; color:#2d3748;">
                                👤 <strong>${escapeHtml(d.client_name)}</strong>
                                ${d.phone
                                    ? `&nbsp;·&nbsp;<a href="tel:${escapeHtml(d.phone)}" style="color:#0082c9; font-weight:600; text-decoration:none;">📞 ${escapeHtml(d.phone)}</a>`
                                    : '&nbsp;·&nbsp;<span style="color:#a0aec0;">Aucun téléphone</span>'}
                            </div>
                            <div style="margin-top:0.3rem;">
                                <span class="co-badge ${st.class}">${escapeHtml(st.label)}</span>
                                <span style="margin-left:0.5rem; font-size:0.85rem; color:#718096;">
                                    📅 Créé le: <strong>${creationStr}</strong> &nbsp;|&nbsp; Banque: ${escapeHtml(d.bank || 'N/A')} &nbsp;|&nbsp; Wilaya: ${escapeHtml(d.wilaya || 'N/A')}
                                </span>
                            </div>
                        </div>
                        <button class="co-modal-close" id="modal-close" aria-label="Fermer le dossier">&times;</button>
                    </div>

                    <div class="co-modal-tabs" aria-label="Rubriques du dossier">
                        <button class="co-tab ${state.activeModalTab === 'info' ? 'active' : ''}" data-modal-tab="info">Fiche client</button>
                        <button class="co-tab ${state.activeModalTab === 'appointment' ? 'active' : ''}" data-modal-tab="appointment">${canCoordinate(d) ? '① ' : ''}Rendez-vous</button>
                        <button class="co-tab ${state.activeModalTab === 'checklist' ? 'active' : ''}" data-modal-tab="checklist">${canCoordinate(d) ? '② ' : ''}Pièces</button>
                        <button class="co-tab ${state.activeModalTab === 'history' ? 'active' : ''}" data-modal-tab="history">Historique</button>
                    </div>

                    <div class="co-modal-body"><div class="co-feedback" role="status" aria-live="polite" hidden></div>
                        ${state.activeModalTab === 'checklist' ? renderChecklistTab(checklist) : ''}
                        ${state.activeModalTab === 'appointment' ? renderAppointmentTab(d) : ''}
                        ${state.activeModalTab === 'info' ? renderInfoTab(d) : ''}
                        ${state.activeModalTab === 'history' ? renderHistoryTab(history) : ''}
                    </div>

                    <div class="co-modal-footer">
                        ${renderTransitionActions(d)}
                        ${renderNextStep(d)}
                        ${isRcoord ? `<button class="co-btn co-btn-danger" id="btn-modal-delete-dossier" data-id="${d.id}" style="margin-right:auto;">🗑️ Supprimer le dossier</button>` : ''}
                        <button class="co-btn co-btn-secondary" id="modal-close-btn">Fermer</button>
                    </div>
                </div>
            </div>
        `;
    }

    function renderChecklistTab(checklist) {
        const d = state.currentDossier.dossier;
        const isCoordinator = canCoordinate(d);
        const beforeAppointment = isCoordinator && ['assigned','rejected'].includes(d.status);
        const canCoord = isCoordinator && d.status === 'appointment_set';
        const canBO = (state.currentUser.is_backoffice || state.currentUser.is_rcoordinateur) && d.status === 'collected';
        const showScans = !!state.currentDossier.can_view_scans;
        const mandatory = checklist.filter(x => x.is_mandatory);
        const field = canBO ? 'backoffice_checked' : 'coordinator_checked';
        const done = mandatory.filter(x => x[field]).length;
        const scanned = mandatory.filter(x => x.scan_count > 0).length;
        const step = beforeAppointment
            ? `<div class="co-step-banner is-blocked" role="note"><strong>Étape 1 : fixez d’abord le rendez-vous.</strong><span>Les pièces se scannent puis se valident une fois le RDV enregistré.</span><button type="button" class="co-btn co-btn-primary" data-goto-tab="appointment">📅 Prendre rendez-vous</button></div>`
            : canCoord ? `<div class="co-step-banner" role="note"><strong>Étape 2 : pour chaque pièce, 📷 scannez puis ✅ validez.</strong><span>Le mode guidé enchaîne les pièces : scan, vérification, « Valider et suivant ».</span>${checklist.some(x => !x.coordinator_checked) ? '<button type="button" class="co-btn co-btn-primary btn-guided-scan">📷 Scanner les pièces une par une</button>' : ''}</div>` : '';
        return `<div class="co-mobile-checklist">${step}
          <div class="co-checklist-progress" role="status">${showScans ? `📎 ${scanned} / ${mandatory.length} scannées · ` : ''}✅ ${done} / ${mandatory.length} ${canBO ? 'conformes' : 'validées'}</div>
          ${checklist.map(item => {
            const hasScan = item.scan_count > 0;
            const coordEnabled = canCoord && (hasScan || item.coordinator_checked);
            return `<section class="co-piece-card ${hasScan ? 'is-scanned' : ''} ${item.coordinator_checked ? 'is-validated' : ''}" id="piece-${Number(item.id)}">
            <div class="co-piece-heading"><strong>${escapeHtml(item.document_label_fr)}</strong><span class="co-piece-required">${item.is_mandatory ? 'Obligatoire' : 'Facultatif'}</span></div>
            <div class="co-arabic" lang="ar" dir="rtl">${escapeHtml(item.document_label_ar)}</div>
            ${showScans && (!beforeAppointment || (item.scans || []).length) ? renderPieceScans(d, item, canCoord) : ''}
            <div class="co-piece-checks">
             <label class="${canCoord && !hasScan && !item.coordinator_checked ? 'is-waiting' : ''}"><input type="checkbox" class="chk-item" data-id="${Number(item.id)}" data-role="coordinator" ${item.coordinator_checked ? 'checked' : ''} ${coordEnabled ? '' : 'disabled'}><span>${canCoord && !hasScan && !item.coordinator_checked ? 'Valider (après le scan)' : 'Validée par le coordinateur'}</span></label>
             <label><input type="checkbox" class="chk-item" data-id="${Number(item.id)}" data-role="backoffice" ${item.backoffice_checked ? 'checked' : ''} ${canBO ? '' : 'disabled'}><span>Conforme · Back-Office</span></label>
            </div></section>`;
          }).join('')}
            ${checklist.length ? '' : '<p class="co-empty">Checklist indisponible : contactez le responsable avant de transmettre le dossier.</p>'}</div>`;
    }

    function scanUrl(d, scan, download) {
        return OC.generateUrl(`apps/coordination/api/dossiers/${Number(d.id)}/scans/${Number(scan.id)}/file`) + (download ? '?download=1' : '');
    }

    function formatSize(bytes) {
        return bytes >= 1048576 ? (bytes / 1048576).toFixed(1).replace('.', ',') + ' Mo' : Math.max(1, Math.round(bytes / 1024)) + ' Ko';
    }

    /** Scans d'une pièce : liste consultable/téléchargeable et, pour le coordinateur, prise de vue. */
    function renderPieceScans(d, item, canScan) {
        const scans = item.scans || [];
        const itemId = Number(item.id);
        const isRcoord = state.currentUser.is_rcoordinateur;
        const session = scanSession && scanSession.itemId === itemId ? scanSession : null;
        const rows = scans.map((sc, i) => {
            const canDelete = isRcoord || (canScan && sc.uploaded_by === state.currentUser.uid);
            return `<div class="co-scan-row">
                <div class="co-scan-meta"><strong>📄 Scan ${i + 1}</strong> · ${sc.pages ? sc.pages + ' page(s) · ' : ''}${formatSize(sc.size)}<br><small>${formatDate(sc.uploaded_at)} · ${escapeHtml(userLabel(sc.uploaded_by))}</small></div>
                <div class="co-scan-links">
                    <a class="co-btn co-btn-secondary co-btn-sm" href="${scanUrl(d, sc, false)}" target="_blank" rel="noopener">👁️ Voir</a>
                    <a class="co-btn co-btn-secondary co-btn-sm" href="${scanUrl(d, sc, true)}" download>⬇️ Télécharger</a>
                    ${canDelete ? `<button type="button" class="co-btn co-btn-danger co-btn-sm btn-scan-delete" data-scan="${Number(sc.id)}" aria-label="Supprimer le scan ${i + 1}">🗑️</button>` : ''}
                </div></div>`;
        }).join('');
        const empty = !scans.length && !session ? `<div class="co-scan-empty">${canScan ? 'À scanner : photographiez la pièce (recto, puis verso si besoin).' : 'Aucun scan transmis.'}</div>` : '';
        // Appareil photo natif : secours si le scanner en direct n'est pas disponible (caméra refusée, ancien navigateur).
        const fallbackInput = `<input type="file" accept="image/*" capture="environment" class="co-file-input scan-photo-input" data-item="${itemId}" tabindex="-1" aria-hidden="true">`;
        let actions = '';
        if (canScan && session) {
            actions = `<div class="co-scan-session" aria-label="Scan en cours">
                <div class="co-scan-thumbs">${session.pages.map((p, i) => `<figure><img src="${p.preview}" alt="Page ${i + 1}"><figcaption>Page ${i + 1}</figcaption><button type="button" class="btn-scan-page-del" data-index="${i}" aria-label="Retirer la page ${i + 1}">×</button></figure>`).join('')}
                    ${session.busy ? '<div class="co-scan-busy" role="status">⏳ Traitement…</div>' : ''}</div>
                <label class="co-scan-option"><input type="checkbox" class="scan-doc-mode" ${state.scanDocMode ? 'checked' : ''}> Mode document (noir et blanc contrasté) pour les pages suivantes</label>
                <div class="co-scan-actions">
                    ${session.pages.length < 10 ? `<button type="button" class="co-btn co-btn-secondary btn-live-page" data-item="${itemId}">➕ Page suivante</button>${fallbackInput}` : '<span class="co-scan-limit">10 pages maximum</span>'}
                    <button type="button" class="co-btn co-btn-success btn-scan-send" data-item="${itemId}" ${session.pages.length && !session.busy ? '' : 'disabled'}>✅ Envoyer le PDF (${session.pages.length} page${session.pages.length > 1 ? 's' : ''})</button>
                    <button type="button" class="co-btn co-btn-secondary btn-scan-cancel">Annuler</button>
                </div></div>`;
        } else if (canScan) {
            actions = `<div class="co-scan-actions">
                <button type="button" class="co-btn co-btn-primary btn-live-scan" data-item="${itemId}">📷 Scanner</button>${fallbackInput}
                <label class="co-btn co-btn-secondary co-file-btn">🖼️ Galerie ou PDF<input type="file" accept="image/*,application/pdf,.pdf" multiple class="co-file-input scan-pick-input" data-item="${itemId}"></label>
            </div>`;
        }
        return `<div class="co-scans"><div class="co-scans-head">📎 Scans PDF${scans.length ? ` (${scans.length})` : ''}</div>${rows}${empty}${actions}</div>`;
    }

    function renderAppointmentTab(d) {
        const canEditApp = canCoordinate(d) && ['assigned','appointment_set','rejected'].includes(d.status);
        const custom = (d.custom_data && typeof d.custom_data === 'object') ? d.custom_data : {};
        const phone2 = custom.phone_secondary || '';
        const coordNote = custom.coordinator_note || '';

        return `
            <div>
                <div style="background:#ebf8ff; border:1px solid #bee3f8; border-radius:8px; padding:0.9rem 1.2rem; margin-bottom:1.2rem;">
                    <strong>Coordonnées Client :</strong> ${escapeHtml(d.client_name)} &nbsp;|&nbsp; 
                    ${d.phone ? `<a href="tel:${escapeHtml(d.phone)}" style="color:#0082c9; font-weight:bold; text-decoration:none;">📞 Appeler : ${escapeHtml(d.phone)}</a>` : 'Aucun téléphone'}
                    ${phone2 ? ` &nbsp;|&nbsp; <a href="tel:${escapeHtml(phone2)}" style="color:#0f766e; font-weight:bold; text-decoration:none;">📱 Autre : ${escapeHtml(phone2)}</a>` : ''}
                    <div style="margin-top:0.3rem; font-size:0.9rem; color:#4a5568;">📍 <strong>Adresse :</strong> ${escapeHtml(d.address || '')} (${escapeHtml(d.commune || '')}, ${escapeHtml(d.wilaya || '')})</div>
                    ${coordNote ? `<div style="margin-top:0.3rem; font-size:0.9rem; color:#975a16;">📝 <strong>Note :</strong> ${escapeHtml(coordNote)}</div>` : ''}
                </div>

                <div class="co-grid-2">
                    <div class="co-form-group">
                        <label>Date du rendez-vous chez le client *</label>
                        <input type="date" class="co-input" id="modal-app-date" value="${escapeHtml(d.appointment_date || '')}" required ${canEditApp ? '' : 'disabled'}>
                    </div>
                    <div class="co-form-group">
                        <label>Heure du rendez-vous</label>
                        <input type="time" class="co-input" id="modal-app-time" value="${escapeHtml(d.appointment_time || '')}" required ${canEditApp ? '' : 'disabled'}>
                    </div>
                </div>
                <div class="co-form-group">
                    <label>Remarques / Précisions sur le lieu de rendez-vous</label>
                    <textarea class="co-input" id="modal-app-notes" rows="3" placeholder="Ex: Client présent l'après-midi, disponible au domicile..." ${canEditApp ? '' : 'disabled'}>${escapeHtml(d.appointment_notes || '')}</textarea>
                </div>
                ${canEditApp ? '<button class="co-btn co-btn-primary" id="btn-save-appointment">💾 Enregistrer le Rendez-vous</button>' : ''}
            </div>
        `;
    }

    function renderInfoTab(d) {
        const canEditInfo = state.currentUser.is_rcoordinateur;

        const bankOptions = (state.banks || []).slice();
        if (d.bank && bankOptions.indexOf(d.bank) === -1) {
            bankOptions.unshift(d.bank);
        }

        const custom = (d.custom_data && typeof d.custom_data === 'object') ? d.custom_data : {};
        const coordNote = custom.coordinator_note || '';
        const phone2 = custom.phone_secondary || '';
        const canCoordFields = canCoordinate(d);

        return `
            <div>
                <div class="co-grid-2">
                    <div class="co-form-group">
                        <label>Nom du Client</label>
                        <input type="text" class="co-input" id="edit-client-name" value="${escapeHtml(d.client_name)}" ${canEditInfo ? '' : 'disabled'}>
                    </div>
                    <div class="co-form-group">
                        <label>Numéro de Téléphone</label>
                        <input type="text" class="co-input" id="edit-phone" value="${escapeHtml(d.phone || '')}" ${canEditInfo ? '' : 'disabled'}>
                    </div>
                </div>
                <div class="co-form-group">
                    <label>Adresse Complète</label>
                    <textarea class="co-input" id="edit-address" rows="3" placeholder="Cité, Rue, N° de porte..." ${canEditInfo ? '' : 'disabled'}>${escapeHtml(d.address || '')}</textarea>
                </div>
                <div class="co-grid-2">
                    <div class="co-form-group">
                        <label>Wilaya (Algérie)</label>
                        <select class="co-input" id="edit-wilaya" ${canEditInfo ? '' : 'disabled'}>
                            ${state.wilayas.map(w => {
                                const val = w.full || w.name;
                                return `<option value="${escapeHtml(val)}" ${d.wilaya === val ? 'selected' : ''}>${escapeHtml(val)}</option>`;
                            }).join('')}
                        </select>
                    </div>
                    <div class="co-form-group">
                        <label>Commune</label>
                        ${communeFieldHtml('edit-commune', d.wilaya, d.commune, !canEditInfo)}
                    </div>
                </div>
                <div class="co-grid-2">
                    <div class="co-form-group">
                        <label>Banque</label>
                        <select class="co-input" id="edit-bank" ${canEditInfo ? '' : 'disabled'}>
                            <option value="">— Non renseignée —</option>
                            ${bankOptions.map(b => `<option value="${escapeHtml(b)}" ${d.bank === b ? 'selected' : ''}>${escapeHtml(b)}</option>`).join('')}
                        </select>
                    </div>
                    <div class="co-form-group">
                        <label>Numéro de dossier</label>
                        <input type="text" class="co-input" id="edit-case-number" value="${escapeHtml(d.case_number)}" ${canEditInfo ? '' : 'disabled'}>
                    </div>
                </div>

                <h4 style="margin-top:1.5rem; margin-bottom:0.75rem;">📝 Note & Contact (Coordinateur)</h4>
                <div class="co-form-group">
                    <label>Autre N° de téléphone</label>
                    <input type="text" class="co-input" id="edit-phone2" value="${escapeHtml(phone2)}" placeholder="2e numéro (famille, travail...)" ${canCoordFields ? '' : 'disabled'}>
                </div>
                <div class="co-form-group">
                    <label>Note du coordinateur</label>
                    <textarea class="co-input" id="edit-coord-note" rows="3" placeholder="Ex: client absent, rappeler le soir..." ${canCoordFields ? '' : 'disabled'}>${escapeHtml(coordNote)}</textarea>
                </div>
                ${canCoordFields ? '<button class="co-btn co-btn-success" id="btn-save-coord-fields">💾 Enregistrer la note</button>' : ''}

                <h4 style="margin-top:1.5rem; margin-bottom:0.75rem;">👥 Attribution du Coordinateur</h4>
                <div class="co-form-group">
                    <label>Coordinateur en charge (Groupe csm_coordinateur)</label>
                    <select class="co-input" id="edit-coord" ${canEditInfo ? '' : 'disabled'}>
                        <option value="">-- Aucun --</option>
                        ${state.coordinators.map(u => `<option value="${escapeHtml(u.uid)}" ${d.assigned_coordinator === u.uid ? 'selected' : ''}>${escapeHtml(u.display_name)}</option>`).join('')}
                    </select>
                </div>

                ${canEditInfo ? `
                    <div style="margin-top:1rem; display:flex; gap:0.5rem;">
                        <button class="co-btn co-btn-primary" id="btn-save-dossier-info">💾 Enregistrer les modifications</button>
                    </div>
                ` : ''}
            </div>
        `;
    }

    function renderHistoryTab(history) {
        if (history.length === 0) {
            return `<p style="color:#718096; text-align:center;">Aucun événement enregistré.</p>`;
        }
        return `
            <div class="co-timeline">
                ${history.map(h => `
                    <div class="co-timeline-item">
                        <div class="co-timeline-dot"></div>
                        <div class="co-timeline-content">
                            <div style="display:flex; justify-content:space-between; font-size:0.8rem; color:#718096;">
                                <span>👤 Par <strong>${escapeHtml(userLabel(h.user_id))}</strong></span>
                                <span>${formatDate(h.created_at)}</span>
                            </div>
                            <div style="font-weight:600; margin-top:0.25rem;">${escapeHtml(h.details || h.action)}</div>
                        </div>
                    </div>
                `).join('')}
            </div>
        `;
    }

    function renderNextStep(d) {
        if (!canCoordinate(d)) return '';
        const beforeAppointment = ['assigned','rejected'].includes(d.status);
        if (state.activeModalTab === 'appointment' && d.status === 'appointment_set') {
            return `<button class="co-btn co-btn-primary" data-goto-tab="checklist">Suivant&nbsp;: scanner les pièces →</button>`;
        }
        if (state.activeModalTab === 'checklist' && beforeAppointment) {
            return `<button class="co-btn co-btn-primary" data-goto-tab="appointment">← Prendre rendez-vous</button>`;
        }
        if (state.activeModalTab === 'checklist' && d.status === 'appointment_set') {
            const list = state.currentDossier.checklist || [];
            const ready = list.length > 0 && list.every(x => !x.is_mandatory || (x.coordinator_checked && x.scan_count > 0));
            return `<button class="co-btn co-btn-success" id="btn-action-collected" data-id="${d.id}" ${ready ? '' : 'disabled'} title="Toutes les pièces obligatoires doivent être scannées et validées">📤 Transmettre au Back-Office</button>`;
        }
        return '';
    }

    function renderTransitionActions(d) {
        const id = d.id;
        let html = '';

        const canBO = state.currentUser.is_backoffice || state.currentUser.is_rcoordinateur;

        // Le passage "dossier récupéré" se fait depuis l'onglet RDV (bouton Suivant),
        // après que le rendez-vous a été enregistré.

        // Si dossier collecté: le backoffice peut valider ou rejeter
        if (canBO && d.status === 'collected') {
            html += `<button class="co-btn co-btn-success" id="btn-action-validate-bo" data-id="${id}" ${state.currentDossier.checklist.length && state.currentDossier.checklist.every(x => !x.is_mandatory || x.backoffice_checked) ? '' : 'disabled'}>✅ Valider le dossier (Conforme)</button>`;
            html += `<button class="co-btn co-btn-danger" id="btn-action-reject-bo" data-id="${id}">❌ Rejeter (Pièces non conformes)</button>`;
        }

        return html;
    }

    function renderNewDossierModal() {
        return `
            <div class="co-modal-backdrop" id="modal-backdrop">
                <div class="co-modal" style="max-width: 650px;">
                    <div class="co-modal-header">
                        <h2>➕ Créer un Nouveau Dossier</h2>
                        <button class="co-modal-close" id="modal-close">&times;</button>
                    </div>
                    <div class="co-modal-body"><div class="co-feedback" role="status" aria-live="polite" hidden></div>
                        <div class="co-grid-2">
                            <div class="co-form-group">
                                <label>Nom et Prénom du Client *</label>
                                <input type="text" class="co-input" id="new-client-name" placeholder="Ex: Benali Mohamed" required>
                            </div>
                            <div class="co-form-group">
                                <label>Numéro de Dossier</label>
                                <input type="text" class="co-input" id="new-case-number" placeholder="Laisser vide pour auto">
                            </div>
                        </div>

                        <div class="co-grid-2">
                            <div class="co-form-group">
                                <label>Téléphone</label>
                                <input type="text" class="co-input" id="new-phone" placeholder="0550 / 0660 / 0770 ...">
                            </div>
                            <div class="co-form-group">
                                <label>Banque</label>
                                <select class="co-input" id="new-bank">
                                    <option value="">-- Sélectionner une banque --</option>
                                    ${(state.banks || []).map(b => `<option value="${escapeHtml(b)}">${escapeHtml(b)}</option>`).join('')}
                                </select>
                            </div>
                        </div>

                        <div class="co-grid-2">
                            <div class="co-form-group">
                                <label>Wilaya (Algérie)</label>
                                <select class="co-input" id="new-wilaya">
                                    <option value="">-- Sélectionner parmi les 58 wilayas --</option>
                                    ${state.wilayas.map(w => {
                                        const val = w.full || w.name;
                                        return `<option value="${escapeHtml(val)}">${escapeHtml(val)}</option>`;
                                    }).join('')}
                                </select>
                            </div>
                            <div class="co-form-group">
                                <label>Commune</label>
                                ${communeFieldHtml('new-commune', '', '', false)}
                            </div>
                        </div>

                        <div class="co-form-group">
                            <label>Adresse détaillée</label>
                            <textarea class="co-input" id="new-address" rows="3" placeholder="Cité, Rue, N° de porte..."></textarea>
                        </div>
                    </div>
                    <div class="co-modal-footer">
                        <button class="co-btn co-btn-secondary" id="modal-close-btn">Annuler</button>
                        <button class="co-btn co-btn-success" id="btn-submit-new-dossier">Enregistrer le dossier</button>
                    </div>
                </div>
            </div>
        `;
    }

    function renderImportModal() {
        return `
            <div class="co-modal-backdrop" id="modal-backdrop">
                <div class="co-modal" style="max-width: 600px;">
                    <div class="co-modal-header">
                        <h2>📥 Importer la liste matinale (.xlsx)</h2>
                        <button class="co-modal-close" id="modal-close">&times;</button>
                    </div>
                    <div class="co-modal-body"><div class="co-feedback" role="status" aria-live="polite" hidden></div>
                        <p style="color:#4a5568; font-size:0.92rem;">
                            Sélectionnez votre fichier Excel (<strong>.xlsx</strong>). Les colonnes seront reconnues automatiquement :
                        </p>
                        <ul style="color:#718096; font-size:0.88rem; line-height:1.6;">
                            <li><strong>Nom du client</strong></li>
                            <li><strong>Numéro de dossier</strong></li>
                            <li><strong>Téléphone</strong></li>
                            <li><strong>Adresse / Wilaya / Commune</strong></li>
                            <li><strong>Banque</strong></li>
                        </ul>

                        <div style="border: 2px dashed #cbd5e1; border-radius:12px; padding:2rem; text-align:center; background:#f8fafc; margin-top:1.5rem;">
                            <input type="file" id="xlsx-file-input" accept=".xlsx" style="display:none;">
                            <label for="xlsx-file-input" class="co-btn co-btn-primary" style="cursor:pointer;">
                                📁 Choisir le fichier Excel (.xlsx)
                            </label>
                            <div id="selected-file-name" style="margin-top:0.75rem; font-weight:600; color:#0082c9;"></div>
                        </div>
                    </div>
                    <div class="co-modal-footer">
                        <button class="co-btn co-btn-secondary" id="modal-close-btn">Annuler</button>
                        <button class="co-btn co-btn-success" id="btn-start-import" disabled>Lancer l'importation</button>
                    </div>
                </div>
            </div>
        `;
    }

    function renderSettingsModal() {
        return `
            <div class="co-modal-backdrop" id="modal-backdrop">
                <div class="co-modal" style="max-width: 780px;">
                    <div class="co-modal-header">
                        <h2>⚙️ Paramètres de l'application</h2>
                        <button class="co-modal-close" id="modal-close">&times;</button>
                    </div>
                    <div class="co-modal-body"><div class="co-feedback" role="status" aria-live="polite" hidden></div>
                        <h3 style="margin-top:0; font-size:1.05rem;">📄 Pièces justificatives</h3>
                        <p style="color:#4a5568; font-size:0.9rem;">
                            Liste des documents créés automatiquement pour chaque nouveau dossier :
                        </p>
                        <div id="settings-templates-list">
                            ${state.documentTemplates.map((t, i) => `
                                <div style="display:flex; gap:0.5rem; align-items:center; margin-bottom:0.5rem;" class="template-row" data-index="${i}">
                                    <input type="text" class="co-input" style="flex:1;" value="${escapeHtml(t.label_fr)}" placeholder="Nom FR">
                                    <input type="text" class="co-input" style="flex:1; direction:rtl;" value="${escapeHtml(t.label_ar)}" placeholder="الاسم بالعربية">
                                    <button class="co-btn co-btn-danger co-btn-sm btn-del-tpl">🗑️</button>
                                </div>
                            `).join('')}
                        </div>
                        <button class="co-btn co-btn-secondary co-btn-sm" id="btn-add-tpl-row" style="margin-top:0.5rem;">➕ Ajouter une pièce</button>

                        <hr style="margin:1.75rem 0 1.5rem; border:none; border-top:1px solid #e2e8f0;">

                        <div style="display:flex; justify-content:space-between; align-items:center; gap:1rem;">
                            <h3 style="margin:0; font-size:1.05rem;">🏦 Banques</h3>
                            <button class="co-btn co-btn-secondary co-btn-sm" id="btn-reset-banks" title="Rétablir la liste officielle des banques agréées en Algérie">↺ Liste officielle</button>
                        </div>
                        <p style="color:#4a5568; font-size:0.9rem;">
                            Liste proposée dans la création de dossier et la fiche client. Modifiez un nom directement dans le champ.
                        </p>
                        <div id="settings-banks-list">
                            ${(state.banks || []).map((b, i) => `
                                <div style="display:flex; gap:0.5rem; align-items:center; margin-bottom:0.5rem;" class="bank-row" data-index="${i}">
                                    <input type="text" class="co-input" style="flex:1;" value="${escapeHtml(b)}" placeholder="Nom de la banque">
                                    <button class="co-btn co-btn-danger co-btn-sm btn-del-bank">🗑️</button>
                                </div>
                            `).join('')}
                        </div>
                        <button class="co-btn co-btn-secondary co-btn-sm" id="btn-add-bank-row" style="margin-top:0.5rem;">➕ Ajouter une banque</button>
                    </div>
                    <div class="co-modal-footer">
                        <button class="co-btn co-btn-secondary" id="modal-close-btn">Fermer</button>
                        <button class="co-btn co-btn-primary" id="btn-save-settings">💾 Enregistrer</button>
                    </div>
                </div>
            </div>
        `;
    }

    let wilayaDocClickBound = false;

    function bindWilayaFilter() {
        const toggle = document.getElementById('wilaya-dd-toggle');
        const panel = document.getElementById('wilaya-dd-panel');
        if (!toggle || !panel) {
            return;
        }

        const search = document.getElementById('wilaya-dd-search');
        const list = document.getElementById('wilaya-dd-list');
        const upBtn = document.getElementById('wilaya-dd-up');
        const downBtn = document.getElementById('wilaya-dd-down');

        const setOpen = (open) => {
            panel.classList.toggle('open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        };

        toggle.addEventListener('click', (e) => {
            e.stopPropagation();
            const open = !panel.classList.contains('open');
            setOpen(open);
            if (!open) {
                return;
            }
            search.value = '';
            list.querySelectorAll('.co-dd-option').forEach(o => { o.style.display = ''; });
            const selected = list.querySelector('.co-dd-option.selected');
            if (selected) {
                selected.scrollIntoView({ block: 'nearest' });
            }
            // Sur téléphone, ne pas ouvrir le clavier virtuel : la liste reste visible
            if (window.matchMedia('(min-width: 769px)').matches) {
                setTimeout(() => search.focus(), 10);
            }
        });

        panel.addEventListener('click', (e) => e.stopPropagation());

        if (!wilayaDocClickBound) {
            document.addEventListener('click', () => {
                document.querySelectorAll('.co-dd-panel.open').forEach(p => p.classList.remove('open'));
                document.querySelectorAll('.co-dd-toggle[aria-expanded="true"]').forEach(t => t.setAttribute('aria-expanded', 'false'));
            });
            wilayaDocClickBound = true;
        }

        search.addEventListener('input', () => {
            const q = search.value.trim().toLowerCase();
            list.querySelectorAll('.co-dd-option').forEach(o => {
                const hit = !q || o.textContent.toLowerCase().indexOf(q) !== -1;
                o.style.display = hit ? '' : 'none';
            });
            list.scrollTop = 0;
        });

        search.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                setOpen(false);
                toggle.focus();
            }
        });

        const step = () => Math.max(120, Math.round(list.clientHeight * 0.8));
        upBtn.addEventListener('click', () => list.scrollBy({ top: -step(), behavior: 'smooth' }));
        downBtn.addEventListener('click', () => list.scrollBy({ top: step(), behavior: 'smooth' }));

        list.querySelectorAll('.co-dd-option').forEach(opt => {
            opt.addEventListener('click', async () => {
                state.filterWilaya = opt.getAttribute('data-value');
                await loadDossiers();
                render();
            });
        });
    }

    function bindEvents() {
        document.querySelectorAll('.co-modal input:not(.chk-item), .co-modal textarea, .co-modal select').forEach(el => el.addEventListener('input',() => { state.dirty = true; }));
        document.getElementById('btn-load-more')?.addEventListener('click',async () => { const y = window.scrollY; await loadDossiers(true); render(); window.scrollTo(0,y); });
        document.querySelectorAll('.co-tab[data-tab]').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                state.activeTab = e.currentTarget.getAttribute('data-tab');
                await loadDossiers();
                render();
            });
        });

        const searchInput = document.getElementById('search-input');
        if (searchInput) {
            let timeout = null;
            searchInput.addEventListener('input', (e) => {
                clearTimeout(timeout);
                timeout = setTimeout(async () => {
                    state.searchQuery = e.target.value;
                    await loadDossiers();
                    render();
                }, 400);
            });
        }

        bindWilayaFilter();

        const refreshBtn = document.getElementById('btn-refresh');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', async () => {
                state.apiError = '';
                await loadDossiers();
                render();
            });
        }

        // Clic sur numéro ou bouton ouvrir
        document.querySelectorAll('.open-detail').forEach(el => {
            el.addEventListener('click', (e) => {
                const id = parseInt(e.currentTarget.getAttribute('data-id'), 10);
                openDossierDetail(id);
            });
        });

        // Clic sur bouton supprimer ligne (réservé admin/responsable)
        document.querySelectorAll('.btn-delete-row').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const id = parseInt(e.currentTarget.getAttribute('data-id'), 10);
                deleteDossier(id);
            });
        });

        const selectAll = document.getElementById('select-all-checkbox');
        if (selectAll) {
            selectAll.addEventListener('change', (e) => {
                if (e.target.checked) {
                    state.selectedDossierIds = new Set(state.dossiers.map(d => d.id));
                } else {
                    state.selectedDossierIds.clear();
                }
                render();
            });
        }

        document.querySelectorAll('.row-checkbox').forEach(cb => {
            cb.addEventListener('change', (e) => {
                const id = parseInt(e.target.getAttribute('data-id'), 10);
                if (e.target.checked) {
                    state.selectedDossierIds.add(id);
                } else {
                    state.selectedDossierIds.delete(id);
                }
                render();
            });
        });

        // Batch Assign
        const batchBtn = document.getElementById('btn-batch-assign');
        if (batchBtn) {
            batchBtn.addEventListener('click', async () => {
                const coord = document.getElementById('batch-coord-select').value;
                if (!coord) {
                    alert('Veuillez sélectionner un coordinateur.');
                    return;
                }

                if (state.selectedDossierIds.size === 0 && state.filterWilaya === 'all') {
                    alert('Veuillez cocher des dossiers ou filtrer par Wilaya.');
                    return;
                }

                const idsArray = Array.from(state.selectedDossierIds).map(Number);
                const payload = {
                    ids: idsArray,
                    wilaya: idsArray.length === 0 ? state.filterWilaya : null,
                    assigned_coordinator: coord
                };

                const res = await apiRequest('api/dossiers/batch-assign', 'POST', payload);
                if (res.success && res.updated_count > 0) {
                    alert(`Attribution effectuée : ${res.updated_count} dossier(s) attribué(s).` + (res.skipped_count ? `\n${res.skipped_count} dossier(s) déjà transmis au Back-Office ou validés : attribution inchangée.` : ''));
                    state.selectedDossierIds.clear();
                    await loadDossiers();
                    render();
                } else {
                    alert('Attribution non effectuée : ' + (res.error || (res.skipped_count ? 'les dossiers visés sont déjà transmis au Back-Office ou validés.' : 'aucun dossier mis à jour')));
                    await loadDossiers();
                    render();
                }
            });
        }

        const btnNew = document.getElementById('btn-new-dossier');
        if (btnNew) btnNew.addEventListener('click', () => { state.modal = 'new'; render(); });

        const btnImport = document.getElementById('btn-import-modal');
        if (btnImport) btnImport.addEventListener('click', () => { state.modal = 'import'; render(); });

        const btnSettings = document.getElementById('btn-settings-modal');
        if (btnSettings) btnSettings.addEventListener('click', () => { state.modal = 'settings'; render(); });

        const addTplRow = document.getElementById('btn-add-tpl-row');
        if (addTplRow) {
            addTplRow.addEventListener('click', () => {
                const list = document.getElementById('settings-templates-list');
                const row = document.createElement('div');
                row.className = 'template-row';
                row.style.cssText = 'display:flex; gap:0.5rem; align-items:center; margin-bottom:0.5rem;';
                row.innerHTML = '<input type="text" class="co-input" style="flex:1;" placeholder="Nom FR">'
                    + '<input type="text" class="co-input" style="flex:1; direction:rtl;" placeholder="الاسم بالعربية">'
                    + '<button class="co-btn co-btn-danger co-btn-sm">🗑️</button>';
                row.querySelector('button').addEventListener('click', () => row.remove());
                list.appendChild(row);
                row.querySelector('input').focus();
            });
        }

        document.querySelectorAll('.btn-del-tpl').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const row = e.currentTarget.closest('.template-row');
                if (row) row.remove();
            });
        });

        const addBankRow = document.getElementById('btn-add-bank-row');
        if (addBankRow) {
            addBankRow.addEventListener('click', () => {
                const list = document.getElementById('settings-banks-list');
                const row = document.createElement('div');
                row.className = 'bank-row';
                row.style.cssText = 'display:flex; gap:0.5rem; align-items:center; margin-bottom:0.5rem;';
                row.innerHTML = '<input type="text" class="co-input" style="flex:1;" placeholder="Nom de la banque">'
                    + '<button class="co-btn co-btn-danger co-btn-sm">🗑️</button>';
                row.querySelector('button').addEventListener('click', () => row.remove());
                list.appendChild(row);
                row.querySelector('input').focus();
            });
        }

        document.querySelectorAll('.btn-del-bank').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const row = e.currentTarget.closest('.bank-row');
                if (row) row.remove();
            });
        });

        const resetBanksBtn = document.getElementById('btn-reset-banks');
        if (resetBanksBtn) {
            resetBanksBtn.addEventListener('click', async () => {
                if (!confirm('Rétablir la liste officielle des banques agréées en Algérie ? Vos modifications seront perdues.')) {
                    return;
                }
                const res = await apiRequest('api/config/banks', 'POST', { banks: [] });
                if (res.success) {
                    await loadConfig();
                    render();
                } else {
                    alert('Réinitialisation impossible : ' + (res.error || 'erreur inconnue'));
                }
            });
        }

        const saveSettingsBtn = document.getElementById('btn-save-settings');
        if (saveSettingsBtn) {
            saveSettingsBtn.addEventListener('click', async () => {
                const templates = [];
                document.querySelectorAll('#settings-templates-list .template-row').forEach((row) => {
                    const inputs = row.querySelectorAll('input');
                    const labelFr = inputs[0].value.trim();
                    const labelAr = inputs[1].value.trim();
                    if (!labelFr) return;
                    const idx = row.getAttribute('data-index');
                    const existing = idx !== null ? state.documentTemplates[parseInt(idx, 10)] : null;
                    templates.push({
                        code: existing && existing.code ? existing.code : 'doc_' + Math.random().toString(36).slice(2, 8),
                        label_fr: labelFr,
                        label_ar: labelAr,
                        mandatory: existing ? !!existing.mandatory : true
                    });
                });

                const banks = [];
                document.querySelectorAll('#settings-banks-list .bank-row input').forEach((input) => {
                    const name = input.value.trim();
                    if (name && banks.indexOf(name) === -1) banks.push(name);
                });

                const resTpl = await apiRequest('api/config/document-templates', 'POST', { document_templates: templates });
                const resBanks = await apiRequest('api/config/banks', 'POST', { banks: banks });

                if (!resTpl.success || !resBanks.success) {
                    alert('Enregistrement impossible : ' + (resTpl.error || resBanks.error || 'erreur inconnue'));
                    return;
                }

                await loadConfig();
                alert('Paramètres enregistrés.');
                render();
            });
        }

        const closeModal = () => {
            if (scanSession && scanSession.pages.length && !confirm('Abandonner le scan en cours (pages non envoyées) ?')) return;
            if (state.dirty && !confirm('Fermer sans enregistrer vos modifications ?')) return;
            clearScanSession();
            state.dirty = false;
            state.modal = null;
            state.currentDossier = null;
            render();
        };

        const closeBtn = document.getElementById('modal-close');
        const closeBtn2 = document.getElementById('modal-close-btn');
        const backdrop = document.getElementById('modal-backdrop');

        if (closeBtn) closeBtn.addEventListener('click', closeModal);
        if (closeBtn2) closeBtn2.addEventListener('click', closeModal);
        if (backdrop) backdrop.addEventListener('click', (e) => { if (e.target === backdrop) closeModal(); });

        document.querySelectorAll('[data-modal-tab]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                if (scanSession && scanSession.pages.length && !confirm('Abandonner le scan en cours (pages non envoyées) ?')) return;
                if (state.dirty && !confirm('Changer d’onglet sans enregistrer vos modifications ?')) return;
                clearScanSession();
                state.dirty = false;
                state.activeModalTab = e.currentTarget.getAttribute('data-modal-tab');
                render();
            });
        });

        document.querySelectorAll('[data-goto-tab]').forEach(btn => btn.addEventListener('click', (e) => {
            if (scanSession && scanSession.pages.length && !confirm('Abandonner le scan en cours (pages non envoyées) ?')) return;
            if (state.dirty && !confirm('Changer d’onglet sans enregistrer vos modifications ?')) return;
            clearScanSession();
            state.dirty = false;
            state.activeModalTab = e.currentTarget.getAttribute('data-goto-tab');
            render();
            document.querySelector('.co-modal-body')?.scrollTo(0, 0);
        }));

        const submitNew = document.getElementById('btn-submit-new-dossier');
        if (submitNew) {
            submitNew.addEventListener('click', async () => {
                const clientName = document.getElementById('new-client-name').value.trim();
                if (!clientName) {
                    alert('Le nom du client est obligatoire');
                    return;
                }

                const data = {
                    client_name: clientName,
                    case_number: document.getElementById('new-case-number').value.trim(),
                    phone: document.getElementById('new-phone').value.trim(),
                    bank: document.getElementById('new-bank').value.trim(),
                    wilaya: document.getElementById('new-wilaya').value,
                    commune: readCommune('new-commune'),
                    address: document.getElementById('new-address').value.trim(),
                };

                const res = await apiRequest('api/dossiers', 'POST', data);
                if (res.success) {
                    alert('Dossier créé avec succès !');
                    closeModal();
                    await loadDossiers();
                    render();
                } else {
                    alert('Erreur: ' + res.error);
                }
            });
        }

        bindCommune('new-commune', 'new-wilaya');
        bindCommune('edit-commune', 'edit-wilaya');

        const fileInput = document.getElementById('xlsx-file-input');
        const fileNameDiv = document.getElementById('selected-file-name');
        const startImportBtn = document.getElementById('btn-start-import');

        if (fileInput) {
            fileInput.addEventListener('change', () => {
                if (fileInput.files.length > 0) {
                    fileNameDiv.innerText = '📄 Fichier: ' + fileInput.files[0].name;
                    startImportBtn.disabled = false;
                }
            });
        }

        if (startImportBtn) {
            startImportBtn.addEventListener('click', async () => {
                if (!fileInput.files.length) return;
                startImportBtn.disabled = true;
                startImportBtn.innerText = 'Importation en cours...';

                const formData = new FormData();
                formData.append('file', fileInput.files[0]);

                const res = await apiRequest('api/import/xlsx', 'POST', formData, true);
                if (res.success) {
                    if (res.errors?.length) { feedback(`${res.imported_count} dossier(s) importé(s). ${res.errors.length} ligne(s) à corriger :\n` + res.errors.slice(0,20).join('\n')); startImportBtn.disabled = false; startImportBtn.innerText = 'Réessayer un fichier corrigé'; return; }
                    alert(`${res.imported_count} dossier(s) importé(s).`);
                    closeModal();
                    await loadDossiers();
                    render();
                } else {
                    alert('Erreur: ' + (res.error || 'Échec'));
                    startImportBtn.disabled = false;
                    startImportBtn.innerText = 'Lancer l\'importation';
                }
            });
        }

        document.querySelectorAll('.chk-item').forEach(chk => {
            chk.addEventListener('change', async (e) => {
                const itemId = parseInt(e.target.getAttribute('data-id'), 10);
                const role = e.target.getAttribute('data-role');
                const checked = e.target.checked;
                const dossierId = state.currentDossier.dossier.id;

                e.target.disabled = true;
                const res = await apiRequest(`api/dossiers/${dossierId}/checklist`, 'POST', {
                    checklist_item_id: itemId,
                    role: role,
                    checked: checked
                });

                e.target.disabled = false;
                if (!res.success) {
                    e.target.checked = !checked;
                    feedback('Enregistrement impossible : ' + (res.error || 'erreur inconnue'));
                    return;
                }

                const item = (state.currentDossier.checklist || []).find(i => Number(i.id) === itemId);
                if (item) {
                    if (role === 'coordinator') item.coordinator_checked = checked ? 1 : 0;
                    if (role === 'backoffice') item.backoffice_checked = checked ? 1 : 0;
                }
                render();
                feedback(checked ? (role === 'coordinator' ? '✅ Pièce validée.' : '✅ Pièce conforme.') : 'Pièce remise « à vérifier ».', false);
            });
        });

        bindScanEvents();

        const saveAppBtn = document.getElementById('btn-save-appointment');
        if (saveAppBtn) {
            saveAppBtn.addEventListener('click', async () => {
                const dossierId = state.currentDossier.dossier.id;
                const date = document.getElementById('modal-app-date').value;
                const time = document.getElementById('modal-app-time').value;
                const notes = document.getElementById('modal-app-notes').value;

                const res = await apiRequest(`api/dossiers/${dossierId}/appointment`, 'POST', {
                    appointment_date: date,
                    appointment_time: time,
                    appointment_notes: notes
                });

                if (res.success) {
                    state.dirty = false;
                    state.activeModalTab = 'checklist';
                    await openDossierDetail(dossierId);
                    document.querySelector('.co-modal-body')?.scrollTo(0, 0);
                    feedback('📅 Rendez-vous enregistré. Étape 2 : scannez puis validez chaque pièce.', false);
                    loadDossiers().then(() => { if (!state.modal) render(); });
                } else {
                    feedback('Rendez-vous non enregistré : ' + (res.error || 'erreur inconnue'));
                }
            });
        }

        // Clic sur la date / l'heure : ouvre directement le calendrier ou la roue horaire
        ['modal-app-date', 'modal-app-time'].forEach(id => {
            const el = document.getElementById(id);
            if (el && typeof el.showPicker === 'function') {
                el.addEventListener('click', () => {
                    try { el.showPicker(); } catch (e) { /* déjà ouvert ou non autorisé */ }
                });
            }
        });

        const saveInfoBtn = document.getElementById('btn-save-dossier-info');
        if (saveInfoBtn) {
            saveInfoBtn.addEventListener('click', async () => {
                const dossierId = state.currentDossier.dossier.id;
                const data = {
                    client_name: document.getElementById('edit-client-name').value.trim(),
                    phone: document.getElementById('edit-phone').value.trim(),
                    address: document.getElementById('edit-address').value.trim(),
                    wilaya: document.getElementById('edit-wilaya').value,
                    commune: readCommune('edit-commune'),
                    bank: document.getElementById('edit-bank').value.trim(),
                    case_number: document.getElementById('edit-case-number').value.trim(),
                    revision: state.currentDossier.dossier.revision,
                };

                const updated = await apiRequest(`api/dossiers/${dossierId}/update`, 'POST', data);
                if (!updated.success) { feedback(updated.error || 'Enregistrement impossible.'); return; }

                const coord = document.getElementById('edit-coord').value;
                const assigned = await apiRequest(`api/dossiers/${dossierId}/assign`, 'POST', {
                    assigned_coordinator: coord || null
                });

                if (!assigned.success) { feedback('Informations enregistrées, mais attribution refusée : ' + assigned.error); return; }
                feedback('Modifications enregistrées.',false);
                await openDossierDetail(dossierId);
                await loadDossiers();
            });
        }

        const saveCoordBtn = document.getElementById('btn-save-coord-fields');
        if (saveCoordBtn) {
            saveCoordBtn.addEventListener('click', async () => {
                const dossierId = state.currentDossier.dossier.id;
                const res = await apiRequest(`api/dossiers/${dossierId}/coordinator-fields`, 'POST', {
                    coordinator_note: document.getElementById('edit-coord-note').value.trim(),
                    phone_secondary: document.getElementById('edit-phone2').value.trim()
                });

                if (res.success) {
                    alert('Note du coordinateur enregistrée.');
                    await openDossierDetail(dossierId);
                } else {
                    alert('Erreur: ' + (res.error || 'enregistrement impossible'));
                }
            });
        }

        // Bouton supprimer dans la modale
        const modalDelBtn = document.getElementById('btn-modal-delete-dossier');
        if (modalDelBtn) {
            modalDelBtn.addEventListener('click', (e) => {
                const id = parseInt(e.currentTarget.getAttribute('data-id'), 10);
                deleteDossier(id);
            });
        }

        const btnCollected = document.getElementById('btn-action-collected');
        if (btnCollected) {
            btnCollected.addEventListener('click', async (e) => {
                const id = e.target.getAttribute('data-id');
                if (confirm('Confirmez-vous avoir récupéré le dossier physique chez le client et l\'envoyer au Back-Office pour contrôle ?')) {
                    await saveStatus(id,'collected','Dossier physique récupéré chez le client par le coordinateur');
                }
            });
        }

        const btnValidateBO = document.getElementById('btn-action-validate-bo');
        if (btnValidateBO) {
            btnValidateBO.addEventListener('click', async (e) => {
                const id = e.target.getAttribute('data-id');
                if (confirm('Confirmer que toutes les pièces sont conformes et valider le dossier ?')) {
                    await saveStatus(id,'validated','Dossier approuvé et validé conforme par le Back-Office');
                }
            });
        }

        const btnRejectBO = document.getElementById('btn-action-reject-bo');
        if (btnRejectBO) {
            btnRejectBO.addEventListener('click', async (e) => {
                const id = e.target.getAttribute('data-id');
                const motif = prompt('Veuillez indiquer le motif du rejet / pièces manquantes :');
                if (motif) {
                    await saveStatus(id,'rejected',motif);
                }
            });
        }
    }

    function clearScanSession() {
        if (scanSession) scanSession.pages.forEach(p => URL.revokeObjectURL(p.preview));
        scanSession = null;
    }

    function scanFileName(itemId) {
        const d = state.currentDossier.dossier;
        const item = (state.currentDossier.checklist || []).find(i => Number(i.id) === itemId);
        return `${d.case_number || 'dossier-' + d.id}_${(item && item.document_code) || 'piece'}.pdf`.replace(/[^A-Za-z0-9._-]+/g, '_');
    }

    async function uploadScan(itemId, blob, name) {
        if (blob.size > state.scanMaxBytes) { feedback(`Scan trop volumineux (${formatSize(blob.size)}). Maximum : ${formatSize(state.scanMaxBytes)}.`); return false; }
        const dossierId = state.currentDossier.dossier.id;
        const form = new FormData();
        form.append('file', blob, name);
        feedback('⏳ Envoi du scan en cours… gardez l’application ouverte.', false);
        document.querySelectorAll('.btn-scan-send').forEach(b => { b.disabled = true; });
        document.querySelectorAll('.co-scans .co-file-btn').forEach(b => b.classList.add('is-disabled'));
        const res = await apiRequest(`api/dossiers/${dossierId}/checklist/${itemId}/scans`, 'POST', form, true, 180000);
        if (!res.success) {
            render();
            feedback('Scan non enregistré : ' + (res.error || 'erreur inconnue') + (scanSession ? ' Les pages restent prêtes : réessayez.' : ''));
            return false;
        }
        clearScanSession();
        await openDossierDetail(dossierId);
        const card = document.getElementById('piece-' + itemId);
        if (card) { card.classList.add('co-piece-scan-hint'); card.querySelector('.co-piece-checks')?.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
        feedback('📎 Scan enregistré. Cochez « Validée par le coordinateur » pour valider la pièce.', false);
        return true;
    }

    async function addScanPhotos(itemId, files) {
        if (!window.CoordinationScan) { feedback('Module de scan non chargé : rechargez la page.'); return; }
        if (scanSession && scanSession.itemId !== itemId) {
            if (scanSession.pages.length && !confirm('Un scan d’une autre pièce n’est pas envoyé. L’abandonner ?')) return;
            clearScanSession();
        }
        if (!scanSession) scanSession = { itemId, pages: [], busy: false };
        const session = scanSession;
        session.busy = true; render();
        let error = '';
        try {
            for (const file of files) {
                if (session.pages.length >= 10) { error = '10 pages maximum par scan : envoyez ce PDF puis faites un second scan.'; break; }
                session.pages.push(await window.CoordinationScan.photoToPage(file, state.scanDocMode));
            }
        } catch (err) {
            console.error('Scan error:', err);
            error = 'Photo illisible ou mémoire insuffisante : reprenez la photo.';
        } finally {
            session.busy = false;
            if (scanSession === session && !session.pages.length) clearScanSession();
            render();
            if (error) feedback(error);
        }
    }

    function openLiveScan(itemId) {
        const fallback = () => document.querySelector(`#piece-${itemId} .scan-photo-input`)?.click();
        if (!window.CoordinationDocScan || !window.CoordinationDocScan.supported()) { fallback(); return; }
        if (scanSession && scanSession.itemId !== itemId) {
            if (scanSession.pages.length && !confirm('Un scan d’une autre pièce n’est pas envoyé. L’abandonner ?')) return;
            clearScanSession();
        }
        const item = (state.currentDossier.checklist || []).find(i => Number(i.id) === itemId);
        window.CoordinationDocScan.open({
            title: item ? item.document_label_fr : 'Pièce',
            documentMode: state.scanDocMode,
            maxPages: 10 - (scanSession ? scanSession.pages.length : 0),
            onDocMode: (on) => { state.scanDocMode = on; },
            onPage: async (canvas, documentMode) => {
                const page = await window.CoordinationScan.canvasToPage(canvas, documentMode);
                if (!scanSession || scanSession.itemId !== itemId) { clearScanSession(); scanSession = { itemId, pages: [], busy: false }; }
                scanSession.pages.push(page);
            },
            onFallback: fallback,
            onClose: (count) => {
                render();
                if (!count) return;
                document.querySelector(`#piece-${itemId} .co-scan-session`)?.scrollIntoView({ block: 'nearest' });
                feedback(`${count} page${count > 1 ? 's' : ''} prête${count > 1 ? 's' : ''}. Vérifiez les miniatures puis « ✅ Envoyer le PDF ».`, false);
            },
        });
    }

    /** Petit constructeur DOM : les libellés viennent de la base, jamais injectés en HTML. */
    function h(tag, attrs, ...children) {
        const e = document.createElement(tag);
        Object.entries(attrs || {}).forEach(([k, v]) => { if (k === 'class') e.className = v; else if (k.startsWith('on')) e.addEventListener(k.slice(2), v); else if (v !== false && v != null) e.setAttribute(k, v === true ? '' : v); });
        children.flat().forEach(c => { if (c != null) e.append(c instanceof Node ? c : document.createTextNode(String(c))); });
        return e;
    }

    /**
     * Mode guidé (smartphone) : pour chaque pièce non validée, dans l'ordre de la checklist,
     * caméra → aperçu → « Valider et suivant » (envoi du PDF puis validation de la pièce)
     * → pièce suivante, sans quitter l'écran caméra.
     */
    function startGuidedScan(startItemId) {
        const fallback = (id) => document.querySelector(`#piece-${id} .scan-photo-input`)?.click();
        if (!window.CoordinationDocScan || !window.CoordinationDocScan.supported()) { if (startItemId) fallback(startItemId); else feedback('Caméra indisponible : utilisez « 📷 Scanner » ou « 🖼️ Galerie ou PDF » sous chaque pièce.'); return; }
        if (scanSession && scanSession.pages.length && !confirm('Abandonner les pages non envoyées ?')) return;
        clearScanSession();
        const dossierId = state.currentDossier.dossier.id;
        const items = () => state.currentDossier.checklist || [];
        const all = items().map(i => Number(i.id));
        const startAt = Math.max(0, startItemId ? all.indexOf(startItemId) : all.findIndex(id => !items().find(i => Number(i.id) === id).coordinator_checked));
        const queue = all.slice(startAt).concat(all.slice(0, startAt)).filter(id => !items().find(i => Number(i.id) === id).coordinator_checked);
        if (!queue.length) { feedback('Toutes les pièces sont déjà validées.', false); return; }
        let index = 0, pages = [], uploaded = false, busy = false, validated = 0;
        const skipped = [];
        const current = () => items().find(i => Number(i.id) === queue[index]);
        const releasePages = () => { pages.forEach(p => URL.revokeObjectURL(p.preview)); pages = []; };

        const scanner = window.CoordinationDocScan.open({
            title: '', documentMode: state.scanDocMode, maxPages: 10,
            onDocMode: (on) => { state.scanDocMode = on; },
            onPage: async (canvas, documentMode) => {
                pages.push(await window.CoordinationScan.canvasToPage(canvas, documentMode));
                review();
            },
            onFallback: () => fallback(queue[index]),
            onClose: async () => {
                releasePages();
                await openDossierDetail(dossierId);
                if (validated || skipped.length) feedback(`${validated} pièce${validated > 1 ? 's' : ''} validée${validated > 1 ? 's' : ''} en mode guidé.` + (skipped.length ? ` ${skipped.length} pièce(s) passée(s) : à reprendre.` : ''), !!skipped.length);
            },
        });

        function enter(i) {
            index = i; uploaded = false; releasePages(); scanner.resetCount();
            const item = current();
            scanner.setTitle(`Pièce ${index + 1}/${queue.length} · ${item.document_label_fr}`);
            if (item.scan_count > 0) existing(); else scanner.resume();
        }

        function next() {
            if (index + 1 < queue.length) enter(index + 1); else finish();
        }

        async function refreshDetail() {
            const res = await apiRequest(`api/dossiers?detail_id=${dossierId}`);
            if (res.success) state.currentDossier = res.data;
        }

        /** Envoi du PDF (une seule fois) puis validation de la pièce ; réessayable. */
        async function validateCurrent(withPages, message) {
            if (busy) return;
            busy = true; message.textContent = withPages ? '⏳ Envoi du PDF…' : '⏳ Validation…'; message.classList.remove('is-error');
            const buttons = message.parentElement.querySelectorAll('button'); buttons.forEach(b => { b.disabled = true; });
            try {
                const item = current();
                if (withPages && !uploaded) {
                    const pdf = window.CoordinationScan.buildPdf(pages);
                    const blob = new Blob([pdf], { type: 'application/pdf' });
                    if (blob.size > state.scanMaxBytes) throw new Error(`PDF trop volumineux (${formatSize(blob.size)}) : retirez une page.`);
                    const form = new FormData(); form.append('file', blob, scanFileName(Number(item.id)));
                    const up = await apiRequest(`api/dossiers/${dossierId}/checklist/${item.id}/scans`, 'POST', form, true, 180000);
                    if (!up.success) throw new Error(up.error || 'envoi impossible');
                    uploaded = true;
                }
                message.textContent = '⏳ Validation de la pièce…';
                const ok = await apiRequest(`api/dossiers/${dossierId}/checklist`, 'POST', { checklist_item_id: Number(item.id), role: 'coordinator', checked: true });
                if (!ok.success) throw new Error(ok.error || 'validation impossible');
                validated++;
                if (navigator.vibrate) navigator.vibrate([30, 40, 30]);
                await refreshDetail();
                busy = false;
                next();
            } catch (err) {
                busy = false;
                buttons.forEach(b => { b.disabled = false; });
                message.textContent = '⚠️ ' + (err.message || err) + (uploaded ? ' Le scan est déjà enregistré : réessayez la validation.' : ' Les pages restent prêtes : réessayez.');
                message.classList.add('is-error');
            }
        }

        function review() {
            const item = current();
            const last = index + 1 >= queue.length;
            const message = h('p', { class: 'co-guided-msg', role: 'status', 'aria-live': 'polite' });
            const thumbs = h('div', { class: 'co-guided-thumbs' }, pages.map((p, i) => h('figure', {},
                h('img', { src: p.preview, alt: `Page ${i + 1}` }),
                h('figcaption', {}, `Page ${i + 1}`),
                h('button', { type: 'button', class: 'co-guided-del', 'aria-label': `Retirer la page ${i + 1}`, onclick: () => {
                    const [removed] = pages.splice(i, 1); if (removed) URL.revokeObjectURL(removed.preview);
                    uploaded = false;
                    if (pages.length) review(); else { scanner.resetCount(); scanner.resume(); }
                } }, '×'))));
            scanner.showPanel(h('div', { class: 'co-guided' },
                h('div', { class: 'co-guided-head' }, h('strong', {}, item.document_label_fr), h('span', {}, ` · ${pages.length} page${pages.length > 1 ? 's' : ''}`)),
                item.document_label_ar ? h('div', { class: 'co-guided-ar', lang: 'ar', dir: 'rtl' }, item.document_label_ar) : null,
                thumbs, message,
                h('div', { class: 'co-guided-actions' },
                    h('button', { type: 'button', class: 'co-guided-primary', onclick: () => validateCurrent(true, message) }, last ? '✅ Valider et terminer' : '✅ Valider et suivant'),
                    pages.length < 10 ? h('button', { type: 'button', onclick: () => scanner.resume() }, '➕ Ajouter une page (verso)') : null,
                    h('button', { type: 'button', onclick: () => { releasePages(); uploaded = false; scanner.resetCount(); scanner.resume(); } }, '↺ Reprendre la photo'),
                    h('button', { type: 'button', class: 'co-guided-link', onclick: () => { skipped.push(item.id); next(); } }, 'Passer cette pièce'))));
        }

        function existing() {
            const item = current();
            const message = h('p', { class: 'co-guided-msg', role: 'status', 'aria-live': 'polite' });
            scanner.showPanel(h('div', { class: 'co-guided' },
                h('div', { class: 'co-guided-head' }, h('strong', {}, item.document_label_fr)),
                h('p', {}, `Déjà scannée (${item.scan_count} scan${item.scan_count > 1 ? 's' : ''}), pas encore validée.`), message,
                h('div', { class: 'co-guided-actions' },
                    h('button', { type: 'button', class: 'co-guided-primary', onclick: () => validateCurrent(false, message) }, index + 1 >= queue.length ? '✅ Valider et terminer' : '✅ Valider et suivant'),
                    h('button', { type: 'button', onclick: () => scanner.resume() }, '📷 Scanner à nouveau'),
                    h('button', { type: 'button', class: 'co-guided-link', onclick: () => { skipped.push(item.id); next(); } }, 'Passer cette pièce'))));
        }

        function finish() {
            const missing = items().filter(i => i.is_mandatory && !(i.coordinator_checked && i.scan_count > 0));
            scanner.setTitle('Scan terminé');
            scanner.showPanel(h('div', { class: 'co-guided' },
                h('div', { class: 'co-guided-head' }, h('strong', {}, missing.length ? '⚠️ Pièces obligatoires restantes' : '✅ Toutes les pièces obligatoires sont scannées et validées')),
                missing.length ? h('ul', { class: 'co-guided-missing' }, missing.map(i => h('li', {}, i.document_label_fr))) : h('p', {}, 'Vous pouvez transmettre le dossier au Back-Office.'),
                h('div', { class: 'co-guided-actions' },
                    h('button', { type: 'button', class: 'co-guided-primary', onclick: () => scanner.close() }, missing.length ? 'Fermer' : 'Fermer et transmettre au BO'))));
        }

        enter(0);
    }

    function bindScanEvents() {
        document.querySelectorAll('.btn-live-page').forEach(btn => btn.addEventListener('click', (e) => openLiveScan(Number(e.currentTarget.getAttribute('data-item')))));
        document.querySelectorAll('.btn-live-scan').forEach(btn => btn.addEventListener('click', (e) => startGuidedScan(Number(e.currentTarget.getAttribute('data-item')))));
        document.querySelector('.btn-guided-scan')?.addEventListener('click', () => startGuidedScan(null));
        document.querySelectorAll('.scan-photo-input').forEach(input => input.addEventListener('change', async (e) => {
            const files = Array.from(e.target.files || []);
            const itemId = Number(e.target.getAttribute('data-item'));
            e.target.value = '';
            if (files.length) await addScanPhotos(itemId, files);
        }));
        document.querySelectorAll('.scan-pick-input').forEach(input => input.addEventListener('change', async (e) => {
            const files = Array.from(e.target.files || []);
            const itemId = Number(e.target.getAttribute('data-item'));
            e.target.value = '';
            const pdfs = files.filter(f => f.type === 'application/pdf' || /\.pdf$/i.test(f.name));
            const images = files.filter(f => !pdfs.includes(f) && /^image\//.test(f.type));
            if (pdfs.length > 1 || (pdfs.length && images.length)) { feedback('Choisissez un seul PDF, ou des photos (une par page).'); return; }
            if (pdfs.length) { await uploadScan(itemId, pdfs[0], pdfs[0].name); return; }
            if (images.length) await addScanPhotos(itemId, images);
            else if (files.length) feedback('Format non pris en charge : choisissez une photo ou un PDF.');
        }));
        document.querySelectorAll('.scan-doc-mode').forEach(chk => chk.addEventListener('change', (e) => { state.scanDocMode = e.target.checked; }));
        document.querySelectorAll('.btn-scan-page-del').forEach(btn => btn.addEventListener('click', (e) => {
            if (!scanSession) return;
            const [page] = scanSession.pages.splice(Number(e.currentTarget.getAttribute('data-index')), 1);
            if (page) URL.revokeObjectURL(page.preview);
            if (!scanSession.pages.length) clearScanSession();
            render();
        }));
        document.querySelector('.btn-scan-cancel')?.addEventListener('click', () => {
            if (scanSession && scanSession.pages.length && !confirm('Abandonner les pages photographiées ?')) return;
            clearScanSession(); render();
        });
        document.querySelector('.btn-scan-send')?.addEventListener('click', async (e) => {
            if (!scanSession || !scanSession.pages.length || mutationBusy) return;
            mutationBusy = true;
            try {
                const itemId = Number(e.currentTarget.getAttribute('data-item'));
                const pdf = window.CoordinationScan.buildPdf(scanSession.pages);
                await uploadScan(itemId, new Blob([pdf], { type: 'application/pdf' }), scanFileName(itemId));
            } catch (err) {
                console.error('PDF error:', err);
                feedback('Création du PDF impossible : ' + (err.message || err));
            } finally { mutationBusy = false; }
        });
        document.querySelectorAll('.btn-scan-delete').forEach(btn => btn.addEventListener('click', async (e) => {
            const scanId = Number(e.currentTarget.getAttribute('data-scan'));
            if (!confirm('Supprimer définitivement ce scan ?')) return;
            const dossierId = state.currentDossier.dossier.id;
            const res = await apiRequest(`api/dossiers/${dossierId}/scans/${scanId}/delete`, 'POST');
            if (!res.success) { feedback('Suppression impossible : ' + (res.error || 'erreur inconnue')); return; }
            await openDossierDetail(dossierId);
            feedback('Scan supprimé.', false);
        }));
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    document.addEventListener('DOMContentLoaded', init);
    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        init();
    }
})();
