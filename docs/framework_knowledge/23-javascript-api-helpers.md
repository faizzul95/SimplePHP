# 23. JavaScript Helper Library

## Source: `public/general/js/helper.js` (3418 lines, 90+ functions)

## Runtime Ownership

This helper layer is now partly adapter-based:

- `public/general/js/helper.js` still exposes global wrappers like `callApi`, `submitApi`, `datatableManager`, and `modalManager`.
- `public/general/js/classes/BootstrapDataTable.js` owns the reusable DataTable behavior for create/reload/mutation flows.
- `public/general/js/classes/ModalManager.js` owns the shared modal/offcanvas lifecycle, overlay stacking, dynamic content loading, and standalone overlay behavior.

For new CRUD pages, treat `helper.js` as the public entrypoint and the two class files as the underlying implementation.

## API Functions

### Token Resolution

- `_resolveToken(token)` — Resolves token: explicit string, auto-detect from localStorage/meta, or `false` to disable.

### Core API Wrappers

- `loginApi(url, formID?, token?)` — Login form submission. Handles success redirect, error display.
- `submitApi(url, dataObj, formID?, reloadFunction?, closedModal?, token?)` — Form submission. Manages button states, validation errors, modal close, table reload.
- `deleteApi(id, url, reloadFunction?, token?)` — Older delete convenience wrapper. Keep for compatibility, but prefer `confirmDeleteAction()` plus `removeDatatableRow()` for new CRUD list work.
- `callApi(method, url, dataObj?, option?, token?)` — General-purpose API call. Supports GET/POST/PUT/PATCH/DELETE.
- `uploadApi(url, formID?, idProgressBar?, reloadFunction?, permissions?, token?)` — File upload with progress bar tracking.

### Confirmation Helpers

- `confirmAction(options)` — Generic SweetAlert2 confirmation helper with configurable `title`, `html`, `icon`, button labels/colors, `onConfirm`, and `onCancel`.
- `confirmApiAction(options)` — API-aware confirmation helper built on `confirmAction()`. Runs `callApi()`, handles success notification, and supports `onSuccess`, `onError`, and `onCancel`. Use this for custom API confirms that are not the standard delete path.
- `confirmDeleteAction(options)` — Preferred delete wrapper on top of `confirmApiAction()` with delete-friendly defaults.
- `confirmSubmitAction(options)` — Preferred submit wrapper on top of `confirmAction()` with submit-friendly defaults.

### Response Checkers

- `isSuccess(res)` — Check if response code is 200.
- `isError(res)` — Check if response code is >= 400.
- `isUnauthorized(res)` — Check if response code is 401/403.

### Notification

- `noti(code, text)` — Toastr notification. Green for 200, red for errors. Auto-maps common codes.
- `showToast(message, type)` — Simple toast notification.

## Debug Functions

- `log(...args)` — Console log (only when `DEBUG_MODE` is true).
- `dd(...args)` — Console log + execution stop via error throw.

## UI Utility Functions

### Button & Loading

- `loadingBtn(id, display, text)` — Toggle button loading spinner.
- `disableBtn(id, display, text)` — Toggle button disabled state.
- `loading(id, display)` — Show/hide BlockUI loading overlay.
- `printDiv(idToPrint, printBtnID, printBtnText, pageTitlePrint)` — Print a specific DOM element.

### Modal & Offcanvas

- `showModal(id, timeSet)` — Open Bootstrap modal.
- `closeModal(id, timeSet)` — Close Bootstrap modal.
- `closeOffcanvas(id, timeSet)` — Close Bootstrap offcanvas.
- `closeOverlayBySelector(id, timeSet)` — Close either a modal or offcanvas by selector.
- `loadFileContent(fileName, idToLoad, sizeModal, title, dataArray, typeModal)` — Load content into modal.
- `loadFormContent(fileName, idToLoad, sizeModal, urlFunc, title, dataArray, typeModal)` — Load form content with API binding.

### ModalManager Class Standard

`modalManager()` is the preferred entrypoint for overlay work. It returns a shared `ModalManager` instance backed by `public/general/js/classes/ModalManager.js`.

Use it for:

