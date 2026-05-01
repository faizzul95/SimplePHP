/**
 * Reusable Bootstrap-aware DataTable manager.
 *
 * Supported modes:
 * - simple: static in-memory data or existing table markup
 * - client: fetch once, render client-side
 * - server: standard DataTables server-side transport
 * - cursor: cursor/next-page APIs without DataTables pagination
 *
 * Identity model:
 * - rowId: stable value used as DOM row id and DataTables row selector
 * - key: optional application-level identifier for edit/delete/show routes
 *
 * Minimal example:
 * const table = new BootstrapDataTable({
 *   tableId: 'dataList',
 *   mode: 'server',
 *   rowId: 'row_key',
 *   ajax: {
 *     url: '/api/roles/list',
 *     method: 'POST',
 *     data: () => ({ status: $('#statusFilter').val() })
 *   },
 *   columns: [
 *     { data: 'name' },
 *     { data: 'status' },
 *     { data: 'action', orderable: false, searchable: false }
 *   ]
 * });
 *
 * await table.create();
 * table.updateRow('role-row-3', nextRowData);
 * table.removeRow('role-row-2');
 */
class BootstrapDataTable {
    constructor(options = {}) {
        const resolvedDefaults = this.mergeDeep(this.getDefaultOptions(), this.getGlobalDefaults());
        this.options = this.mergeDeep(resolvedDefaults, options);
        this.instance = null;
        this.$table = null;
        this.cursorState = {
            requestCursor: null,
            nextCursor: null,
            history: [],
            loading: false,
        };

        if (this.options.tableId) {
            this.setTable(this.options.tableId);
        }
    }

    getGlobalDefaults() {
        if (typeof window === 'undefined') {
            return {};
        }

        const globalDefaults = window.__bootstrapDataTableDefaults;
        return globalDefaults && typeof globalDefaults === 'object' ? globalDefaults : {};
    }

    getSettings() {
        if (!this.instance || typeof this.instance.settings !== 'function') {
            return null;
        }

        const settings = this.instance.settings();
        return Array.isArray(settings) ? settings[0] || null : settings || null;
    }

    updateServerSideDisplayState(totalDelta = 0, filteredDelta = 0) {
        if (!this.isServerSideTable()) {
            return;
        }

        const settings = this.getSettings();
        if (!settings) {
            return;
        }

        if (typeof settings._iRecordsTotal === 'number') {
            settings._iRecordsTotal = Math.max(0, settings._iRecordsTotal + totalDelta);
        }

        if (typeof settings._iRecordsDisplay === 'number') {
            settings._iRecordsDisplay = Math.max(0, settings._iRecordsDisplay + filteredDelta);
        }

        this.refreshInfoDisplay();
    }

    refreshInfoDisplay() {
        if (!this.instance) {
            return;
        }

        const settings = this.getSettings();
        if (!settings) {
            return;
        }

        const infoElement = document.getElementById(this.options.tableId + '_info');
        if (!(infoElement instanceof HTMLElement)) {
            return;
        }

        const filteredTotal = typeof settings._iRecordsDisplay === 'number' ? settings._iRecordsDisplay : this.countRows();
        const recordsTotal = typeof settings._iRecordsTotal === 'number' ? settings._iRecordsTotal : filteredTotal;
        const pageInfo = typeof this.instance.page === 'function' ? this.instance.page.info() : null;
        const startIndex = pageInfo && filteredTotal > 0 ? pageInfo.start + 1 : 0;
        const endIndex = filteredTotal > 0 ? ((pageInfo ? pageInfo.start : 0) + this.countRows()) : 0;
        const language = settings.oLanguage || {};
        const getLanguageValue = (modernKey, legacyKey, fallback) => {
            if (typeof language[modernKey] === 'string' && language[modernKey] !== '') {
                return language[modernKey];
            }

            if (typeof language[legacyKey] === 'string' && language[legacyKey] !== '') {
                return language[legacyKey];
            }

            return fallback;
        };
        const infoTemplate = filteredTotal === 0
            ? getLanguageValue('infoEmpty', 'sInfoEmpty', 'Showing 0 to 0 of 0 entries')
            : getLanguageValue('info', 'sInfo', 'Showing _START_ to _END_ of _TOTAL_ entries');

        let infoText = infoTemplate
            .replace('_START_', startIndex)
            .replace('_END_', endIndex)
            .replace('_TOTAL_', filteredTotal)
            .replace('_MAX_', recordsTotal);

        if (filteredTotal > 0 && filteredTotal !== recordsTotal) {
            const filteredTemplate = getLanguageValue('infoFiltered', 'sInfoFiltered', '(filtered from _MAX_ total entries)');
            infoText += ' ' + filteredTemplate.replace('_MAX_', recordsTotal);
        }

        infoElement.textContent = infoText;
    }

