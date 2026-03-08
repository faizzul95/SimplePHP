# 23. JavaScript Helper Library

## Source: `public/general/js/helper.js` (3418 lines, 90+ functions)

## API Functions

### Token Resolution

- `_resolveToken(token)` — Resolves token: explicit string, auto-detect from localStorage/meta, or `false` to disable.

### Core API Wrappers

- `loginApi(url, formID?, token?)` — Login form submission. Handles success redirect, error display.
- `submitApi(url, dataObj, formID?, reloadFunction?, closedModal?, token?)` — Form submission. Manages button states, validation errors, modal close, table reload.
- `deleteApi(id, url, reloadFunction?, token?)` — Delete confirmation + API call. SweetAlert2 confirm dialog.
- `callApi(method, url, dataObj?, option?, token?)` — General-purpose API call. Supports GET/POST/PUT/PATCH/DELETE.
- `uploadApi(url, formID?, idProgressBar?, reloadFunction?, permissions?, token?)` — File upload with progress bar tracking.

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
- `loadFileContent(fileName, idToLoad, sizeModal, title, dataArray, typeModal)` — Load content into modal.
- `loadFormContent(fileName, idToLoad, sizeModal, urlFunc, title, dataArray, typeModal)` — Load form content with API binding.

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

- `generateDatatableServer(id, url, nodatadiv, dataObj, filterColumn, screenLoadID)` — Server-side DataTable with API pagination.
- `generateDatatableClient(id, url, dataObj, filterColumn, nodatadiv, screenLoadID)` — Client-side DataTable.

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

// Form submission with auto-modal-close and table reload
await submitApi('/api/v1/users/save', formData, 'userForm', reloadTable, true, true);

// Delete with confirmation dialog
await deleteApi(encodedId, '/api/v1/users/delete', reloadTable, true);

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

// Data tables
generateDatatableServer('userTable', '/api/v1/users/list', 'nodatadiv', null, [0, 1, 2]);
```

## How To Use

1. Include `helper.js` in your page (loaded via header template).
2. Use `callApi` for flexible requests, specific wrappers for common flows.
3. Use PHP-style array/string functions for familiar patterns.
4. Use skeleton/nodata templates while loading data.
5. Use `loadFileContent` / `loadFormContent` for modal-based forms.

## What To Avoid

- Avoid duplicate AJAX wrappers — use the built-in API functions.
- Avoid manual DOM manipulation for loading states — use `loading()`, `loadingBtn()`.
- Avoid building URLs manually — use `urls()`, `base_url()`, `asset()`.

## Benefits

- 90+ utility functions covering API calls, DOM manipulation, data processing.
- PHP-style functions for developers familiar with PHP.
- Consistent API request patterns with token management.
- Built-in DataTable integration, file preview, and export helpers.

## Evidence

- `public/general/js/helper.js` (3418 lines)
- `app/routes/api.php` (API endpoints consumed by these helpers)