- shared-shell modal/offcanvas loaders through `loadFileContent()` and `loadFormContent()`
- standalone modal/offcanvas overlays through `showFileContent()` and `showFormContent()`
- Bootstrap-aware overlay stacking where newly opened overlays stay above earlier ones
- form-backed overlays that should close by selector after successful `submitApi()` calls

Preferred usage:

```javascript
modalManager().showFormContent({
	fileName: 'views/rbac/_roleForm.php',
	overlayType: 'offcanvas',
	size: '500px',
	formAction: '/roles/save',
	title: 'Update Role',
	dataArray: roleData
});
```

Operational rules:

- Prefer `modalManager()` over directly constructing `new ModalManager()` in page scripts.
- Use shared-shell loaders when you want a reused overlay container.
- Use standalone overlay methods when you want a unique modal/offcanvas instance.
- Forms loaded through the manager are expected to carry `data-modal`, which `submitApi()` uses to close the correct modal or offcanvas on success.

## Confirmation Standard

For new CRUD pages, do not inline `Swal.fire(...)` for common submit/delete flows unless the interaction is genuinely custom.

Preferred usage:

```javascript
await confirmDeleteAction({
	url: deleteUrl,
	onSuccess: function () {
		removeDatatableRow('dataList', rowKey);
	}
});

confirmSubmitAction({
	onConfirm: async function () {
		const res = await submitApi(url, form.serializeArray(), 'rolesForm');
		if (isSuccess(res.data.code ?? res)) {
			noti(res.data.code ?? res.status, res.data.message);
			syncDatatableRow('dataList', res.data.data ?? null);
		}
	}
});
```

Use `confirmApiAction()` directly when:

- the request is not a standard delete flow
- you need a custom HTTP method like `post`
- the confirm title/html/button text differs from the delete/submit defaults
- the cancel path must restore UI state

Example:

```javascript
await confirmApiAction({
	title: isChecked ? 'Grant Permission?' : 'Revoke Permission?',
	html: actionDesc,
	confirmButtonText: confirmBtnText,
	confirmButtonColor: isChecked ? '#198754' : '#d33',
	cancelButtonColor: '#6c757d',
	method: 'post',
	url: '/permissions/save-assignment',
	data: {
		role_id: roleID,
		abilities_id: abilitiesID,
		all_access: isAllAccess,
		permission: actionText,
	},
	onSuccess: async function () {
		getListPermissionAssignment();
	},
	onCancel: async function () {
		$('#ab' + abilitiesID).prop('checked', !isChecked);
	}
});
```

Operational rules:

- Prefer `confirmDeleteAction()` for delete buttons in CRUD lists.
- Prefer `confirmSubmitAction()` for standard form submit confirms in partial forms.
- Prefer `confirmApiAction()` instead of raw `Swal.fire()` when the confirm still ends in `callApi()`.
- Keep raw `confirmAction()` for non-API confirms or highly custom flows.

## Data Type Helpers

### Type Checks

- `isUndef(value)` — Check undefined.
- `isDef(value)` — Check defined (not undefined).
- `isTrue(value)` — Strict `=== true`.
- `isFalse(value)` — Strict `=== false`.
- `isObject(obj)` — Check plain object.
- `isValidArrayIndex(val)` — Check valid array index.
- `isPromise(val)` — Check Promise instance.
- `isArray(val)` — Check array.
- `isMobileJs()` — Detect mobile device via regex.
- `isNumberKey(evt)` — Validate keypress is numeric.
- `isNumeric(evt)` — Input-restricted to numbers only.
- `isDigit(str)` — Check string is digits only.

### Data Checks

- `isset(variable)` — PHP-style isset check.
- `empty(variable)` — PHP-style empty check (handles null, undefined, '', 0, [], {}).
- `trimData(text)` — Trim + collapse whitespace.
- `hasData(data, arrKey, returnData, defaultValue)` — Null-safe data access with optional key drilling.

## String Functions

- `ucfirst(string)` — Capitalize first letter.
- `capitalize(str)` — Capitalize each word.
- `uppercase(obj)` — Uppercase all values in object.

## Array Functions (PHP-style)

