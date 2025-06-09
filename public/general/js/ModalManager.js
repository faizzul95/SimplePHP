// Dynamic Modal Manager Class with API Support and Error Handling
class ModalManager {
    constructor() {
        this.activeModals = new Map();
        this.activeOffcanvas = new Map();
        this.defaultModalConfig = {
            size: 'lg',
            title: 'General Modal',
            content: 'Please add content',
            backdrop: true,
            keyboard: true,
            focus: true,
            showHeader: true,
            showFooter: true,
            showClose: true,
            headerClass: '',
            bodyClass: '',
            footerClass: '',
            modalClass: '',
            centered: false,
            scrollable: false,
            staticBackdrop: false,
            footerButtons: [{
                text: 'Close',
                class: 'btn btn-secondary',
                dismiss: true
            }],
            onShow: null,
            onShown: null,
            onHide: null,
            onHidden: null
        };
        
        this.defaultOffcanvasConfig = {
            position: 'end',
            title: 'General Offcanvas',
            content: 'Please add content',
            backdrop: true,
            keyboard: true,
            scroll: false,
            showHeader: true,
            showClose: true,
            width: '400px',
            height: 'auto',
            headerClass: '',
            bodyClass: '',
            offcanvasClass: '',
            customStyles: '',
            onShow: null,
            onShown: null,
            onHide: null,
            onHidden: null
        };

        this.defaultApiConfig = {
            url: '',
            method: 'GET',
            headers: {
                'Content-Type': 'application/json'
            },
            body: null,
            timeout: 30000,
            retries: 3,
            retryDelay: 1000,
            responseType: 'json', // 'json', 'text', 'html'
            showLoader: true,
            loaderText: 'Loading...',
            showRefreshOnError: true,
            onLoadStart: null,
            onLoadEnd: null,
            onError: null,
            onSuccess: null
        };
    }

    // Generate unique ID for each modal/offcanvas
    generateUniqueId(prefix) {
        try {
            return prefix + '-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
        } catch (error) {
            console.error('Error generating unique ID:', error);
            return prefix + '-' + Date.now();
        }
    }

    // Create and show modal
    showModal(options = {}) {
        try {
            const config = { ...this.defaultModalConfig, ...options };
            
            // Generate unique IDs
            const modalId = this.generateUniqueId('modal');
            const titleId = this.generateUniqueId('title');
            const contentId = this.generateUniqueId('content');
            
            // Create modal HTML
            const modalHtml = this.createModalHtml(modalId, titleId, contentId, config);
            
            // Add to DOM
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            
            // Get modal element
            const modalElement = document.getElementById(modalId);
            if (!modalElement) {
                throw new Error('Failed to create modal element');
            }
            
            // Configure Bootstrap modal options
            const bsOptions = {
                backdrop: config.staticBackdrop ? 'static' : config.backdrop,
                keyboard: config.keyboard,
                focus: config.focus
            };
            
            // Create Bootstrap modal instance
            const modal = new bootstrap.Modal(modalElement, bsOptions);
            
            // Create modal controller object
            const modalController = {
                modal: modal,
                element: modalElement,
                modalId: modalId,
                titleId: titleId,
                contentId: contentId,
                config: config,
                updateTitle: (newTitle) => {
                    try {
                        const titleElement = document.getElementById(titleId);
                        if (titleElement) {
                            titleElement.textContent = newTitle;
                        }
                    } catch (error) {
                        console.error('Error updating modal title:', error);
                    }
                },
                updateContent: (newContent) => {
                    try {
                        const contentElement = document.getElementById(contentId);
                        if (contentElement) {
                            contentElement.innerHTML = newContent;
                        }
                    } catch (error) {
                        console.error('Error updating modal content:', error);
                    }
                },
                hide: () => {
                    try {
                        modal.hide();
                    } catch (error) {
                        console.error('Error hiding modal:', error);
                    }
                },
                show: () => {
                    try {
                        modal.show();
                    } catch (error) {
                        console.error('Error showing modal:', error);
                    }
                },
                toggle: () => {
                    try {
                        modal.toggle();
                    } catch (error) {
                        console.error('Error toggling modal:', error);
                    }
                },
                dispose: () => {
                    try {
                        modal.dispose();
                        modalElement.remove();
                        this.activeModals.delete(modalId);
                    } catch (error) {
                        console.error('Error disposing modal:', error);
                    }
                }
            };
            
            // Add event listeners
            this.addModalEventListeners(modalElement, config, modalController);
            
            // Store active modal
            this.activeModals.set(modalId, modalController);
            
            // Show modal
            modal.show();
            
            return modalController;
        } catch (error) {
            console.error('Error creating modal:', error);
            throw error;
        }
    }