    getDefaultOptions() {
        return {
            tableId: null,
            mode: 'simple',
            rowId: null,
            rowIdColumn: null,
            columns: null,
            columnDefs: [],
            data: null,
            autoDestroy: true,
            responsive: true,
            processing: true,
            searching: true,
            ordering: true,
            lengthChange: true,
            autoWidth: false,
            pageLength: 10,
            lengthMenu: [10, 25, 50, 100],
            searchDelay: 350,
            dom: null,
            language: {
                searchPlaceholder: 'Search...',
                sSearch: '',
                lengthMenu: '_MENU_ item / page',
                paginate: {
                    first: 'First',
                    last: 'The End',
                    previous: 'Previous',
                    next: 'Next',
                },
                info: 'Showing _START_ to _END_ of _TOTAL_ items',
                sInfo: 'Showing _START_ to _END_ of _TOTAL_ items',
                emptyTable: 'No data is available in the table',
                infoEmpty: 'Showing 0 to 0 of 0 items',
                sInfoEmpty: 'Showing 0 to 0 of 0 items',
                infoFiltered: '(filtered from _MAX_ number of items)',
                sInfoFiltered: '(filtered from _MAX_ number of items)',
                zeroRecords: 'No matching records',
                processing: "<span class='text-danger font-weight-bold font-italic'>Processing ... Please wait a moment..</span>",
                loadingRecords: 'Loading...',
            },
            datatable: {},
            ajax: {
                url: null,
                method: 'POST',
                data: null,
                headers: {},
                dataType: 'json',
                timeout: 30000,
                token: null,
                dataSrc: 'data',
                recordsTotalPath: 'recordsTotal',
                recordsFilteredPath: 'recordsFiltered',
                drawPath: 'draw',
                transformRequest: null,
                transformResponse: null,
                onError: null,
            },
            cursor: {
                dataPath: 'data',
                nextCursorPath: 'next_cursor',
                cursorParam: 'cursor',
                limitParam: 'limit',
                searchParam: 'search',
                searchValue: '',
                enableSearch: false,
                enableOrdering: false,
                requestBuilder: null,
                onPageLoaded: null,
                previousButtonText: 'Previous',
                nextButtonText: 'Next',
                statusText: 'Page :current',
            },
            template: {
                bootstrapVersion: null,
                classes: {},
                dom: {
                    bs4: '<"row align-items-center"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>><"table-responsive"t><"row align-items-center"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
                    bs5: '<"row align-items-center g-2"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>><"table-responsive"t><"row align-items-center g-2"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
                },
            },
            ui: {
                tableWrapperId: null,
                emptyStateContainerId: null,
                loadingContainerId: null,
                showSkeleton: false,
                useLoadingIndicator: true,
                renderSkeleton: null,
                renderEmptyState: null,
                skeletonRowCount: 5,
                skeletonColumnCount: 5,
                noDataText: 'No data available.',
            },
            mutation: {
                rowPath: 'row',
                rowIdPath: null,
                shouldKeepRow: null,
                addMissingRow: 'auto',
                reloadWhenMissing: true,
                reloadWhenEmpty: true,
            },
            callbacks: {
                onInitComplete: null,
                onDraw: null,
                onRowCreated: null,
            },
        };
    }

    mergeDeep(target, source) {
        const output = { ...target };

        if (!source || typeof source !== 'object') {
            return output;
        }

        Object.keys(source).forEach((key) => {
            const targetValue = output[key];
            const sourceValue = source[key];

            if (Array.isArray(sourceValue)) {
                output[key] = sourceValue.slice();
                return;
            }

            if (sourceValue && typeof sourceValue === 'object') {
                output[key] = this.mergeDeep(targetValue && typeof targetValue === 'object' ? targetValue : {}, sourceValue);
                return;
            }

            output[key] = sourceValue;
        });

        return output;
    }

    setTable(tableId) {
        this.options.tableId = this.normalizeTableId(tableId);
        this.$table = $('#' + this.options.tableId);

        if (!this.$table.length) {
            throw new Error('DataTable element not found: #' + this.options.tableId);
        }

        return this;
    }