- `in_array(needle, data)` — PHP-style `in_array`. Supports arrays and objects.
- `array_push(data, ...elements)` — Push to array or object.
- `array_merge(...arrays)` — Merge arrays or objects.
- `array_key_exists(arrKey, data)` — Check key exists in array/object.
- `array_search(needle, haystack)` — Find key/index by value.
- `implode(separator, data)` — Join array/object values to string.
- `explode(delimiter, data)` — Split string to array.
- `remove_item_array(data, item)` — Remove item from array/object.
- `distinct(value, index, self)` — Filter callback for unique values (`arr.filter(distinct)`).
- `random(min, max)` — Random integer in range.
- `chunkData(dataArr, perChunk)` — Split array into chunks.
- `chunkDataObj(dataArr, chunk_size)` — Split object array into chunks.
- `getDataPerChunk(total, percentage)` — Calculate chunk size from total.

## Date / Time Functions

- `getCurrentTime(use12HourFormat, hideSeconds)` — Current time string.
- `getCurrentDate()` — Current date in `YYYY-MM-DD`.
- `getCurrentTimestamp()` — Current datetime `YYYY-MM-DD HH:MM:SS`.
- `getClock(format, lang, showSeconds)` — Formatted clock string.
- `showClock(id, customize)` — Live clock in DOM element.
- `date(formatted, timestamp)` — PHP-style `date()` formatting (supports Y, m, d, H, i, s, etc.).
- `formatDate(dateToFormat, format, defaultValue)` — Format date string.
- `isWeekend(date, weekendDays)` — Check if date is weekend.
- `isWeekday(date, weekendDays)` — Check if date is weekday.
- `calculateDays(date1, date2, exception)` — Count days between dates with exception dates.
- `getDatesByDay(startDate, endDate, dayOfWeek)` — Get all dates of specific weekday in range.
- `getDayIndex(dayOfWeek)` — Convert day name to index (0-6).

## Currency Functions

- `formatCurrency(value, code, includeSymbol)` — Format number as currency.
- `currencySymbol(currencyCode)` — Get currency symbol from code.

## URL / Navigation

- `base_url()` — Get base URL from meta tag.
- `urls(path)` — Build URL from base + path.
- `redirect(url)` — Navigate to URL.
- `refreshPage()` — Reload current page.
- `asset(path, isPublic)` — Build asset URL.

## Display Helpers

- `sizeToText(size, decimal)` — Convert bytes to human-readable (KB, MB, GB).
- `jsonHtmlDisplay(json, type)` — Render JSON as formatted HTML.
- `maxLengthCheck(object)` — Enforce maxlength on input elements.
- `getImageSizeBase64(base64, type)` — Calculate base64 image size.
- `getImageDefault(imageName, path)` — Get default image path.

## No Data / Skeleton Templates

- `noSelectDataLeft(text, filesName)` — "Select data" placeholder HTML.
- `nodata(text, filesName)` — "No data" placeholder HTML.
- `nodataAccess(filesName)` — "No access" placeholder HTML.
- `skeletonTableOnly(totalData)` — Skeleton loading rows.
- `skeletonTable(hasFilter, buttonRefresh)` — Full skeleton table with filter.
- `skeletonTableCard(hasFilter, buttonRefresh)` — Skeleton table in card layout.

## DataTable Generators

- `generateDatatableServer(id, url, nodatadiv, dataObj, filterColumn, screenLoadID)` — Older server-side DataTable adapter. Keep for compatibility, but prefer `datatableManager()` or `generateDatatable()` for new CRUD work.
- `generateDatatableClient(id, url, dataObj, filterColumn, nodatadiv, screenLoadID)` — Older client-side DataTable adapter. Keep for compatibility, but prefer `datatableManager()` or `generateDatatable()` for new CRUD work.

## BootstrapDataTable Standard

`BootstrapDataTable` is now the preferred DataTable abstraction for new pages. It standardizes Bootstrap 4/5-compatible table rendering, remote loading, row identity, empty-state handling, and local row mutations after save/delete flows.

Standard setup:

- Use `datatableManager('tableId', config)` or `new BootstrapDataTable(config)` instead of wiring raw DataTables directly.
- Use `rowId: 'row_key'` for stable row identity. Keep route/action identity in a separate `key` field.
- For AJAX-backed tables, define `ajax.url` and, when needed, `mutation` rules once per table.
- For form-driven pages, prefer `syncDatatableRow()` after save and `removeDatatableRow()` after delete instead of requerying the list by default.
- For server-side tables that mutate rows locally, `BootstrapDataTable` keeps the last known `recordsTotal` / `recordsFiltered` from the API response and updates the footer count locally after delete instead of forcing a list reload.

Recommended payload shape:

```json
{
	"row_key": "role-row-3",
	"key": "A1B2C3",
	"name": "ADMIN",
	"role_status_value": 1,
	"status": "<span class=\"badge bg-label-success\">Active</span>"
}
```

Recommended page pattern:

```javascript
const table = datatableManager('dataList', {
	tableId: 'dataList',
	mode: 'server',
	rowId: 'row_key',
	ajax: {
		url: '{{ route("roles.list") }}',
		method: 'POST',
		data: function() {
			return {
				role_status: $("#filter_role_status").val()
			};
		}
	},
	ui: {
		emptyStateContainerId: 'nodataDiv',
		loadingContainerId: 'bodyDiv',
		renderEmptyState: () => nodata()
	},
	mutation: {
		rowPath: null,
		shouldKeepRow: (rowData) => {
			const filterValue = $('#filter_role_status').val();
			return filterValue === '' || String(rowData.role_status_value) === String(filterValue);
		}
	}
});

await table.create();
```

Standard save/delete operations:

```javascript
confirmSubmitAction({
	onConfirm: async function () {
		const saveRes = await submitApi(url, form.serializeArray(), 'rolesForm');
		if (isSuccess(saveRes.data.code ?? saveRes)) {
			syncDatatableRow('dataList', saveRes.data.data ?? null);
		}
	}
});

await confirmDeleteAction({
	url: "{{ route('roles.delete') }}".replace('{id}', id),
	onSuccess: function () {
		removeDatatableRow('dataList', rowKey);
	}
});
```

Full supported properties:

`confirmSubmitAction(options)` forwards to `confirmAction()` and keeps these defaults unless overridden:

```javascript
confirmSubmitAction({
	title: 'Are you sure?',
	html: 'Form will be submitted!',
	icon: 'warning',
	showCancelButton: true,
	confirmButtonColor: '#3085d6',
	cancelButtonColor: '#d33',
	confirmButtonText: 'Yes, Confirm!',
	reverseButtons: true,
	customClass: {},
	onConfirm: async function (result) {},
	onCancel: async function (result) {},
	// Any extra SweetAlert2 options also pass through.
	allowOutsideClick: false,
	allowEscapeKey: true,
	focusCancel: true
});
```

`confirmDeleteAction(options)` forwards to `confirmApiAction()`, which then forwards to `confirmAction()`. These are the effective properties you can use:

```javascript
await confirmDeleteAction({
	title: 'Are you sure?',
	html: 'You won\'t be able to revert this action!<br><strong>This item will be permanently deleted.</strong>',
	icon: 'warning',
	showCancelButton: true,
	confirmButtonColor: '#3085d6',
	cancelButtonColor: '#d33',
	confirmButtonText: 'Yes, Remove it!',
	reverseButtons: true,
	customClass: {},
	method: 'delete',
	url: deleteUrl,
	data: null,
	apiOptions: {},
	token: null,
	autoNotify: true,
	onSuccess: async function (res, result) {},
	onError: async function (res, result) {},
	onCancel: async function (result) {},
	// Any extra SweetAlert2 options also pass through.
	allowOutsideClick: false,
	allowEscapeKey: true,
	focusCancel: true
});
```

Minimal-change rule to keep the standard message:

- For `confirmSubmitAction()`, only add `onConfirm` and, if needed, `onCancel`.
- For `confirmDeleteAction()`, only add `url`, `onSuccess`, and optionally `onError` or `onCancel`.
- Do not override `title`, `html`, or `confirmButtonText` unless the screen intentionally needs a custom confirmation message.

Preferred minimal-change examples:

```javascript
confirmSubmitAction({
	onConfirm: async function () {
		const saveRes = await submitApi(url, form.serializeArray(), 'rolesForm');
		if (isSuccess(saveRes.data.code ?? saveRes)) {
			syncDatatableRow('dataList', saveRes.data.data ?? null);
		}
	}
});

await confirmDeleteAction({
	url: "{{ route('roles.delete') }}".replace('{id}', id),
	onSuccess: function () {
		removeDatatableRow('dataList', rowKey);
	}
});
```

Operational rules:

- `syncDatatableRow()` updates an existing visible row locally.
- If the updated row no longer matches the active filter, it removes the row locally.
- `removeDatatableRow()` removes by stable row key without forcing a list query when the row is present.
- `removeDatatableRow()` accepts either one row key/payload or an array of row keys/payloads.
- If all visible rows are gone and `emptyStateContainerId` is configured, the no-data container is shown.
- `reloadWhenMissing` and `reloadWhenEmpty` control when the class falls back to server reloads.
- If the response already returns the mapped row object directly, set `mutation.rowPath: null` so the payload itself is treated as the updated row.
- `submitApi()` now closes modal or offcanvas overlays using the form's `data-modal` selector, so modal-backed forms keep the old close-on-success behavior.

Server-side local delete rule:

- When a visible row is removed locally from a server-side table, the class subtracts from the last known API totals and re-renders the info text locally.
- This keeps delete interactions cheap for large lists where a full reload would cost an extra server request and exact count query.
- If the row is missing locally, normal `reloadWhenMissing` fallback still applies.

### Standard CRUD List Lifecycle

Use this lifecycle as the default implementation order for new list screens:

1. Define one `getDataList(resetPaging = false)` function that owns the table configuration.
2. Configure the table with `datatableManager(tableId, tableConfig)`.
3. Use `rowId: 'row_key'` and keep route-level identity in `key`.
4. Put active filter logic in `ajax.data` and `mutation.shouldKeepRow`.
5. On add/edit, open the form through `modalManager().showFormContent(...)` or `showFileContent(...)`.
6. On save success, call `syncDatatableRow(...)` with the mapped response row.
7. On delete success, call `removeDatatableRow(...)` with the visible row key.
8. Only call `reload()` when the local row is unavailable or the screen intentionally needs a full server refresh.

### Table Configuration Standard

For maintainability, keep the full table configuration in one local `tableConfig` object inside the list loader.

Preferred shape:

```javascript
const tableConfig = {
	tableId: 'dataList',
	mode: 'server',
	rowId: 'row_key',
	ajax: {
		url: '{{ route("roles.list") }}',
		method: 'POST',
		data: function() {
			return {
				role_status: $("#filter_role_status").val()
			};
		}
	},
	columns: [
		{ data: 'name', targets: 0 },
		{ data: 'rank', width: '10%', targets: 1 },
		{ data: 'count', width: '10%', targets: 2 },
		{ data: 'status', width: '5%', targets: 3 },
		{
            data: 'action',
            render: function(data) {
                return data;
            },
            targets: -1,
            width: '3%',
            searchable: false,
            orderable: false
        }
	],
	ui: {
		emptyStateContainerId: 'nodataDiv',
		loadingContainerId: 'bodyDiv',
		useLoadingIndicator: true,
		renderEmptyState: function () {
			return nodata();
		}
	},
	mutation: {
		rowPath: null,
		shouldKeepRow: function (rowData) {
			const filterValue = $('#filter_role_status').val();
			return filterValue === '' || String(rowData.role_status_value) === String(filterValue);
		}
	}
};
```

Keep these rules:

- `rowPath: null` when the save response already returns the mapped row object directly.
- `shouldKeepRow(rowData)` when an updated row might stop matching the current filters.
- `useLoadingIndicator: true` when you want the shared loading overlay behavior.
- `renderEmptyState: () => nodata()` when the standard no-data UI should be shown.

### Save Response Standard

For frontend CRUD screens, save endpoints should return the mapped DataTable row payload directly so the list can update locally without another fetch.

Expected frontend usage:

```javascript
confirmSubmitAction({
	onConfirm: async function () {
		const res = await submitApi(url, form.serializeArray(), 'rolesForm');
		if (isSuccess(res.data.code ?? res)) {
			noti(res.data.code ?? res.status, res.data.message);
			syncDatatableRow('dataList', res.data.data ?? null);
		}
	}
});
```