    // Create and show modal with API content
    async showModalApi(options = {}) {
        try {
            const apiConfig = { ...this.defaultApiConfig, ...options.api };
            const modalConfig = { ...this.defaultModalConfig, ...options };
            
            // Set initial loading content
            if (apiConfig.showLoader) {
                modalConfig.content = this.createLoaderHtml(apiConfig.loaderText);
            }
            
            // Create modal first
            const modalController = this.showModal(modalConfig);
            
            // Add API-specific methods to controller
            modalController.loadContent = async (url = apiConfig.url, config = {}) => {
                return await this.loadApiContent(modalController, { ...apiConfig, ...config, url });
            };
            
            modalController.refresh = async () => {
                return await this.loadApiContent(modalController, apiConfig);
            };
            
            // Load API content
            if (apiConfig.url) {
                await this.loadApiContent(modalController, apiConfig);
            }
            
            return modalController;
        } catch (error) {
            console.error('Error creating API modal:', error);
            throw error;
        }
    }

    // Load content from API
    async loadApiContent(modalController, config) {
        try {
            // Fire onLoadStart callback
            if (config.onLoadStart && typeof config.onLoadStart === 'function') {
                config.onLoadStart(modalController);
            }

            // Show loader
            if (config.showLoader) {
                modalController.updateContent(this.createLoaderHtml(config.loaderText));
            }

            // Fetch data with retries
            const response = await this.fetchWithRetry(config.url, {
                method: config.method,
                headers: config.headers,
                body: config.body ? JSON.stringify(config.body) : null,
                signal: AbortSignal.timeout(config.timeout)
            }, config.retries, config.retryDelay);

            if (!response.ok) {
                throw new Error(`HTTP Error: ${response.status} ${response.statusText}`);
            }

            // Parse response based on type
            let data;
            switch (config.responseType.toLowerCase()) {
                case 'json':
                    data = await response.json();
                    break;
                case 'text':
                case 'html':
                    data = await response.text();
                    break;
                default:
                    data = await response.text();
            }

            // Process and update content
            let content;
            if (config.responseType === 'json') {
                content = this.formatJsonContent(data);
            } else {
                content = data;
            }

            modalController.updateContent(content);

            // Fire onSuccess callback
            if (config.onSuccess && typeof config.onSuccess === 'function') {
                config.onSuccess(data, modalController);
            }

            return data;
        } catch (error) {
            console.error('Error loading API content:', error);
            
            // Show error content with refresh button
            const errorContent = this.createErrorHtml(error.message, config.showRefreshOnError, modalController);
            modalController.updateContent(errorContent);

            // Fire onError callback
            if (config.onError && typeof config.onError === 'function') {
                config.onError(error, modalController);
            }

            throw error;
        } finally {
            // Fire onLoadEnd callback
            if (config.onLoadEnd && typeof config.onLoadEnd === 'function') {
                config.onLoadEnd(modalController);
            }
        }
    }

    // Fetch with retry logic
    async fetchWithRetry(url, options, retries, delay) {
        let lastError;
        
        for (let i = 0; i <= retries; i++) {
            try {
                const response = await fetch(url, options);
                return response;
            } catch (error) {
                lastError = error;
                if (i < retries) {
                    await this.delay(delay * Math.pow(2, i)); // Exponential backoff
                }
            }
        }
        
        throw lastError;
    }