    normalizeTableId(tableId) {
        return String(tableId || '').replace(/^#/, '').trim();
    }

    getBootstrapMajorVersion() {
        if (this.options.template.bootstrapVersion) {
            return parseInt(this.options.template.bootstrapVersion, 10);
        }

        const versionCandidates = [
            window.bootstrap && window.bootstrap.Modal && window.bootstrap.Modal.VERSION,
            $.fn.modal && $.fn.modal.Constructor && $.fn.modal.Constructor.VERSION,
        ];

        for (let index = 0; index < versionCandidates.length; index++) {
            const version = versionCandidates[index];
            if (!version) {
                continue;
            }

            const major = parseInt(String(version).split('.')[0], 10);
            if (!Number.isNaN(major)) {
                return major;
            }
        }

        return 5;
    }

    getResolvedDom() {
        if (this.options.dom) {
            return this.options.dom;
        }

        return this.getBootstrapMajorVersion() >= 5
            ? this.options.template.dom.bs5
            : this.options.template.dom.bs4;
    }

    getResolvedClasses() {
        const major = this.getBootstrapMajorVersion();
        const baseClasses = major >= 5
            ? {
                wrapper: 'dt-bootstrap5',
                filterInput: 'form-control form-control-sm',
                lengthSelect: 'form-select form-select-sm',
            }
            : {
                wrapper: 'dt-bootstrap4',
                filterInput: 'form-control form-control-sm',
                lengthSelect: 'custom-select custom-select-sm form-control form-control-sm',
            };

        return this.mergeDeep(baseClasses, this.options.template.classes || {});
    }

    resolveUrl(url) {
        if (!url) {
            return null;
        }

        if (/^(https?:)?\/\//i.test(url)) {
            return url;
        }

        if (typeof urls === 'function') {
            return urls(url);
        }

        const baseMeta = document.querySelector('meta[name="base_url"]');
        if (baseMeta && baseMeta.content) {
            return baseMeta.content + url.replace(/^\//, '');
        }

        return url;
    }

    getCsrfToken() {
        if (typeof getCsrfToken === 'function') {
            return getCsrfToken();
        }

        const meta = document.querySelector('meta[name="csrf-token"], meta[name="csrf_token"], meta[name="secure_token"]');
        return meta ? meta.content : null;
    }

    resolveToken(token = null) {
        if (token === false) {
            return null;
        }

        if (typeof token === 'string' && token.length > 0) {
            return token;
        }

        if (typeof _resolveToken === 'function') {
            return _resolveToken(token);
        }

        const storedToken = window.localStorage ? localStorage.getItem('api_token') : null;
        if (storedToken) {
            return storedToken;
        }

        if (token === true) {
            const metaToken = document.querySelector('meta[name="api_token"]');
            return metaToken ? metaToken.content : null;
        }

        return null;
    }

    buildAjaxHeaders() {
        const csrfToken = this.getCsrfToken();
        const bearerToken = this.resolveToken(this.options.ajax.token);
        const headers = {
            'X-Requested-With': 'XMLHttpRequest',
            'content-type': 'application/x-www-form-urlencoded',
        };

        if (csrfToken) {
            headers['X-CSRF-TOKEN'] = csrfToken;
        }

        if (bearerToken) {
            headers.Authorization = 'Bearer ' + bearerToken;
        }

        return this.mergeDeep(headers, this.options.ajax.headers || {});
    }

    ensureTablePlugin() {
        if (!$.fn || !$.fn.DataTable) {
            throw new Error('jQuery DataTable plugin is not available');
        }
    }

    hasExistingInstance() {
        return !!(this.$table && $.fn.dataTable && $.fn.dataTable.isDataTable(this.$table));
    }

    destroy() {
        this.hideLoading();
        this.unbindCursorControls();

        if (this.hasExistingInstance()) {
            this.$table.DataTable().clear().destroy();
        }

        this.instance = null;
        return this;
    }

    create(options = {}) {
        this.options = this.mergeDeep(this.options, options);
        this.ensureTablePlugin();
        this.setTable(this.options.tableId);

        if (this.options.autoDestroy) {
            this.destroy();
        }

        const mode = String(this.options.mode || 'simple').toLowerCase();

        if (mode === 'server') {
            this.ensureRemoteUrl('server');
            return this.createServerTable();
        }

        if (mode === 'client') {
            this.ensureRemoteUrl('client');
            return this.createClientTable();
        }

        if (mode === 'cursor') {
            this.ensureRemoteUrl('cursor');
            return this.createCursorTable();
        }

        return this.createSimpleTable();
    }

    ensureRemoteUrl(mode) {
        const resolvedUrl = this.resolveUrl(this.options.ajax && this.options.ajax.url);

        if (!resolvedUrl) {
            throw new Error('BootstrapDataTable ' + mode + ' mode requires ajax.url');
        }

        return resolvedUrl;
    }

    buildDataTableOptions(mode) {
        const instance = this;
        const userInitComplete = this.options.datatable.initComplete;
        const userDrawCallback = this.options.datatable.drawCallback;
        const userCreatedRow = this.options.datatable.createdRow;
        const options = {
            processing: mode === 'server' ? true : this.options.processing,
            serverSide: mode === 'server',
            responsive: this.options.responsive,
            autoWidth: this.options.autoWidth,
            searching: mode === 'cursor' ? !!this.options.cursor.enableSearch : this.options.searching,
            ordering: mode === 'cursor' ? !!this.options.cursor.enableOrdering : this.options.ordering,
            lengthChange: this.options.lengthChange,
            pageLength: this.options.pageLength,
            lengthMenu: this.options.lengthMenu,
            searchDelay: mode === 'server' ? this.options.searchDelay : 0,
            deferRender: mode !== 'simple',
            dom: this.getResolvedDom(),
            language: this.mergeDeep({}, this.options.language),
            columns: this.options.columns,
            columnDefs: this.options.columnDefs,
            initComplete(settings, json) {
                instance.applyTemplateClasses();
                instance.toggleEmptyState(instance.countRows() > 0);
                instance.hideLoading();

                if (typeof instance.options.callbacks.onInitComplete === 'function') {
                    instance.options.callbacks.onInitComplete.call(this, settings, json, instance);
                }

                if (typeof userInitComplete === 'function') {
                    userInitComplete.call(this, settings, json);
                }
            },
            drawCallback(settings) {
                instance.applyTemplateClasses();
                instance.toggleEmptyState(instance.countRows() > 0);

                if (typeof instance.options.callbacks.onDraw === 'function') {
                    instance.options.callbacks.onDraw.call(this, settings, instance);
                }

                if (typeof userDrawCallback === 'function') {
                    userDrawCallback.call(this, settings);
                }
            },
            createdRow(row, data, dataIndex) {
                instance.applyRowIdentity(row, data, dataIndex);

                if (typeof instance.options.callbacks.onRowCreated === 'function') {
                    instance.options.callbacks.onRowCreated(row, data, dataIndex, instance);
                }

                if (typeof userCreatedRow === 'function') {
                    userCreatedRow(row, data, dataIndex);
                }
            },
        };

        if (typeof this.options.rowId === 'string' && this.options.rowId.length > 0) {
            options.rowId = this.options.rowId;
        }

        return this.mergeDeep(options, this.options.datatable);
    }

    createServerTable() {
        const requestOptions = this.buildDataTableOptions('server');

        requestOptions.ajax = (request, callback) => {
            const payload = this.buildServerPayload(request);
            this.showLoading();

            this.fetchRemoteData(payload)
                .then((response) => {
                    const normalized = this.normalizeServerResponse(response, request);
                    callback(normalized);
                    this.toggleEmptyState(normalized.data.length > 0);
                })
                .catch((error) => {
                    this.handleRequestError(error);
                    callback({
                        draw: request.draw,
                        recordsTotal: 0,
                        recordsFiltered: 0,
                        data: [],
                    });
                    this.toggleEmptyState(false);
                })
                .finally(() => {
                    this.hideLoading();
                });
        };

        this.instance = this.$table.DataTable(requestOptions);
        return this.instance;
    }

    async createClientTable() {
        this.showLoading();

        try {
            const response = await this.fetchRemoteData(this.buildClientPayload());
            const rows = this.normalizeArrayResponse(response, this.options.ajax.dataSrc);
            const requestOptions = this.buildDataTableOptions('client');
            requestOptions.data = rows;
            requestOptions.serverSide = false;

            this.instance = this.$table.DataTable(requestOptions);
            this.toggleEmptyState(rows.length > 0);
            return this.instance;
        } catch (error) {
            this.handleRequestError(error);
            this.toggleEmptyState(false);
            return null;
        } finally {
            this.hideLoading();
        }
    }

    createSimpleTable() {
        const requestOptions = this.buildDataTableOptions('simple');

        if (Array.isArray(this.options.data)) {
            requestOptions.data = this.options.data;
        }

        requestOptions.serverSide = false;
        requestOptions.processing = false;

        this.instance = this.$table.DataTable(requestOptions);
        this.toggleEmptyState(this.countRows() > 0);
        return this.instance;
    }

    async createCursorTable() {
        const requestOptions = this.buildDataTableOptions('cursor');
        requestOptions.data = [];
        requestOptions.serverSide = false;
        requestOptions.processing = false;
        requestOptions.paging = false;
        requestOptions.info = false;

        this.instance = this.$table.DataTable(requestOptions);
        this.mountCursorControls();
        await this.loadCursorPage(null, 'initial');
        return this.instance;
    }

    buildServerPayload(request) {
        const customPayload = typeof this.options.ajax.data === 'function'
            ? this.options.ajax.data(request, this)
            : this.options.ajax.data;

        const payload = this.mergeDeep(request, customPayload || {});
        return typeof this.options.ajax.transformRequest === 'function'
            ? this.options.ajax.transformRequest(payload, { mode: 'server', manager: this })
            : payload;
    }

    buildClientPayload() {
        const payload = typeof this.options.ajax.data === 'function'
            ? this.options.ajax.data({}, this)
            : (this.options.ajax.data || {});

        return typeof this.options.ajax.transformRequest === 'function'
            ? this.options.ajax.transformRequest(payload, { mode: 'client', manager: this })
            : payload;
    }

    async fetchRemoteData(payload) {
        const ajaxUrl = this.ensureRemoteUrl(String(this.options.mode || 'remote').toLowerCase());
        const method = String(this.options.ajax.method || 'POST').toUpperCase();

        return new Promise((resolve, reject) => {
            $.ajax({
                url: ajaxUrl,
                type: method,
                dataType: this.options.ajax.dataType,
                data: payload,
                headers: this.buildAjaxHeaders(),
                timeout: this.options.ajax.timeout,
                success: (response) => {
                    const transformed = typeof this.options.ajax.transformResponse === 'function'
                        ? this.options.ajax.transformResponse(response, { payload, manager: this })
                        : response;

                    resolve(transformed);
                },
                error: (xhr, error, exception) => {
                    reject({ xhr, error, exception });
                },
            });
        });
    }

    normalizeServerResponse(response, request) {
        if (Array.isArray(response)) {
            return {
                draw: request.draw,
                recordsTotal: response.length,
                recordsFiltered: response.length,
                data: response,
            };
        }

        const rows = this.normalizeArrayResponse(response, this.options.ajax.dataSrc);
        const recordsTotal = this.getValueByPath(response, this.options.ajax.recordsTotalPath, rows.length);
        const recordsFiltered = this.getValueByPath(response, this.options.ajax.recordsFilteredPath, recordsTotal);
        const draw = this.getValueByPath(response, this.options.ajax.drawPath, request.draw);

        return {
            draw,
            recordsTotal,
            recordsFiltered,
            data: rows,
        };
    }

    normalizeArrayResponse(response, dataPath = 'data') {
        if (Array.isArray(response)) {
            return response;
        }

        const rows = this.getValueByPath(response, dataPath, []);
        return Array.isArray(rows) ? rows : [];
    }

    getValueByPath(source, path, fallback = null) {
        if (!path) {
            return source == null ? fallback : source;
        }

        if (source == null) {
            return fallback;
        }

        const segments = String(path).split('.');
        let value = source;

        for (let index = 0; index < segments.length; index++) {
            const segment = segments[index];
            if (value == null || !Object.prototype.hasOwnProperty.call(value, segment)) {
                return fallback;
            }
            value = value[segment];
        }

        return value == null ? fallback : value;
    }

    applyRowIdentity(row, data) {
        const resolvedRowId = this.resolveRowId(data);
        if (resolvedRowId == null || resolvedRowId === '') {
            return;
        }

        row.id = String(resolvedRowId);
        row.setAttribute('data-row-id', String(resolvedRowId));
    }

    resolveRowId(data) {
        if (typeof this.options.rowId === 'function') {
            return this.options.rowId(data, this);
        }

        if (typeof this.options.rowId === 'string' && this.options.rowId.length > 0) {
            return this.getValueByPath(data, this.options.rowId, null);
        }

        if (this.options.rowIdColumn != null) {
            if (Array.isArray(data)) {
                return data[this.options.rowIdColumn];
            }

            if (typeof this.options.rowIdColumn === 'string') {
                return this.getValueByPath(data, this.options.rowIdColumn, null);
            }
        }

        return null;
    }

    countRows() {
        if (!this.instance) {
            return 0;
        }

        return this.instance.rows().count();
    }

    applyTemplateClasses() {
        if (!this.instance) {
            return;
        }

        const classes = this.getResolvedClasses();
        const $container = $(this.instance.table().container());

        if (classes.wrapper) {
            $container.addClass(classes.wrapper);
        }

        if (classes.filterInput) {
            $container.find('div.dataTables_filter input').addClass(classes.filterInput);
        }

        if (classes.lengthSelect) {
            $container.find('div.dataTables_length select').addClass(classes.lengthSelect);
        }
    }

    getTableWrapperId() {
        return this.options.ui.tableWrapperId || (this.options.tableId ? this.options.tableId + 'Div' : null);
    }

    toggleEmptyState(hasRows) {
        const emptyStateContainerId = this.options.ui.emptyStateContainerId;
        const tableWrapperId = this.getTableWrapperId();

        if (tableWrapperId) {
            $('#' + tableWrapperId).toggle(!!hasRows);
        }

        if (!emptyStateContainerId) {
            return;
        }

        const $emptyState = $('#' + emptyStateContainerId);
        if (!$emptyState.length) {
            return;
        }

        if (hasRows) {
            $emptyState.hide();
            return;
        }

        if (!$emptyState.html().trim()) {
            const renderer = typeof this.options.ui.renderEmptyState === 'function'
                ? this.options.ui.renderEmptyState
                : () => this.renderDefaultEmptyState();
            $emptyState.html(renderer(this));
        }

        $emptyState.show();
    }

    renderDefaultEmptyState() {
        return '<div class="alert alert-light border text-center mb-0">' + this.escapeHtml(this.options.ui.noDataText) + '</div>';
    }

    renderDefaultSkeleton() {
        const rowCount = Number(this.options.ui.skeletonRowCount || 5);
        const columnCount = Number(this.options.ui.skeletonColumnCount || 5);
        let body = '';

        for (let rowIndex = 0; rowIndex < rowCount; rowIndex++) {
            let columns = '';
            for (let columnIndex = 0; columnIndex < columnCount; columnIndex++) {
                columns += '<td><span class="placeholder col-12"></span></td>';
            }
            body += '<tr>' + columns + '</tr>';
        }

        return '<div class="table-responsive"><table class="table"><tbody>' + body + '</tbody></table></div>';
    }

    showLoading() {
        const loadingContainerId = this.options.ui.loadingContainerId;

        if (this.options.ui.showSkeleton && loadingContainerId) {
            const renderer = typeof this.options.ui.renderSkeleton === 'function'
                ? this.options.ui.renderSkeleton
                : () => this.renderDefaultSkeleton();

            $('#' + loadingContainerId).html(renderer(this)).show();
            return;
        }

        if (loadingContainerId && this.options.ui.useLoadingIndicator && typeof loading === 'function') {
            loading('#' + loadingContainerId, true);
        }
    }

    hideLoading() {
        const loadingContainerId = this.options.ui.loadingContainerId;
        if (!loadingContainerId) {
            return;
        }

        if (this.options.ui.showSkeleton) {
            $('#' + loadingContainerId).hide().empty();
            return;
        }

        if (this.options.ui.useLoadingIndicator && typeof loading === 'function') {
            loading('#' + loadingContainerId, false);
        }
    }

    handleRequestError(error) {
        const xhr = error && error.xhr ? error.xhr : null;
        const responseCode = xhr ? xhr.status : 500;
        const responseMessage = xhr && xhr.responseJSON && xhr.responseJSON.message
            ? xhr.responseJSON.message
            : (error && error.exception) || 'Unable to load data';

        if (typeof this.options.ajax.onError === 'function') {
            this.options.ajax.onError(error, this);
            return;
        }

        if (typeof noti === 'function' && responseCode) {
            if (typeof isError === 'function' && isError(responseCode)) {
                noti(responseCode, responseMessage);
            }
            return;
        }

        console.error('BootstrapDataTable request failed:', error);
    }

    getRow(rowId) {
        if (!this.instance) {
            return null;
        }

        const selector = this.buildRowSelector(rowId);
        const row = this.instance.row(selector);
        return row && row.any() ? row : null;
    }

    getMutationOptions(options = {}) {
        return this.mergeDeep(this.options.mutation || {}, options);
    }

    resolvePayloadRow(payload, options = {}) {
        const mutationOptions = this.getMutationOptions(options);

        if (payload == null) {
            return null;
        }

        if (typeof payload !== 'object') {
            return null;
        }

        if (!mutationOptions.rowPath) {
            return payload;
        }

        return this.getValueByPath(payload, mutationOptions.rowPath, null);
    }

    resolvePayloadRowId(payloadOrRowId, options = {}) {
        const mutationOptions = this.getMutationOptions(options);

        if (typeof payloadOrRowId === 'string' || typeof payloadOrRowId === 'number') {
            return String(payloadOrRowId);
        }

        if (!payloadOrRowId || typeof payloadOrRowId !== 'object') {
            return null;
        }

        if (typeof mutationOptions.rowIdPath === 'string' && mutationOptions.rowIdPath.length > 0) {
            const explicitRowId = this.getValueByPath(payloadOrRowId, mutationOptions.rowIdPath, null);
            if (explicitRowId != null && explicitRowId !== '') {
                return String(explicitRowId);
            }
        }

        const rowData = this.resolvePayloadRow(payloadOrRowId, mutationOptions);
        const resolvedRowId = rowData ? this.resolveRowId(rowData) : this.resolveRowId(payloadOrRowId);

        return resolvedRowId == null || resolvedRowId === '' ? null : String(resolvedRowId);
    }

    isServerSideTable() {
        if (!this.instance || typeof this.instance.settings !== 'function') {
            return String(this.options.mode).toLowerCase() === 'server';
        }

        const settings = this.instance.settings()[0];
        return !!(settings && settings.oFeatures && settings.oFeatures.bServerSide);
    }

    resolveColumnValue(columnConfig, rowData, columnIndex) {
        if (!columnConfig) {
            return '';
        }

        if (typeof columnConfig.data === 'function') {
            return columnConfig.data(rowData, 'display', rowData, {
                col: columnIndex,
                settings: this.instance ? this.instance.settings()[0] : null,
            });
        }

        if (typeof columnConfig.data === 'string' && columnConfig.data.length > 0) {
            return this.getValueByPath(rowData, columnConfig.data, '');
        }

        if (typeof columnConfig.data === 'number') {
            return Array.isArray(rowData) ? (rowData[columnConfig.data] ?? '') : '';
        }

        if (columnConfig.data == null) {
            return rowData;
        }

        return '';
    }

    getRenderableColumns() {
        if (Array.isArray(this.options.columns) && this.options.columns.length > 0) {
            return this.options.columns;
        }

        if (!this.instance || typeof this.instance.settings !== 'function') {
            return [];
        }

        const settings = this.instance.settings()[0];
        if (!settings || !Array.isArray(settings.aoColumns)) {
            return [];
        }

        return settings.aoColumns.map((column) => ({
            data: typeof column.mData === 'undefined' ? null : column.mData,
            render: typeof column.mRender === 'function' ? column.mRender : null,
        }));
    }

    refreshRowNode(rowApi, rowData) {
        const rowNode = rowApi && typeof rowApi.node === 'function' ? rowApi.node() : null;
        if (!rowNode) {
            return;
        }

        const columns = this.getRenderableColumns();
        const cells = rowNode.cells || [];

        for (let index = 0; index < cells.length; index++) {
            const columnConfig = columns[index] || {};
            let cellValue = this.resolveColumnValue(columnConfig, rowData, index);

            if (typeof columnConfig.render === 'function') {
                cellValue = columnConfig.render(cellValue, 'display', rowData, {
                    col: index,
                    settings: this.instance ? this.instance.settings()[0] : null,
                });
            }

            cells[index].innerHTML = cellValue == null ? '' : String(cellValue);
        }
        this.applyRowIdentity(rowNode, rowData);
    }

    updateRow(rowId, rowData, redraw = false) {
        const row = this.getRow(rowId);
        if (!row) {
            return false;
        }

        if (this.isServerSideTable() && !redraw) {
            row.data(rowData);
            this.refreshRowNode(row, rowData);
            this.toggleEmptyState(this.countRows() > 0);
            return true;
        }

        row.data(rowData).invalidate();
        this.instance.draw(!!redraw);
        return true;
    }

    removeRow(rowId, redraw = false, options = {}) {
        const row = this.getRow(rowId);
        if (!row) {
            return false;
        }

        const mutationOptions = this.getMutationOptions(options);
        const rowNode = typeof row.node === 'function' ? row.node() : null;
        row.remove();

        if (this.isServerSideTable() && !redraw) {
            if (rowNode && rowNode.parentNode) {
                rowNode.parentNode.removeChild(rowNode);
            }

            const serverSideCountDelta = mutationOptions.serverSideCountDelta || {};
            const totalDelta = Number.isFinite(serverSideCountDelta.total) ? serverSideCountDelta.total : -1;
            const filteredDelta = Number.isFinite(serverSideCountDelta.filtered) ? serverSideCountDelta.filtered : -1;
            this.updateServerSideDisplayState(totalDelta, filteredDelta);
            this.toggleEmptyState(this.countRows() > 0);

            if (mutationOptions.reloadWhenEmpty && this.countRows() === 0) {
                this.reload(false);
            }

            return true;
        }

        this.instance.draw(!!redraw);
        this.toggleEmptyState(this.countRows() > 0);

        if (mutationOptions.reloadWhenEmpty && this.countRows() === 0) {
            this.reload(false);
        }

        return true;
    }

    addRow(rowData, redraw = false) {
        if (!this.instance) {
            return null;
        }

        const row = this.instance.row.add(rowData);
        this.instance.draw(!!redraw);
        this.toggleEmptyState(this.countRows() > 0);
        return row;
    }

    reload(resetPaging = false) {
        if (!this.instance) {
            return this;
        }

        if (String(this.options.mode).toLowerCase() === 'cursor') {
            this.loadCursorPage(this.cursorState.requestCursor, 'reload');
            return this;
        }

        if (this.instance.ajax && typeof this.instance.ajax.reload === 'function') {
            this.instance.ajax.reload(null, resetPaging);
        }

        return this;
    }

    syncRowFromPayload(payload, options = {}) {
        const mutationOptions = this.getMutationOptions(options);
        const rowData = this.resolvePayloadRow(payload, mutationOptions);
        const rowId = this.resolvePayloadRowId(payload, mutationOptions);

        if (!rowData || !rowId) {
            if (mutationOptions.reloadWhenMissing) {
                this.reload(false);
            }
            return false;
        }

        const existingRow = this.getRow(rowId);
        const shouldKeepRow = typeof mutationOptions.shouldKeepRow === 'function'
            ? mutationOptions.shouldKeepRow(rowData, this, payload)
            : true;

        if (!shouldKeepRow) {
            if (existingRow) {
                return this.removeRow(rowId, false, {
                    ...mutationOptions,
                    serverSideCountDelta: {
                        total: 0,
                        filtered: -1,
                    },
                });
            }

            if (mutationOptions.reloadWhenMissing) {
                this.reload(false);
            }

            return false;
        }

        if (existingRow) {
            return this.updateRow(rowId, rowData, false);
        }

        const addMissingRow = mutationOptions.addMissingRow;
        const shouldAddLocally = addMissingRow === true || addMissingRow === 'add' || (addMissingRow === 'auto' && !this.isServerSideTable());

        if (shouldAddLocally) {
            this.addRow(rowData, false);
            return true;
        }

        if (mutationOptions.reloadWhenMissing) {
            this.reload(false);
        }

        return false;
    }

    removeRowByPayload(payloadOrRowId, options = {}) {
        const mutationOptions = this.getMutationOptions(options);
        const rowId = this.resolvePayloadRowId(payloadOrRowId, mutationOptions);

        if (!rowId) {
            if (mutationOptions.reloadWhenMissing) {
                this.reload(false);
            }
            return false;
        }

        const removed = this.removeRow(rowId, false, mutationOptions);

        if (!removed && mutationOptions.reloadWhenMissing) {
            this.reload(false);
        }

        return removed;
    }

    buildRowSelector(rowId) {
        const safeRowId = $.escapeSelector ? $.escapeSelector(String(rowId)) : String(rowId).replace(/([ #;?%&,.+*~\':"!^$\[\]()=>|\/])/g, '\\$1');
        return '#' + safeRowId;
    }

    async loadCursorPage(requestCursor = null, direction = 'initial') {
        if (!this.instance || this.cursorState.loading) {
            return;
        }

        this.cursorState.loading = true;
        this.showLoading();

        try {
            const payload = this.buildCursorPayload(requestCursor);
            const response = await this.fetchRemoteData(payload);
            const rows = this.normalizeArrayResponse(response, this.options.cursor.dataPath);
            const nextCursor = this.getValueByPath(response, this.options.cursor.nextCursorPath, null);

            if (direction === 'next') {
                this.cursorState.history.push(this.cursorState.requestCursor);
            }

            if (direction === 'previous') {
                this.cursorState.history.pop();
            }

            this.cursorState.requestCursor = requestCursor;
            this.cursorState.nextCursor = nextCursor;

            this.instance.clear();
            this.instance.rows.add(rows).draw(false);
            this.toggleEmptyState(rows.length > 0);
            this.updateCursorControls(rows.length);

            if (typeof this.options.cursor.onPageLoaded === 'function') {
                this.options.cursor.onPageLoaded({ rows, response, nextCursor, requestCursor, manager: this });
            }
        } catch (error) {
            this.handleRequestError(error);
            this.toggleEmptyState(false);
            this.updateCursorControls(0);
        } finally {
            this.cursorState.loading = false;
            this.hideLoading();
        }
    }

    buildCursorPayload(requestCursor) {
        const basePayload = typeof this.options.ajax.data === 'function'
            ? this.options.ajax.data({}, this)
            : (this.options.ajax.data || {});
        const requestBuilder = this.options.cursor.requestBuilder;

        if (typeof requestBuilder === 'function') {
            return requestBuilder({
                cursor: requestCursor,
                limit: this.options.pageLength,
                search: this.options.cursor.searchValue,
                manager: this,
                payload: basePayload,
            });
        }

        const payload = this.mergeDeep({}, basePayload);
        payload[this.options.cursor.limitParam] = this.options.pageLength;

        if (requestCursor) {
            payload[this.options.cursor.cursorParam] = requestCursor;
        }

        if (this.options.cursor.searchValue) {
            payload[this.options.cursor.searchParam] = this.options.cursor.searchValue;
        }

        return typeof this.options.ajax.transformRequest === 'function'
            ? this.options.ajax.transformRequest(payload, { mode: 'cursor', manager: this })
            : payload;
    }

    mountCursorControls() {
        if (!this.$table || !this.$table.length) {
            return;
        }

        this.unbindCursorControls();

        const controlsId = this.options.tableId + '-cursor-controls';
        const controlsHtml = [
            '<div id="' + controlsId + '" class="d-flex align-items-center justify-content-between gap-2 mt-3">',
            '<button type="button" class="btn btn-outline-secondary btn-sm" data-dt-cursor="previous" disabled>' + this.escapeHtml(this.options.cursor.previousButtonText) + '</button>',
            '<span class="small text-muted" data-dt-cursor="status"></span>',
            '<button type="button" class="btn btn-outline-secondary btn-sm" data-dt-cursor="next" disabled>' + this.escapeHtml(this.options.cursor.nextButtonText) + '</button>',
            '</div>',
        ].join('');

        $(this.instance.table().container()).after(controlsHtml);

        const $controls = $('#' + controlsId);
        $controls.on('click', '[data-dt-cursor="next"]', () => {
            if (this.cursorState.nextCursor) {
                this.loadCursorPage(this.cursorState.nextCursor, 'next');
            }
        });

        $controls.on('click', '[data-dt-cursor="previous"]', () => {
            if (!this.cursorState.history.length) {
                return;
            }

            const previousCursor = this.cursorState.history[this.cursorState.history.length - 1] || null;
            this.loadCursorPage(previousCursor, 'previous');
        });
    }

    updateCursorControls(rowCount) {
        const controlsId = this.options.tableId + '-cursor-controls';
        const $controls = $('#' + controlsId);
        if (!$controls.length) {
            return;
        }

        const currentPage = this.cursorState.history.length + 1;
        const statusText = String(this.options.cursor.statusText || 'Page :current')
            .replace(':current', currentPage)
            .replace(':count', rowCount);

        $controls.find('[data-dt-cursor="status"]').text(statusText);
        $controls.find('[data-dt-cursor="previous"]').prop('disabled', this.cursorState.history.length === 0);
        $controls.find('[data-dt-cursor="next"]').prop('disabled', !this.cursorState.nextCursor);
    }

    unbindCursorControls() {
        if (!this.options.tableId) {
            return;
        }

        $('#' + this.options.tableId + '-cursor-controls').remove();
    }

    escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
}

window.BootstrapDataTable = BootstrapDataTable;