Expected payload shape:

```json
{
	"row_key": "role-row-3",
	"key": "A1B2C3",
	"name": "ADMIN",
	"role_status_value": 1,
	"status": "<span class=\"badge bg-label-success\">Active</span>",
	"action": "...html..."
}
```

### Delete Standard

Delete handlers should stay small and delegate confirmation plus API handling to shared helpers.

Preferred usage:

```javascript
async function deleteRecord(id, rowKey = null) {
	await confirmDeleteAction({
		url: "{{ route('roles.delete') }}".replace('{id}', id),
		onSuccess: function () {
			removeDatatableRow('dataList', rowKey);
		}
	});
}
```

Rules:

- Use `confirmDeleteAction()` for standard destructive list deletes.
- Pass the rendered `row_key` to `removeDatatableRow()`.
- Do not force a full list reload when the visible row can be removed locally.

### Custom Confirm Standard

Use `confirmApiAction()` when the interaction is not a standard delete flow but still ends in `callApi()`.

This is the preferred pattern for actions like permission assignment, reset password, grant/revoke toggles, and other state changes that need custom confirm text or custom cancel recovery.

Preferred usage:

```javascript
await confirmApiAction({
	title: isChecked ? 'Grant Permission?' : 'Revoke Permission?',
	html: actionDesc,
	confirmButtonText: confirmBtnText,
	confirmButtonColor: isChecked ? '#198754' : '#d33',
	cancelButtonColor: '#6c757d',
	method: 'post',
	url: '/permissions/save-assignment',
	data: {
		role_id: roleID,
		abilities_id: abilitiesID,
		all_access: isAllAccess,
		permission: actionText,
	},
	onSuccess: async function () {
		getListPermissionAssignment();
	},
	onCancel: async function () {
		$('#ab' + abilitiesID).prop('checked', !isChecked);
	}
});
```

### Offcanvas And Modal Form Standard

Use `modalManager()` as the only frontend entrypoint for opening CRUD forms.

Preferred add/edit usage:

```javascript
function addRoles() {
	modalManager().showFormContent({
		fileName: 'views/rbac/_roleForm.php',
		overlayType: 'offcanvas',
		size: '500px',
		formAction: '{{ route("roles.save") }}',
		title: 'Add Roles',
		dataArray: {}
	});
}

async function editRecord(key) {
	const res = await callApi('get', "{{ route('roles.show') }}".replace('{id}', key));
	if (isSuccess(res)) {
		modalManager().showFormContent({
			fileName: 'views/rbac/_roleForm.php',
			overlayType: 'offcanvas',
			size: '500px',
			formAction: '{{ route("roles.save") }}',
			title: 'Update Roles',
			dataArray: res.data.data
		});
	}
}
```

Rules:

- Prefer `showFormContent()` for form partials.
- Prefer `showFileContent()` for read/write partials that are not driven only by `formAction`.
- Let `submitApi()` close the overlay automatically when `closedModal` remains `true`.
- Set `closedModal` to `false` only when the form is designed to stay open after save.

### Reusable Helper Priority

When adding frontend CRUD code, prefer helpers in this order:

1. `datatableManager()` / `generateDatatable()`
2. `confirmDeleteAction()`
3. `confirmSubmitAction()`
4. `confirmApiAction()` for custom API confirms
5. `callApi()` for non-confirmed API reads or custom one-off requests
6. raw library calls only when there is no shared helper that fits

### What To Avoid In New CRUD Work

- raw `Swal.fire(...)` for standard submit/delete flows
- full DataTable reloads after every save/delete when local mutation is enough
- unstable route ids as DataTable row ids
- duplicated field assignment logic spread across edit/reset/save branches
- direct `new ModalManager()` creation in page scripts
- bypassing `datatableManager()` to wire equivalent logic manually per page

### Evidence

- `public/general/js/classes/BootstrapDataTable.js`
- `public/general/js/classes/ModalManager.js`
- `public/general/js/helper.js`
- `app/views/rbac/roles.php`
- `app/views/rbac/_roleForm.php`
- `app/http/controllers/RoleController.php`