    // Delay helper
    delay(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    // Create loader HTML
    createLoaderHtml(text = 'Loading...') {
        return `
            <div class="text-center py-4">
                <div class="spinner-border text-primary mb-3" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <div class="text-muted">${text}</div>
            </div>
        `;
    }

    // Create error HTML with refresh button
    createErrorHtml(errorMessage, showRefresh = true, modalController = null) {
        const refreshButton = showRefresh && modalController ? `
            <button type="button" class="btn btn-outline-primary btn-sm mt-3" onclick="handleRefresh('${modalController.modalId}')">
                <i class="bi bi-arrow-clockwise me-1"></i> Refresh
            </button>
        ` : '';

        // Add refresh handler to global scope
        if (showRefresh && modalController) {
            window.handleRefresh = window.handleRefresh || {};
            window.handleRefresh[modalController.modalId] = async () => {
                if (modalController.refresh) {
                    try {
                        await modalController.refresh();
                    } catch (error) {
                        console.error('Error refreshing content:', error);
                    }
                }
            };
        }

        return `
            <div class="text-center py-4">
                <div class="text-danger mb-3">
                    <i class="bi bi-exclamation-triangle-fill fs-1"></i>
                </div>
                <h5 class="text-danger">Error Loading Content</h5>
                <p class="text-muted mb-3">${errorMessage}</p>
                ${refreshButton}
            </div>
        `;
    }

    // Format JSON content for display
    formatJsonContent(data) {
        try {
            if (typeof data === 'object') {
                return `<pre class="bg-light p-3 rounded"><code>${JSON.stringify(data, null, 2)}</code></pre>`;
            }
            return data.toString();
        } catch (error) {
            console.error('Error formatting JSON content:', error);
            return '<p class="text-muted">Unable to format content</p>';
        }
    }

    // Add event listeners to modal
    addModalEventListeners(modalElement, config, controller) {
        try {
            if (config.onShow) {
                modalElement.addEventListener('show.bs.modal', config.onShow);
            }
            if (config.onShown) {
                modalElement.addEventListener('shown.bs.modal', config.onShown);
            }
            if (config.onHide) {
                modalElement.addEventListener('hide.bs.modal', config.onHide);
            }
            if (config.onHidden) {
                modalElement.addEventListener('hidden.bs.modal', config.onHidden);
            }
            
            // Auto cleanup on hide
            modalElement.addEventListener('hidden.bs.modal', () => {
                try {
                    modalElement.remove();
                    this.activeModals.delete(controller.modalId);
                    
                    // Cleanup refresh handler
                    if (window.handleRefresh && window.handleRefresh[controller.modalId]) {
                        delete window.handleRefresh[controller.modalId];
                    }
                } catch (error) {
                    console.error('Error during modal cleanup:', error);
                }
            });
        } catch (error) {
            console.error('Error adding modal event listeners:', error);
        }
    }

    // Create modal HTML
    createModalHtml(modalId, titleId, contentId, config) {
        try {
            const sizeClass = config.size === 'fullscreen' ? 'modal-fullscreen' : 'modal-' + config.size;
            const centeredClass = config.centered ? 'modal-dialog-centered' : '';
            const scrollableClass = config.scrollable ? 'modal-dialog-scrollable' : '';
            const dialogClasses = [sizeClass, centeredClass, scrollableClass].filter(Boolean).join(' ');
            
            // Header HTML
            let headerHtml = '';
            if (config.showHeader) {
                const closeButton = config.showClose ? 
                    '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' : '';
                
                headerHtml = `
                    <div class="modal-header ${config.headerClass}">
                        <h5 class="modal-title" id="${titleId}">${config.title}</h5>
                        ${closeButton}
                    </div>
                `;
            }
            
            // Footer HTML
            let footerHtml = '';
            if (config.showFooter && config.footerButtons.length > 0) {
                const buttonsHtml = config.footerButtons.map(btn => {
                    const dismissAttr = btn.dismiss ? 'data-bs-dismiss="modal"' : '';
                    const onclickAttr = btn.onclick ? `onclick="${btn.onclick}"` : '';
                    const idAttr = btn.id ? `id="${btn.id}"` : '';
                    const disabledAttr = btn.disabled ? 'disabled' : '';
                    const attributes = btn.attributes || {};
                    const customAttrs = Object.entries(attributes).map(([key, value]) => `${key}="${value}"`).join(' ');
                    
                    return `<button type="button" class="${btn.class}" ${dismissAttr} ${onclickAttr} ${idAttr} ${disabledAttr} ${customAttrs}>${btn.text}</button>`;
                }).join('');
                
                footerHtml = `
                    <div class="modal-footer ${config.footerClass}">
                        ${buttonsHtml}
                    </div>
                `;
            }
            
            return `
                <div class="modal fade ${config.modalClass}" id="${modalId}" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog ${dialogClasses}" role="document">
                        <div class="modal-content">
                            ${headerHtml}
                            <div class="modal-body ${config.bodyClass}" id="${contentId}">
                                ${config.content}
                            </div>
                            ${footerHtml}
                        </div>
                    </div>
                </div>
            `;
        } catch (error) {
            console.error('Error creating modal HTML:', error);
            throw error;
        }
    }

    // Create and show offcanvas with API content
    async showOffcanvasApi(options = {}) {
        try {
            const apiConfig = { ...this.defaultApiConfig, ...options.api };
            const offcanvasConfig = { ...this.defaultOffcanvasConfig, ...options };
            
            // Set initial loading content
            if (apiConfig.showLoader) {
                offcanvasConfig.content = this.createLoaderHtml(apiConfig.loaderText);
            }
            
            // Create offcanvas first
            const offcanvasController = this.showOffcanvas(offcanvasConfig);
            
            // Add API-specific methods to controller
            offcanvasController.loadContent = async (url = apiConfig.url, config = {}) => {
                return await this.loadOffcanvasApiContent(offcanvasController, { ...apiConfig, ...config, url });
            };
            
            offcanvasController.refresh = async () => {
                return await this.loadOffcanvasApiContent(offcanvasController, apiConfig);
            };
            
            // Load API content
            if (apiConfig.url) {
                await this.loadOffcanvasApiContent(offcanvasController, apiConfig);
            }
            
            return offcanvasController;
        } catch (error) {
            console.error('Error creating API offcanvas:', error);
            throw error;
        }
    }

    // Load content from API for offcanvas
    async loadOffcanvasApiContent(offcanvasController, config) {
        try {
            // Fire onLoadStart callback
            if (config.onLoadStart && typeof config.onLoadStart === 'function') {
                config.onLoadStart(offcanvasController);
            }

            // Show loader
            if (config.showLoader) {
                offcanvasController.updateContent(this.createLoaderHtml(config.loaderText));
            }

            // Fetch data with retries
            const response = await this.fetchWithRetry(config.url, {
                method: config.method,
                headers: config.headers,
                body: config.body ? JSON.stringify(config.body) : null,
                signal: AbortSignal.timeout(config.timeout)
            }, config.retries, config.retryDelay);

            if (!response.ok) {
                throw new Error(`HTTP Error: ${response.status} ${response.statusText}`);
            }

            // Parse response based on type
            let data;
            switch (config.responseType.toLowerCase()) {
                case 'json':
                    data = await response.json();
                    break;
                case 'text':
                case 'html':
                    data = await response.text();
                    break;
                default:
                    data = await response.text();
            }

            // Process and update content
            let content;
            if (config.responseType === 'json') {
                content = this.formatJsonContent(data);
            } else {
                content = data;
            }

            offcanvasController.updateContent(content);

            // Fire onSuccess callback
            if (config.onSuccess && typeof config.onSuccess === 'function') {
                config.onSuccess(data, offcanvasController);
            }

            return data;
        } catch (error) {
            console.error('Error loading offcanvas API content:', error);
            
            // Show error content with refresh button
            const errorContent = this.createOffcanvasErrorHtml(error.message, config.showRefreshOnError, offcanvasController);
            offcanvasController.updateContent(errorContent);

            // Fire onError callback
            if (config.onError && typeof config.onError === 'function') {
                config.onError(error, offcanvasController);
            }

            throw error;
        } finally {
            // Fire onLoadEnd callback
            if (config.onLoadEnd && typeof config.onLoadEnd === 'function') {
                config.onLoadEnd(offcanvasController);
            }
        }
    }

    // Create error HTML with refresh button for offcanvas
    createOffcanvasErrorHtml(errorMessage, showRefresh = true, offcanvasController = null) {
        const refreshButton = showRefresh && offcanvasController ? `
            <button type="button" class="btn btn-outline-primary btn-sm mt-3" onclick="handleOffcanvasRefresh('${offcanvasController.offcanvasId}')">
                <i class="bi bi-arrow-clockwise me-1"></i> Refresh
            </button>
        ` : '';

        // Add refresh handler to global scope
        if (showRefresh && offcanvasController) {
            window.handleOffcanvasRefresh = window.handleOffcanvasRefresh || {};
            window.handleOffcanvasRefresh[offcanvasController.offcanvasId] = async () => {
                if (offcanvasController.refresh) {
                    try {
                        await offcanvasController.refresh();
                    } catch (error) {
                        console.error('Error refreshing offcanvas content:', error);
                    }
                }
            };
        }

        return `
            <div class="text-center py-4">
                <div class="text-danger mb-3">
                    <i class="bi bi-exclamation-triangle-fill fs-1"></i>
                </div>
                <h6 class="text-danger">Error Loading Content</h6>
                <p class="text-muted small mb-3">${errorMessage}</p>
                ${refreshButton}
            </div>
        `;
    }

    // Create and show offcanvas
    showOffcanvas(options = {}) {
        try {
            const config = { ...this.defaultOffcanvasConfig, ...options };
            
            // Generate unique IDs
            const offcanvasId = this.generateUniqueId('offcanvas');
            const titleId = this.generateUniqueId('offcanvas-title');
            const contentId = this.generateUniqueId('offcanvas-content');
            
            // Create offcanvas HTML
            const offcanvasHtml = this.createOffcanvasHtml(offcanvasId, titleId, contentId, config);
            
            // Add to DOM
            document.body.insertAdjacentHTML('beforeend', offcanvasHtml);
            
            // Get offcanvas element
            const offcanvasElement = document.getElementById(offcanvasId);
            if (!offcanvasElement) {
                throw new Error('Failed to create offcanvas element');
            }
            
            // Configure Bootstrap offcanvas options
            const bsOptions = {
                backdrop: config.backdrop,
                keyboard: config.keyboard,
                scroll: config.scroll
            };
            
            // Create Bootstrap offcanvas instance
            const offcanvas = new bootstrap.Offcanvas(offcanvasElement, bsOptions);
            
            // Create offcanvas controller object
            const offcanvasController = {
                offcanvas: offcanvas,
                element: offcanvasElement,
                offcanvasId: offcanvasId,
                titleId: titleId,
                contentId: contentId,
                config: config,
                updateTitle: (newTitle) => {
                    try {
                        const titleElement = document.getElementById(titleId);
                        if (titleElement) {
                            titleElement.textContent = newTitle;
                        }
                    } catch (error) {
                        console.error('Error updating offcanvas title:', error);
                    }
                },
                updateContent: (newContent) => {
                    try {
                        const contentElement = document.getElementById(contentId);
                        if (contentElement) {
                            contentElement.innerHTML = newContent;
                        }
                    } catch (error) {
                        console.error('Error updating offcanvas content:', error);
                    }
                },
                hide: () => {
                    try {
                        offcanvas.hide();
                    } catch (error) {
                        console.error('Error hiding offcanvas:', error);
                    }
                },
                show: () => {
                    try {
                        offcanvas.show();
                    } catch (error) {
                        console.error('Error showing offcanvas:', error);
                    }
                },
                toggle: () => {
                    try {
                        offcanvas.toggle();
                    } catch (error) {
                        console.error('Error toggling offcanvas:', error);
                    }
                },
                dispose: () => {
                    try {
                        offcanvas.dispose();
                        offcanvasElement.remove();
                        this.activeOffcanvas.delete(offcanvasId);
                    } catch (error) {
                        console.error('Error disposing offcanvas:', error);
                    }
                }
            };
            
            // Add event listeners
            this.addOffcanvasEventListeners(offcanvasElement, config, offcanvasController);
            
            // Store active offcanvas
            this.activeOffcanvas.set(offcanvasId, offcanvasController);
            
            // Show offcanvas
            offcanvas.show();
            
            return offcanvasController;
        } catch (error) {
            console.error('Error creating offcanvas:', error);
            throw error;
        }
    }

    // Add event listeners to offcanvas
    addOffcanvasEventListeners(offcanvasElement, config, controller) {
        try {
            if (config.onShow) {
                offcanvasElement.addEventListener('show.bs.offcanvas', config.onShow);
            }
            if (config.onShown) {
                offcanvasElement.addEventListener('shown.bs.offcanvas', config.onShown);
            }
            if (config.onHide) {
                offcanvasElement.addEventListener('hide.bs.offcanvas', config.onHide);
            }
            if (config.onHidden) {
                offcanvasElement.addEventListener('hidden.bs.offcanvas', config.onHidden);
            }
            
            // Auto cleanup on hide
            offcanvasElement.addEventListener('hidden.bs.offcanvas', () => {
                try {
                    offcanvasElement.remove();
                    this.activeOffcanvas.delete(controller.offcanvasId);
                    
                    // Cleanup offcanvas refresh handler
                    if (window.handleOffcanvasRefresh && window.handleOffcanvasRefresh[controller.offcanvasId]) {
                        delete window.handleOffcanvasRefresh[controller.offcanvasId];
                    }
                } catch (error) {
                    console.error('Error during offcanvas cleanup:', error);
                }
            });
        } catch (error) {
            console.error('Error adding offcanvas event listeners:', error);
        }
    }

    // Create offcanvas HTML
    createOffcanvasHtml(offcanvasId, titleId, contentId, config) {
        try {
            const positionClass = 'offcanvas-' + config.position;
            
            // Custom styles for width/height
            let customStyles = config.customStyles;
            if (config.width && (config.position === 'end' || config.position === 'start')) {
                customStyles += `width: ${config.width};`;
            }
            if (config.height && (config.position === 'top' || config.position === 'bottom')) {
                customStyles += `height: ${config.height};`;
            }
            
            const styleAttr = customStyles ? `style="${customStyles}"` : '';
            
            // Header HTML
            let headerHtml = '';
            if (config.showHeader) {
                const closeButton = config.showClose ? 
                    '<button type="button" class="btn-close text-reset" data-bs-dismiss="offcanvas" aria-label="Close"></button>' : '';
                
                headerHtml = `
                    <div class="offcanvas-header ${config.headerClass}">
                        <h5 id="${titleId}">${config.title}</h5>
                        ${closeButton}
                    </div>
                `;
            }
            
            return `
                <div class="offcanvas ${positionClass} ${config.offcanvasClass}" tabindex="-1" id="${offcanvasId}" ${styleAttr}>
                    ${headerHtml}
                    <div class="offcanvas-body ${config.bodyClass}">
                        <div id="${contentId}">
                            ${config.content}
                        </div>
                    </div>
                </div>
            `;
        } catch (error) {
            console.error('Error creating offcanvas HTML:', error);
            throw error;
        }
    }

    // Get modal by ID
    getModal(modalId) {
        try {
            return this.activeModals.get(modalId);
        } catch (error) {
            console.error('Error getting modal:', error);
            return null;
        }
    }

    // Get offcanvas by ID
    getOffcanvas(offcanvasId) {
        try {
            return this.activeOffcanvas.get(offcanvasId);
        } catch (error) {
            console.error('Error getting offcanvas:', error);
            return null;
        }
    }

    // Get all active modals
    getActiveModals() {
        try {
            return Array.from(this.activeModals.values());
        } catch (error) {
            console.error('Error getting active modals:', error);
            return [];
        }
    }

    // Get all active offcanvas
    getActiveOffcanvas() {
        try {
            return Array.from(this.activeOffcanvas.values());
        } catch (error) {
            console.error('Error getting active offcanvas:', error);
            return [];
        }
    }

    // Close all modals
    closeAllModals() {
        try {
            this.activeModals.forEach(controller => {
                controller.hide();
            });
        } catch (error) {
            console.error('Error closing all modals:', error);
        }
    }

    // Close all offcanvas
    closeAllOffcanvas() {
        try {
            this.activeOffcanvas.forEach(controller => {
                controller.hide();
            });
        } catch (error) {
            console.error('Error closing all offcanvas:', error);
        }
    }

    // Close all (modals and offcanvas)
    closeAll() {
        try {
            this.closeAllModals();
            this.closeAllOffcanvas();
        } catch (error) {
            console.error('Error closing all:', error);
        }
    }

    // Get count of active modals
    getModalCount() {
        try {
            return this.activeModals.size;
        } catch (error) {
            console.error('Error getting modal count:', error);
            return 0;
        }
    }

    // Get count of active offcanvas
    getOffcanvasCount() {
        try {
            return this.activeOffcanvas.size;
        } catch (error) {
            console.error('Error getting offcanvas count:', error);
            return 0;
        }
    }

    // Set default modal configuration
    setDefaultModalConfig(config) {
        try {
            this.defaultModalConfig = { ...this.defaultModalConfig, ...config };
        } catch (error) {
            console.error('Error setting default modal config:', error);
        }
    }

    // Set default offcanvas configuration
    setDefaultOffcanvasConfig(config) {
        try {
            this.defaultOffcanvasConfig = { ...this.defaultOffcanvasConfig, ...config };
        } catch (error) {
            console.error('Error setting default offcanvas config:', error);
        }
    }

    // Set default API configuration
    setDefaultApiConfig(config) {
        try {
            this.defaultApiConfig = { ...this.defaultApiConfig, ...config };
        } catch (error) {
            console.error('Error setting default API config:', error);
        }
    }

    // Dispose all modals and offcanvas
    disposeAll() {
        try {
            this.activeModals.forEach(controller => {
                controller.dispose();
            });
            this.activeOffcanvas.forEach(controller => {
                controller.dispose();
            });
            this.activeModals.clear();
            this.activeOffcanvas.clear();
            
            // Cleanup global refresh handlers
            if (window.handleRefresh) {
                window.handleRefresh = {};
            }
            if (window.handleOffcanvasRefresh) {
                window.handleOffcanvasRefresh = {};
            }
        } catch (error) {
            console.error('Error disposing all:', error);
        }
    }
}