## File Preview / Export

- `previewPDF(fileLoc, fileMime, divToLoadID, modalId)` — PDF preview in container.
- `previewFiles(fileLoc, fileMime, options)` — Multi-format file preview (PDF, images, video, audio, Office docs via Google Viewer).
- `printHelper(method, url, filter, config)` — Print via API data fetch.
- `exportExcelHelper(method, url, filter, config)` — Excel export via Blob download.
- `downloadFile(url, filename)` — Direct file download.

## Fullscreen Helpers

- `toggleFullscreen(containerId)` — Toggle native fullscreen.
- `createCustomFullscreen(containerId)` — Custom fullscreen overlay.
- `exitCustomFullscreen(containerId)` — Exit custom fullscreen.
- `restoreNormalView(containerId)` — Restore normal view.
- `rotateImage(containerId)` — Rotate image in preview.
- `switchPdfViewer(containerId, viewerType)` — Switch PDF viewer type.

## Examples

### API calls

```javascript
// General API call
const result = await callApi('GET', '/api/v1/users', null, {}, true);

// Standard CRUD save confirmation
confirmSubmitAction({
	onConfirm: async function () {
		const res = await submitApi('/api/v1/users/save', formData, 'userForm', null, true, true);
		if (isSuccess(res.data.code ?? res)) {
			syncDatatableRow('dataList', res.data.data ?? null);
		}
	}
});

// Standard CRUD delete confirmation
await confirmDeleteAction({
	url: '/api/v1/users/delete/' + encodedId,
	onSuccess: function () {
		removeDatatableRow('dataList', rowKey);
	}
});

// File upload with progress
await uploadApi('/api/v1/uploads/image-cropper', 'uploadForm', 'progressBar');
```

### Utility usage

```javascript
// PHP-style checks
if (!empty(userData) && hasData(userData, 'email')) { ... }

// Date formatting
const today = getCurrentDate(); // "2024-01-15"
const formatted = formatDate('2024-01-15', 'd/m/Y'); // "15/01/2024"

// Currency
const price = formatCurrency(1500.5, 'MYR', true); // "RM 1,500.50"

// Standard CRUD table setup
datatableManager('userTable', {
	tableId: 'userTable',
	mode: 'server',
	rowId: 'row_key',
	ajax: {
		url: '/api/v1/users/list',
		method: 'POST'
	},
	mutation: {
		rowPath: null
	}
}).create();
```

## How To Use

1. Include `helper.js` in your page (loaded via header template).
2. For CRUD lists, start with `datatableManager()` and `rowId: 'row_key'`.
3. Use `confirmDeleteAction()` for standard deletes and `confirmSubmitAction()` for standard saves.
4. Use `confirmApiAction()` when the action is custom but still ends in `callApi()`.
5. Use `callApi()` for flexible requests, API reads, and one-off custom flows.
6. Use `loadFileContent` / `loadFormContent` or `modalManager()` methods for modal-based forms.
7. Use `syncDatatableRow()` and `removeDatatableRow()` instead of full list reloads whenever the visible row can be mutated locally.
8. Use PHP-style array/string functions for familiar patterns.
9. Use skeleton/nodata templates while loading data.

## What To Avoid

- Avoid duplicate AJAX wrappers — use the built-in API functions.
- Avoid manual DOM manipulation for loading states — use `loading()`, `loadingBtn()`.
- Avoid building URLs manually — use `urls()`, `base_url()`, `asset()`.
- Avoid `deleteApi()` for new CRUD list deletes when `confirmDeleteAction()` fits the flow.
- Avoid `generateDatatableServer()` / `generateDatatableClient()` for new CRUD screens when `datatableManager()` or `generateDatatable()` can express the full table config.

## Benefits

- 90+ utility functions covering API calls, DOM manipulation, data processing.
- PHP-style functions for developers familiar with PHP.
- Consistent API request patterns with token management.
- Built-in DataTable integration, file preview, and export helpers.
- Standard CRUD table workflow with local row sync/remove, empty-state handling, and overlay-aware submit close behavior.

## Evidence

- `public/general/js/helper.js` (3418 lines)
- `app/routes/api.php` (API endpoints consumed by these helpers)
