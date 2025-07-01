let localeMapCurrency = {
	USD: {
		symbol: '$',
		pattern: '$ #,##0.00',
		code: 'en-US',
		decimal: 2
	}, // United States Dollar (USD)
	JPY: {
		symbol: '¥',
		pattern: '¥ #,##0',
		code: 'ja-JP',
		decimal: 2
	}, // Japanese Yen (JPY)
	GBP: {
		symbol: '£',
		pattern: '£ #,##0.00',
		code: 'en-GB',
		decimal: 2
	}, // British Pound Sterling (GBP)
	EUR: {
		symbol: '€',
		pattern: '€ #,##0.00',
		code: 'en-GB',
		decimal: 2
	}, // Euro (EUR) - Using en-GB for Euro
	AUD: {
		symbol: 'A$',
		pattern: 'A$ #,##0.00',
		code: 'en-AU',
		decimal: 2
	}, // Australian Dollar (AUD)
	CAD: {
		symbol: 'C$',
		pattern: 'C$ #,##0.00',
		code: 'en-CA',
		decimal: 2
	}, // Canadian Dollar (CAD)
	CHF: {
		symbol: 'CHF',
		pattern: 'CHF #,##0.00',
		code: 'de-CH',
		decimal: 2
	}, // Swiss Franc (CHF)
	CNY: {
		symbol: '¥',
		pattern: '¥ #,##0.00',
		code: 'zh-CN',
		decimal: 2
	}, // Chinese Yuan (CNY)
	SEK: {
		symbol: 'kr',
		pattern: 'kr #,##0.00',
		code: 'sv-SE',
		decimal: 2
	}, // Swedish Krona (SEK)
	MYR: {
		symbol: 'RM',
		pattern: 'RM #,##0.00',
		code: 'ms-MY',
		decimal: 2
	}, // Malaysian Ringgit (MYR)
	SGD: {
		symbol: 'S$',
		pattern: 'S$ #,##0.00',
		code: 'en-SG',
		decimal: 2
	}, // Singapore Dollar (SGD)
	INR: {
		symbol: '₹',
		pattern: '₹ #,##0.00',
		code: 'en-IN',
		decimal: 2
	}, // Indian Rupee (INR)
	IDR: {
		symbol: 'Rp',
		pattern: 'Rp #,##0',
		code: 'id-ID',
		decimal: 0
	}, // Indonesian Rupiah (IDR)
};
let language = 'en';

// Global error handling function
window.showContainerError = function (display_id, message) {
	const $container = $(`#${display_id}`);
	$container.html(`
		<div class="alert alert-danger" role="alert">
			<i class="fas fa-exclamation-triangle"></i> ${message}
		</div>
	`);
};

// DEBUG HELPER

/**
 * Function: log
 * Description: This function takes in multiple arguments and logs each argument to the console.
 * It iterates through the provided arguments and uses the console.log() function to display each argument's value in the console.
 *
 * @param {...any} args - The arguments to be logged to the console.
 * 
 * @example
 * log("Hello", 42, [1, 2, 3]);
 */
const log = (...args) => {
	args.forEach((param) => {
		console.log(param);
	});
}

/**
 * Function: dd
 * Description: This function is similar to the 'log' function, but it additionally throws an error after logging the provided arguments.
 * It is typically used for debugging purposes to terminate program execution and print diagnostic information at a specific point in the code.
 *
 * @param {...any} args - The arguments to be logged to the console before terminating the execution.
 * @throws {Error} - Always throws an error with the message "Execution terminated by dd()".
 * 
 * @example
 * dd("Error occurred", { code: 500 });
 */
const dd = (...args) => {
	args.forEach((param) => {
		console.log(param);
	});
	throw new Error("Execution terminated by dd()");
}

// CUSTOM HELPER

const loadingBtn = (id, display = false, text = "<i class='ti ti-device-floppy ti-xs mb-1'></i> Save") => {
	if (display) {
		$("#" + id).html('Please wait... <span class="spinner-border spinner-border-sm align-middle ms-2"></span>');
		$("#" + id).attr('disabled', true);
	} else {
		$("#" + id).html(text);
		$("#" + id).attr('disabled', false);
	}
}

const printDiv = (idToPrint, printBtnID = 'printBtn', printBtnText = "<i class='ti ti-device-floppy ti-xs mb-1'></i> Save", pageTitlePrint = 'Print') => {
	$("#" + idToPrint).printThis({
		// header: $('#headerPrint').html(),
		// footer: $('#tablePrint').html(), 
		importCSS: false,
		pageTitle: pageTitlePrint,
		beforePrint: loadingBtn(printBtnID, true),
	});

	setTimeout(function () {
		loadingBtn(printBtnID, false, printBtnText);
		$('#' + idToPrint).empty(); // reset
	}, 800);
}

const disableBtn = (id, display = true, text = null) => {
	const button = $("#" + id);
	button.prop("disabled", display);

	if (text !== null) {
		button.html(text);
	}
}

const isNumberKey = (evt) => {
	try {
		const charCode = (evt.which) ? evt.which : evt.keyCode;
		return charCode > 31 && charCode < 48 || charCode > 57;
	} catch (error) {
		throw new Error(`An error occurred in isNumberKey(): ${error.message}`);
	}
};

const sizeToText = (size, decimal = 2) => {
	try {
		// Convert string to number if needed
		const numSize = typeof size === 'string' ? parseFloat(size) : size;
		
		if (typeof numSize !== 'number' || isNaN(numSize)) {
			throw new Error('An error occurred in sizeToText(): Invalid input - size must be a number or numeric string');
		}

		if (typeof decimal !== 'number') {
			throw new Error('Decimal must be a number.');
		}

		if (decimal < 0) {
			throw new Error('Decimal cannot be negative.');
		}

		const sizeContext = ["B", "KB", "MB", "GB", "TB"];
		let atCont = 0;

		while (size >= 1024 && atCont < sizeContext.length - 1) {
			size /= 1024;
			atCont++;
		}

		return `${(size).toFixed(decimal)} ${sizeContext[atCont]}`;

	} catch (error) {
		throw new Error(`An error occurred in sizeToText(): ${error.message}`);
	}
}

const loading = (id = null, display = false) => {
	if (display) {
		$(id).block({
			// message: '<div class="d-flex justify-content-center"> <div class="spinner-border text-light" role="status"></div> </div>',
			message: '<div class="d-flex justify-content-center"><p class="mb-0">Please wait...</p> <div class="sk-wave m-0"><div class="sk-rect sk-wave-rect"></div> <div class="sk-rect sk-wave-rect"></div> <div class="sk-rect sk-wave-rect"></div> <div class="sk-rect sk-wave-rect"></div> <div class="sk-rect sk-wave-rect"></div></div> </div>',
			css: {
				backgroundColor: 'transparent',
				color: '#fff',
				border: '0'
			},
			overlayCSS: {
				opacity: 0.15
			}
		});
	} else {
		setTimeout(function () {
			$(id).unblock();
		}, 80);
	}
}

const chunkData = (dataArr, perChunk) => {
	if (perChunk <= 0) perChunk = 15;

	let result = [];
	for (let i = 0; i < dataArr.length / perChunk; i++) {
		result.push(dataArr.slice(i * perChunk, i * perChunk + perChunk));
	}
	return result;

}

const chunkDataObj = (dataArr, chunk_size) => {
	if (chunk_size <= 0) chunk_size = 15;

	const chunks = [];
	for (const cols = Object.entries(dataArr); cols.length;)
		chunks.push(cols.splice(0, chunk_size).reduce((o, [k, v]) => (o[k] = v, o), {}));

	return chunks;

}

const getDataPerChunk = (total, percentage = 10) => {
	var percent = (percentage / 100) * total;
	return Math.round(percent);
}

// GENERAL HELPER

const ucfirst = (string) => {
	return string.charAt(0).toUpperCase() + string.slice(1);
}

const capitalize = (str) => {
	return str.toLowerCase().split(' ').map(function (word) {
		return word.replace(word[0], word[0].toUpperCase());
	}).join(' ');
}

const uppercase = (obj) => {
	obj.value = obj.value.toUpperCase();
	return obj.value;
}

const distinct = (value, index, self) => {
	return self.indexOf(value) === index;
}

const random = (min, max) => {
	Math.floor(Math.random() * (max - min)) + min;
};

const isUndef = (value) => {
	return typeof value === undefined || value === null;
}

const isDef = (value) => {
	return typeof value !== undefined && value !== null;
}

const isTrue = (value) => {
	return value === true;
}

const isFalse = (value) => {
	return value === false;
}

const isObject = (obj) => {
	return obj !== null && typeof obj === 'object';
}

const isValidArrayIndex = (val) => {
	var n = parseFloat(String(val));
	return n >= 0 && Math.floor(n) === n && isFinite(val);
}

const isPromise = (val) => {
	return (
		isDef(val) &&
		typeof val.then === 'function' &&
		typeof val.catch === 'function'
	);
}

const isArray = (val) => {
	return Array.isArray(val) ? true : false;
}

const isMobileJs = () => {
	const toMatch = [
		/Android/i,
		/webOS/i,
		/iPhone/i,
		/iPad/i,
		/iPod/i,
		/BlackBerry/i,
		/Windows Phone/i
	];

	return toMatch.some((toMatchItem) => {
		return navigator.userAgent.match(toMatchItem);
	});
}

const maxLengthCheck = (object) => {
	if (object.value.length > object.maxLength)
		object.value = object.value.slice(0, object.maxLength)
}

const isNumeric = (evt) => {
	var theEvent = evt || window.event;
	var key = theEvent.keyCode || theEvent.which;
	key = String.fromCharCode(key);
	var regex = /[0-9]|\./;
	if (!regex.test(key)) {
		theEvent.returnValue = false;
		if (theEvent.preventDefault) theEvent.preventDefault();
	}
}

const isDigit = (str) => {
	var regex = /[0-9]|\./;
	return regex.test(str);
}

/**
 * Function: jsonHtmlDisplay
 * Description: Converts a JSON object or string into HTML-highlighted syntax.
 *
 * @param {string | object} json - The JSON object or string to be highlighted.
 * @param {string} [type='basic'] - The type of highlighting ('basic' or 'bullet').
 * @returns {string} - HTML-formatted string with syntax highlighting for JSON.
 *
 * @example
 * const highlightedJson = jsonHtmlDisplay('{"key": "value"}', 'basic');
 * // highlightedJson is an HTML-formatted string with syntax highlighting for JSON.
 */
const jsonHtmlDisplay = (json, type = 'basic') => {
	if (type === 'bullet') {
		// Convert JSON string to a JavaScript object
		const obj = JSON.parse(json);

		// Recursive function to create HTML for each element
		const createHtml = (element) => {
			let html = '';
			if (typeof element === 'object' && element !== null) {
				html += '<ul>';
				for (const key in element) {
					html += `<li><span class="key">${key}:</span>${createHtml(element[key])}</li>`;
				}
				html += '</ul>';
			} else if (typeof element === 'string') {
				html += `<span class="string">"${element}"</span>`;
			} else if (typeof element === 'number') {
				html += `<span class="number">${element}</span>`;
			} else if (typeof element === 'boolean') {
				html += `<span class="boolean">${element}</span>`;
			} else if (element === null) {
				html += '<span class="null">null</span>';
			}
			return html;
		};

		// Generate HTML and wrap it in a <pre> element
		const html = `<pre>${createHtml(obj)}</pre>`;
		return html;
	} else {
		json = JSON.stringify(JSON.parse(json), null, 2);
		json = json.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
		const html = json.replace(
			/("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(-?\d+(\.\d*)?(e-?\d+)?|null|true|false)\b)/g,
			(match) => {
				let cls = 'number';
				if (/^"/.test(match)) {
					if (/:$/.test(match)) {
						cls = 'key';
					} else {
						cls = 'string';
					}
				} else if (/true|false/.test(match)) {
					cls = 'boolean';
				} else if (/null/.test(match)) {
					cls = 'null';
				}
				return `<span class="${cls}">${match}</span>`;
			}
		);

		return `<pre>${html}</pre>`;
	}
};

// URL & ASSET HELPER

const base_url = () => {
	return $('meta[name="base_url"]').attr('content');
}

const urls = (path) => {
	const newPath = new URL(path, base_url());
	return newPath.href;
}

const redirect = (url) => {
	const pathUrl = base_url() + url;
	window.location.replace(pathUrl);
	// window.location.href = pathUrl;
}

const refreshPage = () => {
	location.reload();
}

const asset = (path, isPublic = true) => {
	const publicFolder = isPublic ? 'public/' : '';
	return urls(publicFolder + path);
}

// MODAL (BOOTSTRAP) HELPER

const showModal = (id, timeSet = 0) => {
	setTimeout(function () {
		$(id).modal('show');
	}, timeSet);
}

const closeModal = (id, timeSet = 250) => {
	setTimeout(function () {
		$(id).modal('hide');
	}, timeSet);
}

const closeOffcanvas = (id, timeSet = 250) => {
	setTimeout(function () {
		$(id).offcanvas('toggle');
	}, timeSet);
}

// DATA HELPER

/**
 * Function: isset
 * Description: Checks if a variable is defined and not null.
 *
 * @param {*} variable - The variable to be checked.
 * @returns {boolean} - True if the variable is defined and not null, false otherwise.
 * 
 * @example
 * const result = isset(myVar);
 * if (result) {
 *   // myVar is defined and not null
 * } else {
 *   // myVar is undefined or null
 * }
 */
const isset = (variable) => {
	return typeof variable != 'undefined' && variable != null;
};

/**
 * Function: trimData
 * Description: Trims leading and trailing whitespace from a given string if it's defined, otherwise returns original text.
 *
 * @param {*} text - The text to be potentially trimmed.
 * @returns {string | *} - Returns the trimmed string or the original value if input is not a string.
 * 
 * @example
 * const trimmedText = trimData("   Some text   "); // trimmedText now contains "Some text"
 * const nullResult = trimData(null); // nullResult is null
 * const numberResult = trimData(6); // numberResult return as is
 */
const trimData = (text = null) => {
	return typeof text === 'string' ? text.trim() : text;
};

/**
 * Function: hasData
 * Description: Check if data exists and optionally if a nested key exists within the data.
 *
 * @param {any} data - The data to be checked.
 * @param {string} arrKey - A dot-separated string representing the nested keys to check within the data.
 * @param {boolean} returnData - If true, return the data instead of a boolean.
 * @param {any} defaultValue - The value to return if the data or nested key is not found.
 * @returns {boolean | any} - Returns a boolean indicating data existence or the actual data based on `returnData` parameter.
 */
const hasData = (data = null, arrKey = null, returnData = false, defaultValue = null) => {
	// Base case 1: Check if data is not set, empty, or null
	if (!data || data === null) {
		return returnData ? (defaultValue ?? data) : false;
	}

	// Base case 2: If arrKey is not provided, consider data itself as having data
	if (arrKey === null) {
		return returnData ? (defaultValue ?? data) : true;
	}

	// Replace square brackets with dots in arrKey
	arrKey = arrKey.replace(/\[/g, '.').replace(/\]/g, '');

	// Split the keys into an array
	const keys = arrKey.split('.');

	// Helper function to recursively traverse the data
	const traverse = (keys, currentData) => {
		if (keys.length === 0) {
			return returnData ? currentData : true;
		}

		const key = keys.shift();

		// Check if currentData is an object or an array
		if (currentData && typeof currentData === 'object' && key in currentData) {
			return traverse(keys, currentData[key]);
		} else {
			// If the key doesn't exist, return the default value or false
			return returnData ? defaultValue : false;
		}
	};

	return traverse(keys, data);
};

/**
 * Function: empty
 * Description: Replicates PHP's empty() function - checks if a variable is empty or not set.
 * @param {*} variable - The variable to be checked.
 * @returns {boolean} - Returns true if the variable is empty or not set, false otherwise.
 * @example
 * const result = empty(myVar);
 * if (result) {
 *     // myVar is empty or not set
 * } else {
 *     // myVar has data
 * }
 */
const empty = (variable) => {
    // Handle undefined and null
    if (variable === undefined || variable === null) {
        return true;
    }
    
    // Handle strings
    if (typeof variable === 'string' && variable === '') {
        return true;
    }
    
    // Handle numbers (0 and NaN are considered empty)
    if (typeof variable === 'number' && (variable === 0 || isNaN(variable))) {
        return true;
    }
    
    // Handle string '0'
    if (variable === '0') {
        return true;
    }
    
    // Handle boolean false
    if (variable === false) {
        return true;
    }
    
    // Handle arrays (empty arrays are considered empty)
    if (Array.isArray(variable) && variable.length === 0) {
        return true;
    }
    
    // Handle objects (empty objects are considered empty in PHP context)
    if (typeof variable === 'object' && variable !== null && !Array.isArray(variable) && Object.keys(variable).length === 0) {
        return true;
    }
    
    return false;
};

// ARRAY HELPER

/**
 * Function: in_array
 * Description: Checks if a given value exists in the provided array.
 *
 * @param {*} needle - The value to search for in the array.
 * @param {Array} data - The array to search within.
 * @returns {boolean} - True if the value exists in the array, false otherwise.
 * 
 * @example
 * const result = in_array(42, [1, 42, 3]); // result is true
 * const result2 = in_array(45, [1, 42, 3]); // result is false
 */
const in_array = (needle, data) => {
	try {
		if (!Array.isArray(data)) {
			throw new Error("An error occurred in in_array(): data should be an array");
		}

		return data.includes(needle);
	} catch (error) {
		console.error(`An error occurred in in_array(): ${error.message}`);
		return false;
	}
}

/**
 * Function: array_push
 * Description: Adds one or more elements to the end of an array and returns the new length of the array.
 *
 * @param {Array} data - The array to which elements will be added.
 * @param {...*} elements - Elements to be added to the array.
 * @returns {number} - The new length of the array.
 * 
 * @example
 * const myArray = [1, 2];
 * const newLength = array_push(myArray, 3, 4); // myArray is now [1, 2, 3, 4], newLength is 4
 */
const array_push = (data, ...elements) => {
	try {
		if (!Array.isArray(data)) {
			throw new Error("An error occurred in array_push(): data should be an array");
		}

		return data.push(...elements);
	} catch (error) {
		console.error(`An error occurred in array_push(): ${error.message}`);
		return [];
	}
}

/**
 * Function: array_merge
 * Description: Merges multiple arrays into a single array.
 *
 * @param {...Array} arrays - Arrays to be merged.
 * @returns {Array} - The merged array.
 * 
 * @example
 * const mergedArray = array_merge([1, 2], [3, 4], [5, 6]); // mergedArray is [1, 2, 3, 4, 5, 6]
 */
const array_merge = (...arrays) => {
	try {
		for (const array of arrays) {
			if (!Array.isArray(array)) {
				throw new Error("All arguments should be arrays");
			}
		}

		return [].concat(...arrays);
	} catch (error) {
		console.error(`An error occurred in array_merge(): ${error.message}`);
		return [];
	}
}

/**
 * Function: array_key_exists
 * Description: Checks if a specified key exists in an object.
 *
 * @param {*} arrKey - The key to check for existence in the object.
 * @param {Object} data - The object to check for the key's existence.
 * @returns {boolean} - True if the key exists in the object, false otherwise.
 * @throws {Error} - Throws an error if data is not an object.
 * 
 * @example
 * const obj = { name: 'John', age: 30 };
 * const result = array_key_exists('name', obj);
 * // result is true
 */
const array_key_exists = (arrKey, data) => {
	try {

		if (typeof data !== 'object' || data === null) {
			throw new Error("An error occurred in array_key_exists(): data should be an object");
		}

		if (data.hasOwnProperty(arrKey)) {
			return true;
		}

		return false;
	} catch (error) {
		console.error(`An error occurred in array_key_exists(): ${error.message}`);
		return false;
	}
}

/**
 * Function: array_search
 * Description: Searches for a value in an array and returns the corresponding key if found.
 *
 * @param {*} needle - The value to search for in the array.
 * @param {Array} haystack - The array to search in.
 * 
 * @throws Will throw an error if the needle is empty or if the haystack is not an array.
 *
 * @return {number|string|false} - The key of the found element or false if not found.
 *
 * @example
 * const arr = ['apple', 'banana', 'orange'];
 * const result = array_search('banana', arr);
 * // result is 1
 */
const array_search = (needle, haystack) => {
	try {
		if (!Array.isArray(haystack)) {
			throw new Error('The second parameter must be an array.');
		}

		if (needle === '') {
			throw new Error('The search value cannot be empty.');
		}

		for (const [key, value] of Object.entries(haystack)) {
			if (value === needle) {
				return key;
			}
		}

		return false;
	} catch (error) {
		console.error(`An error occurred in array_search(): ${error.message}`);
		return false;
	}
};

/**
 * Function: implode
 * Description: Joins elements of an array into a string using a specified separator.
 *
 * @param {string} separator - The separator string used between array elements.
 * @param {Array} data - The array whose elements will be joined.
 * @returns {string} - The joined string.
 * 
 * @example
 * const result = implode(', ', ['apple', 'banana', 'orange']); // result is "apple, banana, orange"
 */
const implode = (separator = ',', data) => {
	try {
		if (data !== null && !Array.isArray(data)) {
			throw new Error(`An error occurred in implode(): data should be an array`);
		}

		return data.join(separator);
	} catch (error) {
		console.error(`An error occurred in implode(): ${error.message}`);
		return '';
	}
}

/**
 * Function: explode
 * Description: Splits a string into an array of substrings based on a specified delimiter.
 *
 * @param {string} delimiter - The delimiter to use for splitting the string.
 * @param {string} data - The string to be split.
 * @returns {Array} - An array of substrings.
 * 
 * @example
 * const result = explode(' ', 'Hello world'); // result is ["Hello", "world"]
 */
const explode = (delimiter = ',', data) => {
	try {
		if (typeof data !== 'string') {
			throw new Error("An error occurred in explode(): data should be a string");
		}

		return data.split(delimiter);
	} catch (error) {
		console.error(`An error occurred in explode(): ${error.message}`);
		return [];
	}
}

/**
 * Function: remove_item_array
 * Description: Removes a specified item from an array if it exists.
 *
 * @param {Array} data - The array from which the item will be removed.
 * @param {*} item - The item to be removed from the array.
 * @returns {*} - The removed item, or undefined if the item doesn't exist in the array.
 * 
 * @example
 * const myArray = [1, 2, 3, 4];
 * const removedItem = remove_item_array(myArray, 2); // myArray is now [1, 3, 4], removedItem is 2
 */
const remove_item_array = (data, item) => {
	if (!Array.isArray(data)) {
		throw new Error("An error occurred in remove_item_array(): data should be an array");
	}

	const index = data.indexOf(item);
	if (index > -1) {
		try {
			return data.splice(index, 1)[0];
		} catch (error) {
			throw new Error(`An error occurred in remove_item_array(): ${error.message}`);
		}
	}

	return undefined;
};

// DATE & TIME HELPER

/**
 * Function: getCurrentTime
 * Description: Gets the current time in the specified format.
 *
 * @param {boolean} use12HourFormat - Optional. If true, the time will be in 12-hour format (AM/PM).
 *                                    If false or not provided, the time will be in 24-hour format.
 * @param {boolean} hideSeconds - Optional. If true, the seconds portion will be hidden.
 * @returns {string} The current time in the specified format.
 *
 * @example
 * const result24 = getCurrentTime();                    // result is like "14:30:45"
 * const result12 = getCurrentTime(true);                // result is like "02:30:45 PM"
 * const result12NoSeconds = getCurrentTime(true, true); // result is like "02:30 PM"
 */
const getCurrentTime = (use12HourFormat = false, hideSeconds = false) => {
	try {
		const today = new Date();
		let hh = today.getHours();
		const mm = today.getMinutes().toString().padStart(2, '0');
		let ss = '';

		if (!hideSeconds) {
			ss = `:${today.getSeconds().toString().padStart(2, '0')}`;
		}

		let timeFormat = "24-hour";

		if (use12HourFormat) {
			timeFormat = "12-hour";
			const period = hh >= 12 ? "PM" : "AM";
			hh = hh % 12 || 12; // Convert 0 to 12 for 12-hour format
			return `${hh}:${mm}${ss} ${period}`;
		}

		hh = hh.toString().padStart(2, '0');
		return `${hh}:${mm}${ss}`;
	} catch (error) {
		console.error(`An error occurred in getCurrentTime(): ${error.message}`);
		return "00:00:00";
	}
};

/**
 * Function: getCurrentDate
 * Description: Gets the current date in YYYY-MM-DD format.
 *
 * @returns {string} - The current date.
 * 
 * @example
 * const result = getCurrentDate(); // result is like "2023-08-17"
 */
const getCurrentDate = () => {
	try {
		const today = new Date();
		const dd = today.getDate().toString().padStart(2, '0');
		const mm = (today.getMonth() + 1).toString().padStart(2, '0'); // January is 0 so need to add 1
		const yyyy = today.getFullYear();
		return `${yyyy}-${mm}-${dd}`;
	} catch (error) {
		console.error(`An error occurred in getCurrentDate(): ${error.message}`);
		return "1970-01-01";
	}
}

/**
 * Function: getCurrentTimestamp
 * Description: Gets the current timestamp in the format "YYYY-MM-DD HH:MM:SS".
 *
 * @returns {string} The current timestamp in the format "YYYY-MM-DD HH:MM:SS".
 *
 * @example
 * const timestamp = getCurrentTimestamp(); // Returns something like "2023-08-17 14:30:45"
 */
const getCurrentTimestamp = () => {
	try {
		const now = new Date();
		const yyyy = now.getFullYear();
		const mm = (now.getMonth() + 1).toString().padStart(2, '0'); // January is 0 so need to add 1
		const dd = now.getDate().toString().padStart(2, '0');
		const hh = now.getHours().toString().padStart(2, '0');
		const min = now.getMinutes().toString().padStart(2, '0');
		const ss = now.getSeconds().toString().padStart(2, '0');

		return `${yyyy}-${mm}-${dd} ${hh}:${min}:${ss}`;
	} catch (error) {
		console.error(`An error occurred in getCurrentTimestamp(): ${error.message}`);
		return "1970-01-01 00:00:00"; // Return default value in case of error
	}
};

/**
 * Function: getClock
 * Description: Returns a formatted current time along with the day name and date in the specified language.
 *
 * @param {string} format - The time format, either '12' (12-hour) or '24' (24-hour). Default is '24'.
 * @param {string} lang - The language code, either 'en' (English), 'my' (Malay), or 'id' (Indonesian). Default is 'en'.
 * @param {boolean} showSeconds - Whether to include seconds in the formatted time string. Default is true.
 * 
 * @return {string} - The formatted time string.
 * 
 * @example
 * // const time = getClock('24', 'en', true); // Returns a 24-hour time string with seconds in English.
 */
const getClock = (format = '24', lang = 'en', showSeconds = true) => {
	try {
		// Define day names in English, Malay, and Indonesian
		const dayNames = {
			en: ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
			my: ['Ahad', 'Isnin', 'Selasa', 'Rabu', 'Khamis', 'Jumaat', 'Sabtu'],
			id: ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu']
		};

		// Validate the format parameter
		if (format !== '12' && format !== '24') {
			throw new Error("An error occurred in getClock(): Invalid format parameter. Use '12' or '24'.");
		}

		// Validate the lang parameter
		if (!dayNames[lang]) {
			throw new Error("An error occurred in getClock(): Invalid lang parameter. Use 'en', 'my', or 'id'.");
		}

		// Get the current date and time
		const currentTime = new Date();
		const currentDayIndex = currentTime.getDay(); // Get the day index (0-6)

		// Get the appropriate day name based on the current day index and language
		const dayName = dayNames[lang][currentDayIndex];

		// Get hours, minutes, and seconds
		let hours = currentTime.getHours();
		const minutes = currentTime.getMinutes();
		const seconds = currentTime.getSeconds();

		// Convert to 12-hour format and determine AM/PM if format is '12'
		let ampm = '';
		if (format === '12') {
			ampm = hours >= 12 ? 'PM' : 'AM';
			hours = hours % 12 || 12; // Convert 0 to 12
		}

		// Add leading zeros to hours, minutes, and seconds if necessary
		hours = hours < 10 ? '0' + hours : hours;
		const formattedMinutes = minutes < 10 ? '0' + minutes : minutes;
		const formattedSeconds = seconds < 10 ? '0' + seconds : seconds;

		// Build the time string
		let createTime = `${hours}:${formattedMinutes}`;

		if (showSeconds) {
			createTime += `:${formattedSeconds}`;
		}

		// Build the formatted time string
		let displayTime = format === '24'
			? createTime
			: `${createTime} ${ampm}`;

		return `${dayName}, ${displayTime}`;
	} catch (error) {
		console.error(`An error occurred in getClock(): ${error.message}`);
		return ''; // Return an empty string in case of an error
	}
};

/**
 * Function: showClock
 * Description: Displays and updates a clock in a specified HTML element every second.
 * The clock shows the current day, time, and date.
 *
 * @param {string} id - The ID of the HTML element where the clock will be displayed
 * @param {Object|null} customize - Optional customization object for clock display
 * @param {string} [customize.timeFormat='24'] - Time format ('12' or '24')
 * @param {string} [customize.lang='en'] - Language for day names ('en', 'my', or 'id')
 * @param {boolean} [customize.showSeconds=true] - Whether to show seconds
 * @param {boolean} [customize.showDate=true] - Whether to show date
 * @param {string} [customize.dateFormat='d/m/Y'] - Date format string
 * @param {string} [customize.separator=' | '] - Separator between time and date
 * 
 * @example
 * // Basic usage with default settings
 * showClock('clock-div');
 * 
 * // Custom settings
 * showClock('clock-div', {
 *   timeFormat: '12',
 *   lang: 'en',
 *   showSeconds: true,
 *   dateFormat: 'Y-m-d',
 *   separator: ' - '
 * });
 */
const showClock = (id, customize = null) => {
    // Validate input ID
    const element = document.getElementById(id);
    if (!element) {
        console.error(`Element with ID '${id}' not found`);
        return;
    }

    // Default configuration
    const config = {
        timeFormat: '24',
        lang: 'en',
        showSeconds: true,
        showDate: true,
        dateFormat: 'd/m/Y',
        separator: ' | ',
        ...customize // Spread operator to override defaults with custom settings
    };

    // Function to update the clock
    const updateClock = () => {
        try {
            // Get the clock and date strings using existing functions
            const clockStr = getClock(
                config.timeFormat,
                config.lang,
                config.showSeconds
            );
            const dateStr = config.showDate ? config.separator + date(config.dateFormat) : '';

            // Combine clock and date with separator
            const displayStr = `${clockStr}${dateStr}`;

            // Update the element
            element.textContent = displayStr;
        } catch (error) {
            console.error(`Error updating clock: ${error.message}`);
            element.textContent = 'Clock Error';
        }
    };

    // Initial update
    updateClock();

    // Set up the interval to update every second
    const timerId = setInterval(updateClock, 1000);

    // Store the timer ID on the element for cleanup if needed
    element.dataset.clockTimerId = timerId;

    // Return a cleanup function
    return () => {
        clearInterval(timerId);
        delete element.dataset.clockTimerId;
    };
};

/**
 * Function: date
 * Description: Formats a date based on the provided format string.
 *
 * @param {string} format - The format string specifying how the date should be formatted.
 * @param {string | number | Date} [timestamp=null] - The timestamp to format. Defaults to the current date and time.
 * @returns {string} The formatted date string.
 * 
 * @example
 * const formattedDate = date("Y-m-d"); - Return current date. e.g : 2024-02-29
 * const formattedDate2 = date("d.M/Y"); - Return current date. e.g : 29-Feb/2024
 * const formattedDate3 = date("d.m.Y, l"); - Return current date. e.g : 29.02.2024, Thursday
 * 
 * @throws {Error} Throws an error if there is an issue during date formatting.
 */

/**
 * Function: date
 * Description: Returns the current date and time formatted according to the specified format.
 *
 * @param {string} - formatted (optional) The format string used to format the date and time. If not provided, the function will use the default format.
 * @param {string | number | Date} [timestamp=null] - The timestamp to format. Defaults to the current date and time.
 * 
 * @return {string} Returns a formatted date string.
 * 
 * @example
 * const date1 = date('Y-m-d H:i:s'); // Outputs something like "2024-02-01 15:30:00"
 * const date2 = date('l, F j, Y');   // Outputs something like "Wednesday, February 1, 2024"
 * 
 * @throws {Error} Throws an error if there is an issue during date formatting.
 */
const date = (formatted = null, timestamp = null) => {
	try {
		const format = formatted === null ? 'Y-m-d' : formatted;

		// Convert the timestamp to a Date object if it is provided
		const currentDate = timestamp === null ? new Date() : (timestamp instanceof Date ? timestamp : new Date(timestamp));

		// Get various date components
		const year = currentDate.getFullYear().toString();
		const month = (currentDate.getMonth() + 1).toString().padStart(2, '0');
		const day = currentDate.getDate().toString().padStart(2, '0');
		const hours24 = currentDate.getHours().toString().padStart(2, '0');
		const hours12 = ((hours24 % 12) || 12).toString().padStart(2, '0');
		const minutes = currentDate.getMinutes().toString().padStart(2, '0');
		const seconds = currentDate.getSeconds().toString().padStart(2, '0');
		const ampm = hours24 >= 12 ? 'PM' : 'AM';

		// Define arrays for days of the week and months
		const daysOfWeek = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
		const months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

		// Replace placeholders in the format string
		return format.replace(/[a-zA-Z]/g, (match) => {
			switch (match) {
				case 'd': return day; // Day of the month, two digits with leading zeros (01 to 31)
				case 'D': return daysOfWeek[currentDate.getDay()].slice(0, 3); // A textual representation of a day, three letters (Mon through Sun)
				case 'j': return currentDate.getDate().toString(); // Day of the month without leading zeros (1 to 31)
				case 'l': return daysOfWeek[currentDate.getDay()]; // A full textual representation of the day of the week (Sunday through Saturday)
				case 'F': return months[currentDate.getMonth()]; // A full textual representation of a month (January through December)
				case 'm': return month; // Numeric representation of a month, with leading zeros (01 to 12)
				case 'M': return months[currentDate.getMonth()].slice(0, 3); // A short textual representation of a month, three letters (Jan through Dec)
				case 'n': return (currentDate.getMonth() + 1).toString(); // Numeric representation of a month, without leading zeros (1 to 12)
				case 'Y': return year; //  A four-digit representation of a year (e.g., 2024)
				case 'y': return year.slice(-2); // A two-digit representation of a year (e.g., 24)
				case 'H': return hours24; // 24-hour format of an hour with leading zeros (00 to 23)
				case 'h': return hours12; // 12-hour format of an hour with leading zeros (01 to 12)
				case 'i': return minutes; // Minutes with leading zeros (00 to 59)
				case 's': return seconds; // Seconds with leading zeros (00 to 59)
				case 'a': return ampm.toLowerCase(); // Lowercase Ante meridiem and Post meridiem (am or pm)
				case 'A': return ampm; // Uppercase Ante meridiem and Post meridiem (AM or PM)
				default: return match;
			}
		});

	} catch (error) {
		console.error(`An error occurred in date() while formatting date: ${error.message}`);
		return ''; // Return an empty string in case of an error
	}
};

/**
 * Function: formatDate
 * Description: Format a date with a specified format (default is 'd.m.Y').
 *
 * @param {string} dateToFormat - The date to be formatted.
 * @param {string} format - The format string for the date (default is 'd.m.Y').
 * @param {*} defaultValue - The default value to return if the date is empty.
 * @returns {string} Formatted date string or defaultValue if date is empty.
 */
const formatDate = (dateToFormat, format = 'd.m.Y', defaultValue = null) => {
	return hasData(dateToFormat) ? date(format, dateToFormat) : defaultValue;
};

/**
 * Function: isWeekend
 * Description: Checks if the given date falls on a weekend based on the specified weekend days.
 *
 * @param {Date|string} date - The date to check. Defaults to the current date if not provided.
 * @param {string[]} weekendDays - An optional array specifying weekend days ('SUN', 'MON', ..., 'SAT').
 * @returns {boolean} - Returns true if the date is a weekend, otherwise false.
 * 
 * @example
 * const result = isWeekend(new Date(2023, 8, 17)); // result is false
 * const result2 = isWeekend('2023-08-17'); // result is false
 * const customWeekendResult = isWeekend(new Date(2023, 8, 17), ['FRI', 'SAT']); // result is true, as Friday is considered a weekend day
 * const customWeekendResult2 = isWeekend('2023-08-17', ['FRI', 'SAT']); // result is true, as Friday is considered a weekend day
 */
const isWeekend = (date = new Date(), weekendDays = ['SUN', 'SAT']) => {
	try {
		const dateData = typeof date === 'string' ? new Date(date) : date;

		if (!(dateData instanceof Date) || isNaN(dateData)) {
			throw new Error("Invalid date input");
		}

		if (!Array.isArray(weekendDays) || weekendDays.some(day => typeof day !== 'string')) {
			throw new Error("Invalid weekendDays input");
		}

		const dayAbbreviation = ['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'];
		const day = dayAbbreviation[dateData.getDay()].toUpperCase();

		return weekendDays.map(d => d.toUpperCase()).includes(day);
	} catch (error) {
		console.error(`An error occurred in isWeekend(): ${error.message}`);
		return false;
	}
};

/**
 * Function: isWeekday
 * Description: Checks if the given date is a weekday (Monday to Friday).
 *
 * @param {Date} date - The date to be checked. Default is the current date.
 * @param {string[]} weekendDays - An optional array specifying weekend days ('SUN', 'MON', ..., 'SAT').
 * @returns {boolean} True if the date is a weekday, otherwise false.
 *
 * @example
 * const result = isWeekday(new Date('2023-08-19')); // Returns true if '2023-08-19' is a weekday.
 * const result2 = isWeekday('2023-08-19'); // Returns true if '2023-08-19' is a weekday.
 * const customWeekendResult = isWeekday('2023-08-19', ['FRI', 'SAT']); // Returns false if '2023-08-19' is a Friday.
 */
const isWeekday = (date = new Date(), weekendDays = ['SUN', 'SAT']) => {
	try {
		const dateData = typeof date === 'string' ? new Date(date) : date;

		if (!(dateData instanceof Date) || isNaN(dateData)) {
			throw new Error("Invalid date input");
		}

		if (!Array.isArray(weekendDays) || weekendDays.some(day => typeof day !== 'string')) {
			throw new Error("Invalid weekendDays input");
		}

		const dayAbbreviation = ['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'];
		const day = dayAbbreviation[dateData.getDay()].toUpperCase();

		return !weekendDays.map(d => d.toUpperCase()).includes(day);
	} catch (error) {
		console.error(`An error occurred in isWeekday(): ${error.message}`);
		return false;
	}
};

/**
 * Function: calculateDays
 * Description: Calculate days between two date strings or date objects, excluding specified dates or days.
 *
 * @param {Date|string} date1 - The first date (as a Date object or date string).
 * @param {Date|string} date2 - The second date (as a Date object or date string).
 * @param {Array} exception - An array of dates (as Date objects or date strings) or day names (e.g., 'MON', 'TUE').
 * @returns {number} Count of the days between the two dates after excluding specified dates or days.
 *
 * @example
 * const result = calculateDays('2022-01-10', '2023-04-21', ['FRI', 'SAT']);
 * const result2 = calculateDays('2022-01-10', '2023-04-21', ['2022-11-10', '2022-11-23', 'FRI']);
 * // Returns the number of days between the two dates excluding Fridays and Saturdays.
 */
const calculateDays = (date1, date2, exception = []) => {
	try {
		// Convert date strings to Date objects
		const date1Obj = typeof date1 === 'string' ? new Date(date1) : date1;
		const date2Obj = typeof date2 === 'string' ? new Date(date2) : date2;

		// Check if both parameters are valid dates
		if (!(date1Obj instanceof Date) || isNaN(date1Obj) || !(date2Obj instanceof Date) || isNaN(date2Obj)) {
			throw new Error("Invalid date input");
		}

		// Check if the dates are the same
		if (date1Obj.getTime() === date2Obj.getTime()) {
			return 0; // Dates are the same, 0 days difference
		}

		// Determine the maximum and minimum dates
		const maxDate = date1Obj > date2Obj ? date1Obj : date2Obj;
		const minDate = date1Obj > date2Obj ? date2Obj : date1Obj;

		// Calculate the difference in days
		const timeDifference = maxDate.getTime() - minDate.getTime();
		let daysDifference = Math.floor(timeDifference / (1000 * 3600 * 24));

		// Remove specified dates or days
		exception.forEach(excludeItem => {
			if (excludeItem instanceof Date || !isNaN(new Date(excludeItem))) {
				// Exclude specific dates
				const excludeDate = new Date(excludeItem);
				if (excludeDate >= minDate && excludeDate <= maxDate) {
					daysDifference--;
				}
			} else if (typeof excludeItem === 'string') {
				const excludedDays = getDatesByDay(minDate, maxDate, excludeItem.toUpperCase().substring(0, 3));
				daysDifference -= excludedDays.length;
			}
		});

		return daysDifference;
	} catch (error) {
		console.error(`An error occurred in calculateDays(): ${error.message}`);
		return false;
	}
}

/**
 * Function: getDatesByDay
 * Description: Get dates within a specific date range that match the specified day of the week.
 *
 * @param {Date|string} startDate - The start date (as a Date object or date string).
 * @param {Date|string} endDate - The end date (as a Date object or date string).
 * @param {string} dayOfWeek - The day of the week to match (e.g., 'MON', 'TUE').
 * @returns {Array} Array of dates (in 'Y-m-d' format) matching the specified day of the week within the date range.
 *
 * @example
 * const result = getDatesByDay('2024-01-01', '2024-01-31', 'TUE');
 * // Returns an array of all Tuesdays between January 1, 2024, and January 31, 2024.
 */
const getDatesByDay = (startDate, endDate, dayOfWeek) => {
	try {
		const result = [];

		// Convert date strings to Date objects
		const startDateObj = typeof startDate === 'string' ? new Date(startDate) : startDate;
		const endDateObj = typeof endDate === 'string' ? new Date(endDate) : endDate;

		// Check if both parameters are valid dates
		if (!(startDateObj instanceof Date) || isNaN(startDateObj) || !(endDateObj instanceof Date) || isNaN(endDateObj)) {
			throw new Error("Invalid date input");
		}

		// Determine the maximum and minimum dates
		const maxDate = startDateObj > endDateObj ? startDateObj : endDateObj;
		const minDate = startDateObj > endDateObj ? endDateObj : startDateObj;

		// Find the first occurrence of the specified day of the week within the date range
		let currentDate = new Date(minDate);
		while (currentDate <= maxDate) {
			if (currentDate.getDay() === getDayIndex(dayOfWeek)) {
				result.push(formatDate(currentDate, 'Y-m-d'));
			}
			currentDate.setDate(currentDate.getDate() + 1); // Move to the next day
		}

		return result;
	} catch (error) {
		console.error(`An error occurred in getDatesByDay(): ${error.message}`);
		return false;
	}
};

/**
 * Function: getDayIndex
 * Description: Get the index of the specified day of the week (0 for Sunday, 1 for Monday, etc.).
 *
 * @param {string} dayOfWeek - The day of the week (case-insensitive, abbreviated to three letters).
 * @returns {number} The index of the specified day of the week.
 * 
 * @example
 * const index = getDayIndex('Mon'); // Returns 1
 * const index2 = getDayIndex('saturday'); // Returns 6
 */
const getDayIndex = (dayOfWeek) => {
	const days = ['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'];
	const upperCaseDay = dayOfWeek.toUpperCase().substring(0, 3);
	return days.indexOf(upperCaseDay);
};

// CURRENCY HELPER

/**
 * Function: formatCurrency
 * Description: This function formats a numerical value as currency, based on the provided country code and options.
 *
 * @param {number} value - The numerical value to format as currency.
 * @param {string|null} code - The country code for the currency (e.g., "USD" for US Dollar). If null, the default locale is used.
 * @param {boolean} includeSymbol - A boolean indicating whether to include the currency symbol in the formatted output.
 *
 * @returns {string} - The formatted currency value as a string.
 */
const formatCurrency = (value, code = null, includeSymbol = false) => {
	// Check if the "Intl" object is available in the browser
	if (typeof Intl === 'undefined' || typeof Intl.NumberFormat === 'undefined') {
		return 'Error: The "Intl" object is not available in this browser, which is required for number formatting.';
	}

	if (!localeMapCurrency.hasOwnProperty(code)) {
		return 'Error: Invalid country code.';
	}

	// Validate the includeSymbol parameter
	if (typeof includeSymbol !== 'boolean') {
		return 'Error: includeSymbol parameter must be a boolean value.';
	}

	const currencyData = localeMapCurrency[code];

	const formatter = new Intl.NumberFormat(currencyData.code, {
		style: 'decimal',
		useGrouping: true,
		minimumFractionDigits: currencyData.decimal,
		maximumFractionDigits: currencyData.decimal,
	});

	if (includeSymbol) {
		const symbolFormatter = new Intl.NumberFormat(currencyData.code, {
			style: 'currency',
			currency: code,
			minimumFractionDigits: currencyData.decimal,
			maximumFractionDigits: currencyData.decimal,
		});
		return symbolFormatter.format(value);
	}

	return formatter.format(value);
};

/**
 * Function: currencySymbol
 * Description: Retrieves the currency symbol associated with a given currency code.
 * 
 * @param {string|null} currencyCode - The currency code for which to retrieve the symbol.
 *                                    If not provided or invalid, an error message is returned.
 * @returns {string} The currency symbol corresponding to the provided currency code,
 *                   or an error message if the code is invalid.
 */
const currencySymbol = (currencyCode = null) => {
	if (!localeMapCurrency.hasOwnProperty(currencyCode)) {
		return 'Error: Invalid country code.';
	}

	return localeMapCurrency[currencyCode]['symbol'];
};

// API CALLBACK HELPER 

const loginApi = async (url, formID = null) => {
	const submitBtnText = $('#loginBtn').html();

	var btnSubmitIDs = $('#' + formID + ' button[type=submit]').attr("id");
	var inputSubmitIDs = $('#' + formID + ' input[type=submit]').attr("id");
	var submitIdBtn = isDef(btnSubmitIDs) ? btnSubmitIDs : isDef(inputSubmitIDs) ? inputSubmitIDs : null;

	loadingBtn(submitIdBtn, true, submitBtnText);

	url = urls(url);
	try {
		var frm = $('#' + formID);
		const dataArr = new FormData(frm[0]);

		return axios({
			method: 'POST',
			headers: {
				'X-Requested-With': 'XMLHttpRequest',
				'content-type': 'application/x-www-form-urlencoded',
			},
			url: url,
			data: dataArr
		})
			.then(result => {
				loadingBtn(submitIdBtn, false, submitBtnText);
				return result;
			})
			.catch(error => {

				log('ERROR 1 LOGIN');
				let textMessage = isset(error.response.data.message) ? error.response.data.message : error.response.statusText;

				if (isError(error.response.status)) {
					noti(error.response.status, textMessage);
				} else if (isUnauthorized(error.response.status)) {
					noti(error.response.status, "Unauthorized: Access is denied");
				}

				loadingBtn(submitIdBtn, false, submitBtnText);

				return error.response;

			});
	} catch (e) {
		const res = e.response;
		log(res, 'ERROR 2 LOGIN');

		loadingBtn(submitIdBtn, false, submitBtnText);

		if (isUnauthorized(res.status)) {
			noti(res.status, "Unauthorized: Access is denied");
		} else {
			if (isError(res.status)) {
				var error_count = 0;
				for (var error in res.data.errors) {
					if (error_count == 0) {
						noti(res.status, res.data.errors[error][0]);
					}
					error_count++;
				}
			} else {
				noti(res.status, 'Something went wrong');
			}
			return res;
		}
	}

	loadingBtn(submitIdBtn, false, submitBtnText);

}

const submitApi = async (url, dataObj, formID = null, reloadFunction = null, closedModal = true) => {
	const submitBtnText = $('#submitBtn').html();

	var btnSubmitIDs = $('#' + formID + ' button[type=submit]').attr("id");
	var inputSubmitIDs = $('#' + formID + ' input[type=submit]').attr("id");
	var submitIdBtn = isDef(btnSubmitIDs) ? btnSubmitIDs : isDef(inputSubmitIDs) ? inputSubmitIDs : null;

	loadingBtn(submitIdBtn, true, submitBtnText);

	if (dataObj != null) {
		url = urls(url);

		try {
			var frm = $('#' + formID);
			const dataArr = new FormData(frm[0]);

			return axios({
				method: 'POST',
				headers: {
					'X-Requested-With': 'XMLHttpRequest',
					'content-type': 'application/x-www-form-urlencoded',
				},
				url: url,
				data: dataArr
			})
				.then(result => {

					if (isSuccess(result.status) && reloadFunction != null) {
						reloadFunction();
					}

					if (formID != null) {
						if (closedModal) {
							var modalID = $('#' + formID).attr('data-modal');
							setTimeout(function () {
								if (modalID == '#generaloffcanvas-right') {
									$(modalID).offcanvas('toggle');
								} else {
									// $('#' + modalID).modal('hide');
									$(modalID).modal('hide');
								}
							}, 350);
						}
					}

					loadingBtn(submitIdBtn, false, submitBtnText);
					return result;

				})
				.catch(error => {

					log('ERROR SubmitApi 1');
					loadingBtn(submitIdBtn, false, submitBtnText);

					let textMessage = isset(error.response.data.message) ? error.response.data.message : error.response.statusText;

					if (isError(error.response.status)) {
						noti(error.response.status, textMessage);
					} else if (isUnauthorized(error.response.status)) {
						noti(error.response.status, "Unauthorized: Access is denied");
					} else {
						log(error, 'Response Submit Api 1');
					}

					return error.response;

				});
		} catch (e) {
			const res = e.response;
			log(res, 'ERROR 2 Submit');

			loadingBtn(submitIdBtn, false);

			if (isUnauthorized(res.status)) {
				noti(res.status, "Unauthorized: Access is denied");
			} else {
				if (isError(res.status)) {
					var error_count = 0;
					for (var error in res.data.errors) {
						if (error_count == 0) {
							noti(res.status, res.data.errors[error][0]);
						}
						error_count++;
					}
				} else {
					noti(res.status, 'Something went wrong');
				}
				return res;
			}
		}
	} else {
		noti(400, "No data to insert!");
		loadingBtn('submitBtn', false);
	}
}

const deleteApi = async (id, url, reloadFunction = null) => {
	if (id != '') {
		url = urls(url + '/' + id);
		try {
			return axios({
				method: 'DELETE',
				headers: {
					'X-Requested-With': 'XMLHttpRequest',
					'content-type': 'application/x-www-form-urlencoded',
				},
				url: url,
			})
				.then(result => {
					if (isSuccess(result.status) && reloadFunction != null) {
						reloadFunction();
					}
					noti(result.status, 'Remove');
					return result;
				})
				.catch(error => {

					log('ERROR DeleteApi 1');
					let textMessage = isset(error.response.data.message) ? error.response.data.message : error.response.statusText;

					if (isError(error.response.status)) {
						noti(error.response.status, textMessage);
					} else if (isUnauthorized(error.response.status)) {
						noti(error.response.status, "Unauthorized: Access is denied");
					} else {
						log(error, 'Response Delete Api 1');
					}

					return error.response;

				});
		} catch (e) {
			const res = e.response;
			log(e, 'Response Delete Api 2');

			if (isUnauthorized(res.status)) {
				noti(res.status, "Unauthorized: Access is denied");
			} else {
				if (isError(res.status)) {
					var error_count = 0;
					for (var error in res.data.errors) {
						if (error_count == 0) {
							noti(res.status, res.data.errors[error][0]);
						}
						error_count++;
					}
				} else {
					noti(500, 'Something went wrong');
				}
				return res;
			}
		}
	} else {
		noti(400);
	}
}

const callApi = async (method = 'POST', url, dataObj = null, option = {}) => {
	url = urls(url);
	let dataSent = null;

	if (method == 'post' || method == 'put') {
		dataSent = new URLSearchParams(dataObj);
	}

	try {
		return axios({
			method: method,
			headers: {
				'X-Requested-With': 'XMLHttpRequest',
				'content-type': 'application/x-www-form-urlencoded',
			},
			url: url,
			data: dataSent,
		},
			option
		).then(result => {
			return result;
		})
			.catch(error => {
				log('ERROR CallApi 1');
				let textMessage = isset(error.response.data.message) ? error.response.data.message : error.response.statusText;

				if (isError(error.response.status)) {
					noti(error.response.status, textMessage);
				} else if (isUnauthorized(error.response.status)) {
					noti(error.response.status, "Unauthorized: Access is denied");
				} else {
					log(error, 'ERROR CallApi 1');
				}

				return error.response;
			});
	} catch (e) {
		log('ERROR CallApi 2');
		const res = e.response;
		if (isUnauthorized(res.status)) {
			noti(res.status, "Unauthorized: Access is denied");
		} else {
			if (isError(res.status)) {
				// var error_count = 0;
				// for (var error in res.data.errors) {
				// 	if (error_count == 0) {
				// 		noti(500, res.data.errors[error][0]);
				// 	}
				// 	error_count++;
				// }
				noti(res.response.status, res.response.data.message);
			} else {
				noti(500, 'Something went wrong');
			}
			return res;
		}
	}
}

const uploadApi = async (url, formID = null, idProgressBar = null, reloadFunction = null, permissions = null) => {
	try {
		url = urls(url);
		var frm = $('#' + formID);
		const dataArr = new FormData(frm[0]);

		var timeStarted = new Date().getTime();

		let axiosConfig = {
			headers: {
				'X-Requested-With': 'XMLHttpRequest',
				'content-type': 'multipart/form-data',
				'X-Permission': permissions,
			},
			onUploadProgress: function (progressEvent) {
				if (idProgressBar != null) {
					const percentCompleted = Math.round((progressEvent.loaded * 100) / progressEvent.total);

					$('#' + idProgressBar).html(`
						<div class="col-12 mt-2 progress">
						<div id="componentProgressBarCanthink" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
						</div>
						<div class="col-12 mt-2 mb-4">
						<div id="componentProgressBarStatusCanthink"></div>
						</div>
					`);

					$('#componentProgressBarCanthink').width(percentCompleted + '%');

					const disSize = sizeToText(progressEvent.total);
					const progress = progressEvent.loaded / progressEvent.total;
					const timeSpent = new Date().getTime() - timeStarted;
					const secondsRemaining = Math.round(((timeSpent / progress) - timeSpent) / 1000);

					let time;
					if (secondsRemaining >= 3600) {
						time = `${Math.floor(secondsRemaining / 3600)} hour ${Math.floor((secondsRemaining % 3600) / 60)} minute`;
					} else if (secondsRemaining >= 60) {
						time = `${Math.floor(secondsRemaining / 60)} minute ${secondsRemaining % 60} second`;
					} else {
						time = `${secondsRemaining} second(s)`;
					}

					$('#componentProgressBarStatusCanthink').html(`${sizeToText(progressEvent.loaded)} of ${disSize} | ${percentCompleted}% uploading <br> estimated time remaining: ${time}`);

					if (percentCompleted == 100) {
						$("#componentProgressBarCanthink").addClass("bg-success").removeClass("bg-info");
						setTimeout(function () {
							$('#componentProgressBarCanthink').width('0%');
							$('#componentProgressBarStatusCanthink').empty();
							$('#' + idProgressBar).empty();
						}, 500);
					} else if (percentCompleted > 0 && percentCompleted <= 40) {
						$("#componentProgressBarCanthink").addClass("bg-danger");
					} else if (percentCompleted > 40 && percentCompleted <= 60) {
						$("#componentProgressBarCanthink").addClass("bg-warning").removeClass("bg-danger");
					} else if (percentCompleted > 60 && percentCompleted <= 99) {
						$("#componentProgressBarCanthink").addClass("bg-info").removeClass("bg-warning");
					}
				}
			}
		};

		return axios.post(url, dataArr, axiosConfig)
			.then(function (res) {

				if (reloadFunction != null) {
					reloadFunction();
				}

				return res;
			})
			.catch(function (error) {
				if (error.response) {
					// Request made and server responded
					let textMessage = isset(error.response.data.message) ? error.response.data.message : error.response.statusText;

					if (isError(error.response.status)) {
						noti(error.response.status, textMessage);
					} else if (isUnauthorized(error.response.status)) {
						noti(error.response.status, "Unauthorized: Access is denied");
					} else {
						log(error, 'ERROR CallApi 1');
					}
				} else if (error.request) {
					// The request was made but no response was received
					noti(400, 'Something went wrong');
				} else {
					// Something happened in setting up the request that triggered an Error
					log(error.message, 'ERROR Upload Api');
					noti(400, 'Something went wrong');
				}

				// throw err;
			});

	} catch (e) {

		const res = e.response;
		log(e, 'ERROR Upload Api');
		log(res.status, 'ERROR Upload Api status');
		log(res.message, 'ERROR Upload Api message');

		if (isUnauthorized(res.status)) {
			noti(res.status, "Unauthorized: Access is denied");
		} else {
			noti(res.status, 'Something went wrong');
		}
	}
}

const noti = (code = 400, text = 'Something went wrong') => {

	const apiStatus = {
		200: 'OK',
		201: 'Created', // POST/PUT resulted in a new resource, MUST include Location header
		202: 'Accepted', // request accepted for processing but not yet completed, might be disallowed later
		204: 'No Content', // DELETE/PUT fulfilled, MUST NOT include message-body
		301: 'Moved Permanently', // The URL of the requested resource has been changed permanently
		304: 'Not Modified', // If-Modified-Since, MUST include Date header
		400: 'Bad Request', // malformed syntax
		401: 'Unauthorized', // Indicates that the request requires user authentication information. The client MAY repeat the request with a suitable Authorization header field
		403: 'Forbidden', // unauthorized
		404: 'Not Found', // request URI does not exist
		405: 'Method Not Allowed', // HTTP method unavailable for URI, MUST include Allow header
		415: 'Unsupported Media Type', // unacceptable request payload format for resource and/or method
		426: 'Upgrade Required',
		429: 'Too Many Requests',
		451: 'Unavailable For Legal Reasons', // REDACTED
		500: 'Internal Server Error', // all other errors
		501: 'Not Implemented', // (currently) unsupported request method
		503: 'Service Unavailable' // The server is not ready to handle the request.
	};

	var resCode = typeof code === 'number' ? code : code.status;
	var textResponse = apiStatus[code];

	var messageText = isSuccess(resCode) ? ucfirst(text) + ' successfully' : isUnauthorized(resCode) ? 'Unauthorized: Access is denied' : isError(resCode) ? text : 'Something went wrong';
	var type = isSuccess(code) ? 'success' : 'error';
	var title = isSuccess(code) ? 'Great!' : 'Ops!';

	toastr.options = {
		"debug": false,
		"closeButton": !isMobileJs(),
		"newestOnTop": true,
		"progressBar": !isMobileJs(),
		"positionClass": !isMobileJs() ? "toast-top-right" : "toast-bottom-full-width",
		"preventDuplicates": isMobileJs(),
		"onclick": null,
		"showDuration": "300",
		"hideDuration": "1000",
		"timeOut": "5000",
		"extendedTimeOut": "1000",
		"showEasing": "swing",
		"hideEasing": "linear",
		"showMethod": "fadeIn",
		"hideMethod": "fadeOut"
	}

	Command: toastr[type](messageText, title)
}

const isSuccess = (res) => {
	const successStatus = [200, 201, 302];
	const status = typeof res === 'number' ? res : res.status;
	return successStatus.includes(status);
}

const isError = (res) => {
	const errorStatus = [400, 404, 422, 429, 500];
	const status = typeof res === 'number' ? res : res.status;
	return errorStatus.includes(status);
}

const isUnauthorized = (res) => {
	const unauthorizedStatus = [401, 403];
	const status = typeof res === 'number' ? res : res.status;
	return unauthorizedStatus.includes(status);
}

//  BASE64-ENCODING HELPER

const getImageSizeBase64 = (base64, type = 'b') => {

	var decodedData = atob(base64.split(',')[1]);
	var dataSizeInBytes = decodedData.length;
	var dataSizeInKB = (dataSizeInBytes / 1024).toFixed(2);
	var dataSizeInMB = (dataSizeInKB / 1024).toFixed(2);

	if (type == 'b' || type == 'B')
		return dataSizeInBytes;
	else if (type == 'kb' || type == 'KB')
		return dataSizeInKB;
	else if (type == 'mb' || type == 'MB')
		return dataSizeInMB;
}

// PROJECT BASED HELPER

const noSelectDataLeft = (text = 'Type', filesName = '5.png') => {

	var fileImage = $('meta[name="base_url"]').attr('content') + 'public/general/images/nodata/' + filesName;

	return "<div id='nodataSelect' class='col-lg-12 mb-4 mt-2'>\
            <center>\
                <img src='" + fileImage + "' class='img-fluid mb-3' width='38%'>\
                <h3 style='letter-spacing :2px; font-family: Quicksand, sans-serif !important;margin-bottom:15px'> \
                	<strong> NO " + text.toUpperCase() + " SELECTED </strong>\
                </h3>\
				<h6 style='letter-spacing :2px; font-family: Quicksand, sans-serif !important;font-size: 13px;'> \
					Select any " + text + " on the left\
				</h6>\
			</center>\
            </div>";
}

const nodata = (text = true, filesName = '4.png') => {

	var fileImage = $('meta[name="base_url"]').attr('content') + 'public/general/images/nodata/' + filesName;
	var showText = (text) ? '' : 'style="display:none"';
	var suggestion = (text) ? '' : '"display:none!important"';

	return "<div id='nodata' class='col-lg-12 mb-4 mt-2'>\
            <center>\
                <img src='" + fileImage + "' class='img-fluid mb-3' width='38%'>\
                <h3 style='letter-spacing :2px; font-family: Quicksand, sans-serif !important;margin-bottom:15px'> \
                <strong> NO INFORMATION FOUND </strong>\
                </h3>\
                <h6 style='letter-spacing :2px; font-family: Quicksand, sans-serif !important;font-size: 13px;" + suggestion + "'> \
                    Here are some action suggestions for you to try :- \
                </h6>\
            </center>\
            <div class='row d-flex justify-content-center w-100' " + showText + ">\
                <div class='col-lg m-1 text-left' style='max-width: 350px !important;letter-spacing :1px; font-family: Quicksand, sans-serif !important;font-size: 12px;'>\
                    1. Try the registrar function (if any).<br>\
                    2. Change your word or search selection.<br>\
                    3. Contact the system support immediately.<br>\
                </div>\
            </div>\
            </div>";
}

const nodataAccess = (filesName = '403.png') => {

	var fileImage = $('meta[name="base_url"]').attr('content') + 'public/general/images/nodata/' + filesName;
	return "<div id='nodataAccess' class='col-lg-12 mb-4 mt-2'>\
            <center>\
                <img src='" + fileImage + "' class='img-fluid mb-3' width='30%'>\
                <h3 style='letter-spacing :2px; font-family: Quicksand, sans-serif !important;margin-bottom:15px'> \
                <strong> NO INFORMATION FOUND </strong>\
                </h3>\
            </center>\
            </div>";
}

const skeletonTableOnly = (totalData = 3) => {

	let body = '';
	for (let index = 0; index < totalData; index++) {
		body += '<tr>\
					<td width="5%" class="skeleton"> </td>\
					<td width="31%" class="skeleton"> </td>\
					<td width="25%" class="skeleton"> </td>\
					<td width="25%" class="skeleton"> </td>\
					<td width="14%" class="skeleton"> </td>\
				</tr>';
	}

	return '<div class="col-xl-12 mt-2">\
				<button type="button" class="btn btn-default btn-sm skeleton">  &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; </button>\
				<button type="button" class="btn btn-default btn-sm float-end skeleton mb-3">\
					&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;\
				</button>\
				<table class="table">\
					<tbody>' + body + '</tbody>\
				</table>\
				<button type="button" class="btn btn-default btn-sm float-end skeleton">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</button>\
				<button type="button" class="btn btn-default btn-sm me-1 float-end skeleton">&nbsp;&nbsp;</button>\
				<button type="button" class="btn btn-default btn-sm me-1 float-end skeleton">&nbsp;&nbsp;</button>\
				<button type="button" class="btn btn-default btn-sm me-1 float-end skeleton">&nbsp;&nbsp;</button>\
				<button type="button" class="btn btn-default btn-sm me-1 float-end skeleton">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</button>\
			</div>';
}

const skeletonTable = (hasFilter = null, buttonRefresh = true) => {

	let totalData = 3;
	let body = '';

	for (let index = 0; index < totalData; index++) {
		body += '<tr>\
					<td width="5%" class="skeleton"> </td>\
					<td width="31%" class="skeleton"> </td>\
					<td width="25%" class="skeleton"> </td>\
					<td width="25%" class="skeleton"> </td>\
					<td width="14%" class="skeleton"> </td>\
				</tr>';
	}

	let filters = '';
	if (hasData(hasFilter)) {
		for (let index = 0; index < hasFilter; index++) {
			filters += '<select class="form-control form-control-sm float-end me-2 skeleton" style="width: 12%!important;"></select>';
		}
	}

	let buttonShow = buttonRefresh ? '<div class="col-xl-12 mb-4">\
										<button type="button" class="btn btn-default btn-sm float-end skeleton">  &nbsp;&nbsp;&nbsp; </button>\
										<button type="button" class="btn btn-default btn-sm float-end me-2 skeleton">\
											&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;\
										</button>\
										' + filters + '\
										</div><br><br><br>' : '';

	return buttonShow + '<div class="col-xl-12 mt-2">\
				<button type="button" class="btn btn-default btn-sm skeleton">  &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; </button>\
				<button type="button" class="btn btn-default btn-sm float-end skeleton mb-3">\
					&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;\
				</button>\
				<table class="table">\
					<tbody>' + body + '</tbody>\
				</table>\
			</div>';
}

const skeletonTableCard = (hasFilter = null, buttonRefresh = true) => {

	let totalData = random(5, 20);
	let body = '';

	for (let index = 0; index < totalData; index++) {
		body += '<tr>\
					<td width="5%" class="skeleton"> </td>\
					<td width="31%" class="skeleton"> </td>\
					<td width="25%" class="skeleton"> </td>\
					<td width="25%" class="skeleton"> </td>\
					<td width="14%" class="skeleton"> </td>\
				</tr>';
	}

	let filters = '';
	if (hasData(hasFilter)) {
		for (let index = 0; index < hasFilter; index++) {
			filters += '<select class="form-control form-control-sm float-end me-2 skeleton" style="width: 12%!important;"></select>';
		}
	}

	let buttonShow = buttonRefresh ? '<div class="col-xl-12 mb-4">\
										<button type="button" class="btn btn-default btn-sm float-end skeleton">  &nbsp;&nbsp;&nbsp; </button>\
										<button type="button" class="btn btn-default btn-sm float-end me-2 skeleton">\
											&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;\
										</button>\
										' + filters + '\
										</div><br><br>' : '';

	return '<div class="row mt-2">\
				<div class="col-md-12 col-lg-12">\
					<div class="card" id="bodyDiv">\
						<div class="card-body">\
							' + buttonShow + '\
							<div class="col-xl-12 mt-2">\
								<table class="table table-bordered">\
									<tbody>' + body + '</tbody>\
								</table>\
							</div>\
						</div>\
					</div>\
				</div>\
			</div>';
}

const getImageDefault = (imageName, path = 'public/upload/default/') => {
	return urls(path + imageName);
}

const generateDatatableServer = (id, url = null, nodatadiv = 'nodatadiv', dataObj = null, filterColumn = [], screenLoadID = null) => {

	const tableID = $('#' + id);
	var table = tableID.DataTable().clear().destroy();

	$.ajaxSetup({
		data: {

		}
	});

	if (dataObj != null) {
		dataSent = dataObj;
	} else {
		dataSent = null;
	}

	if (screenLoadID != null) {
		loading('#' + screenLoadID, true);
	}

	if (dataSent == null) {
		return tableID.DataTable({
			// "pagingType": "full_numbers",
			"processing": true,
			"serverSide": true,
			"responsive": true,
			"iDisplayLength": 10,
			"bLengthChange": true,
			"searching": true,
			"autoWidth": false,
			"ajax": {
				type: 'POST',
				url: $('meta[name="base_url"]').attr('content') + url,
				dataType: "JSON",
				// data: dataSent,
				headers: {
					'X-Requested-With': 'XMLHttpRequest',
					'content-type': 'application/x-www-form-urlencoded',
				},
				"error": function (xhr, error, exception) {
					if (exception) {
						if (isError(xhr.status))
							noti(xhr.status, exception);
					}
				}
			},
			"language": {
				"searchPlaceholder": 'Search...',
				"sSearch": '',
				// "lengthMenu": '_MENU_ item / page',
				// "paginate": {
				// 	"first": "First",
				// 	"last": "The End",
				// 	"previous": "Previous",
				// 	"next": "Next"
				// },
				// "info": "Showing _START_ to _END_ of _TOTAL_ items",
				// "emptyTable": "No data is available in the table",
				// "info": "Showing _START_ to _END_ of _TOTAL_ items",
				// "infoEmpty": "Showing 0 to 0 of 0 items",
				// "infoFiltered": "(filtered from _MAX_ number of items)",
				// "zeroRecords": "No matching records",
				// "processing": "<span class='text-danger font-weight-bold font-italic'> Processing ... Please wait a moment.. ",
				// "loadingRecords": "Loading...",
				// "infoPostFix": "",
				// "thousands": ",",
			},
			"columnDefs": filterColumn,
			initComplete: function () {

				var totalData = this.api().data().length;

				if (totalData > 0) {
					$('#' + nodatadiv).hide();
					$('#' + id + 'Div').show();
				} else {
					tableID.DataTable().clear().destroy();
					$('#' + id + 'Div').hide();
					$('#' + nodatadiv).show();
				}

				if (screenLoadID != null) {
					setTimeout(function () {
						loading('#' + screenLoadID, false);
					}, 100);
				}
			}
		});
	} else {
		return tableID.DataTable({
			// "pagingType": "full_numbers",
			"processing": true,
			"serverSide": true,
			"responsive": true,
			"iDisplayLength": 10,
			"bLengthChange": true,
			"searching": true,
			"ajax": {
				type: 'POST',
				url: $('meta[name="base_url"]').attr('content') + url,
				dataType: "JSON",
				data: dataSent,
				headers: {
					'X-Requested-With': 'XMLHttpRequest',
					'content-type': 'application/x-www-form-urlencoded',
				},
				"error": function (xhr, error, exception) {
					if (exception) {
						if (isError(xhr.status))
							noti(xhr.status, exception);
					}
				}
			},
			"language": {
				"searchPlaceholder": 'Search...',
				"sSearch": '',
				// "lengthMenu": '_MENU_ item / page',
				// "paginate": {
				// 	"first": "First",
				// 	"last": "The End",
				// 	"previous": "Previous",
				// 	"next": "Next"
				// },
				// "info": "Showing _START_ to _END_ of _TOTAL_ items",
				// "emptyTable": "No data is available in the table",
				// "info": "Showing _START_ to _END_ of _TOTAL_ items",
				// "infoEmpty": "Showing 0 to 0 of 0 items",
				// "infoFiltered": "(filtered from _MAX_ number of items)",
				// "zeroRecords": "No matching records",
				"processing": "<span class='text-danger font-weight-bold font-italic'> Processing ... Please wait a moment.. ",
				"loadingRecords": "Loading...",
				// "infoPostFix": "",
				// "thousands": ",",
			},
			"columnDefs": filterColumn,
			initComplete: function () {

				var totalData = this.api().data().length;

				if (totalData > 0) {
					$('#' + nodatadiv).hide();
					$('#' + id + 'Div').show();
				} else {
					tableID.DataTable().clear().destroy();
					$('#' + id + 'Div').hide();
					$('#' + nodatadiv).show();
				}

				if (screenLoadID != null) {
					setTimeout(function () {
						loading('#' + screenLoadID, false);
					}, 100);
				}

			}
		});
	}
}

const generateDatatableClient = async (id, url = null, dataObj = null, filterColumn = [], nodatadiv = 'nodatadiv', screenLoadID = 'nodata') => {

	const tableID = $('#' + id);
	var table = tableID.DataTable().clear().destroy();

	$.ajaxSetup({
		data: {

		}
	});

	loading('#' + screenLoadID, true);

	const res = await callApi('post', url, dataObj);

	if (isSuccess(res)) {
		if (hasData(res.data)) {
			table = tableID.DataTable({
				"data": res.data,
				"deferRender": true,
				"processing": true,
				"serverSide": false,
				'paging': true,
				'ordering': true,
				'info': true,
				'responsive': true,
				'iDisplayLength': 10,
				'bLengthChange': true,
				'searching': true,
				'autoWidth': false,
				'language': {
					"searchPlaceholder": 'Search...',
					"sSearch": '',
					// "lengthMenu": '_MENU_ item / page',
					// "paginate": {
					// 	"first": "First",
					// 	"last": "The End",
					// 	"previous": "Previous",
					// 	"next": "Next"
					// },
					// "info": "Showing _START_ to _END_ of _TOTAL_ items",
					// "emptyTable": "No data is available in the table",
					// "info": "Showing _START_ to  _END_ of  _TOTAL_ items",
					// "infoEmpty": "Showing 0 to 0 of 0 items",
					// "infoFiltered": "(filtered from _MAX_ number of items)",
					// "zeroRecords": "No matching records",
					// "processing": "<span class='text-danger font-weight-bold font-italic'> Processing ... Please wait a moment..",
					// "loadingRecords": "Loading...",
					// "infoPostFix": "",
					// "thousands": ",",
				},
				'columnDefs': filterColumn,
			});

			$('#' + nodatadiv).hide();
			$('#' + id + 'Div').show();

			// Add draw event listener
			// table.on('draw', function () {
			// 	loading('#' + screenLoadID, false);
			// });
		} else {
			$('#' + nodatadiv).empty(); // reset
			$('#' + nodatadiv).html(nodata());
			$('#' + nodatadiv).show();
			$('#' + id + 'Div').hide();
		}
	}

	loading('#' + screenLoadID, false);

	return table;
}

const loadFileContent = (fileName, idToLoad, sizeModal = 'lg', title = 'Default Title', dataArray = null, typeModal = 'modal') => {

	if (typeModal == 'modal') {
		var idContent = idToLoad + "-" + sizeModal;
	} else {
		var idContent = "offCanvasContent-right";
	}

	$('#' + idContent).empty(); // reset

	return $.ajax({
		type: "POST",
		url: $('meta[name="base_url"]').attr('content') + 'init.php',
		data: {
			action: 'modal',
			baseUrl: $('meta[name="base_url"]').attr('content'),
			fileName: fileName,
			dataArray: dataArray,
		},
		dataType: "html",
		success: function (data) {
			$('#' + idContent).append(data);

			setTimeout(function () {
				if (typeof getPassData == 'function') {
					getPassData($('meta[name="base_url"]').attr('content'), dataArray);
				} else {
					console.log('function getPassData not initialize!');
				}
			}, 50);

			if (typeModal == 'modal') {
				$('#generalTitle-' + sizeModal).text(title);
				$('#generalModal-' + sizeModal).modal('show');
			} else {
				// reset
				$('.custom-width').css('width', '400px');

				$('#offCanvasTitle-right').text(title);
				$('#generaloffcanvas-right').offcanvas('toggle');
				$('.custom-width').css('width', sizeModal);
			}
		}
	});
}

const loadFormContent = (fileName, idToLoad, sizeModal = 'lg', urlFunc = null, title = 'Default Title', dataArray =
	null, typeModal = 'modal') => {

	if (typeModal == 'modal') {
		var idContent = idToLoad + "-" + sizeModal;
	} else {
		var idContent = "offCanvasContent-right";
	}

	$('#' + idContent).empty(); // reset

	return $.ajax({
		type: "POST",
		url: $('meta[name="base_url"]').attr('content') + 'init.php',
		data: {
			action: 'modal',
			baseUrl: $('meta[name="base_url"]').attr('content'),
			fileName: fileName,
			dataArray: dataArray,
		},
		dataType: "html",
		success: function (response) {
			$('#' + idContent).append(response);

			setTimeout(function () {
				if (typeof getPassData == 'function') {
					getPassData($('meta[name="base_url"]').attr('content'), dataArray);
				} else {
					console.log('function getPassData not initialize!');
				}
			}, 50);

			// get form id
			var formID = $('#' + idContent + ' > form').attr('id');
			// > div:first-child

			$("#" + formID)[0].reset(); // reset form
			document.getElementById(formID).reset(); // reset form
			$("#" + formID).attr('action', urlFunc); // set url

			if (typeModal == 'modal') {
				$('#generalTitle-' + sizeModal).text(title);
				$('#generalModal-' + sizeModal).modal('show');
				$("#" + formID).attr("data-modal", '#generalModal-' + sizeModal);
			} else {
				// reset
				$('.custom-width').css('width', '400px');

				$('#offCanvasTitle-right').text(title);
				$('#generaloffcanvas-right').offcanvas('toggle');
				$("#" + formID).attr("data-modal", '#generaloffcanvas-right');
				$('.custom-width').css('width', sizeModal);
			}

			if (dataArray != null) {
				$.each($('input, select ,textarea', "#" + formID), function (k) {
					var type = $(this).prop('type');
					var name = $(this).attr('name');

					if (type == 'radio' || type == 'checkbox') {
						$("input[name=" + name + "][value='" + dataArray[name] + "']").prop(
							"checked", true);
					} else {
						$('#' + name).val(dataArray[name]);
					}

				});
			}

		},
		error: function (xhr, status, error) {
        	var statusCode = xhr.status; // HTTP status code (e.g., 404, 500)
			
			var message;
			if (xhr.responseJSON) {
				message = xhr.responseJSON.message;
			} else {
				try {
					var json = JSON.parse(xhr.responseText);
					message = json.message;
				} catch (e) {
					message = xhr.responseText; // fallback to raw text
				}
			}

			if (isError(statusCode)) {
				noti(statusCode, message);
			} else if (isUnauthorized(statusCode)) {
				noti(statusCode, "Unauthorized: Access is denied");
			} else {
				log(error, 'ERROR loadFormContent');
			}
		}
	});
}

// IMPORT EXCEL & PRINT HELPER

const printHelper = async (method = 'get', url, filter = null, config = null) => {

	let btnID = hasData(config, 'id', true, 'printBtn');
	let btnText = hasData(config, 'text', true, '<i class="bx bx-printer"></i> Print');
	let textHeader = hasData(config, 'header', true, 'LIST');

	loadingBtn(btnID, true);

	const res = await callApi(method, url, filter);

	if (isSuccess(res)) {

		if (isSuccess(res.data.code)) {
			const divToPrint = document.createElement('div');
			divToPrint.setAttribute('id', 'generatePDF');
			divToPrint.innerHTML = res.data.result

			document.body.appendChild(divToPrint);
			printDiv('generatePDF', btnID, $('#' + btnID).html(), textHeader);
			document.body.removeChild(divToPrint);
		} else {
			noti(res.data.code, res.data.message);
			console.log(res.data.code, res.data.message);
		}

		setTimeout(function () {
			loadingBtn(btnID, false, btnText);
		}, 450);
	}
}

// EXPORT LIST TO EXCEL

const exportExcelHelper = async (method = 'get', url, filter = null, config = null) => {

	let btnID = hasData(config, 'id', true, 'exportBtn');
	let btnText = hasData(config, 'text', true, '<i class="bx bx-spreadsheet"></i> Export as Excel');

	loadingBtn(btnID, true);

	const res = await callApi(method, url, filter);

	if (isSuccess(res)) {
		noti(res.data.code, res.data.message);
		await downloadFiles(res.data.path, res.data.filename);
	}

	setTimeout(function () {
		loadingBtn(btnID, false, btnText);
	}, 450);
}

// PREVIEW UPLOAD HELPER

const previewPDF = (fileLoc, fileMime, divToLoadID, modalId = null) => {
	const height = (fileMime === 'application/pdf') ? '650px' : 'auto';
	const url = base_url() + fileLoc;
	const view = (fileMime === 'application/pdf') ?
		`<iframe src="http://docs.google.com/gview?url=${url}"&embedded=true" frameborder="0"></iframe>` :
		`<object type="${fileMime}" data="${fileLoc}" width="100%" height="${height}"></object>`;

	$(`#${divToLoadID}`).empty();
	$(`#${divToLoadID}`).css('display', 'block');
	$(`#${divToLoadID}`).append(view);

	if (modalId != null) {
		$(`#${modalId}`).modal('show');
		$(`#${modalId}`).css('z-index', 2500);
	}
};

const previewFiles = async (fileLoc, fileMime, options = {}) => {
	// Default options
	const defaults = {
		display_id: "showDocument",
		modal_id: "",
		modal_type: "modal",
		height: "650px",
		width: "100%",
		errorMessage: "Unable to load the document. Please check the file or try again later.",
		loaderMessage: "Loading preview...",
		retry: 3,
		skeletonLoader: null,
		enableFullscreen: true,
		enableDownload: true,
		enableRotation: true, // For images
		maxFileSize: 50 * 1024 * 1024, // 50MB limit
		timeout: 30000, // 30 seconds timeout
	};

	// Merge default options with provided options
	const settings = {
		...defaults,
		...options,
	};

	// Validate inputs
	if (!fileLoc || !fileMime) {
		console.error("Invalid file location or MIME type");
		showContainerError(settings.display_id, "Invalid file parameters");
		return;
	}

	// Get container and validate it exists
	const $container = $(`#${settings.display_id}`);
	if ($container.length === 0) {
		console.error(`Container with ID '${settings.display_id}' not found`);
		return;
	}

	$container.empty().css("display", "block");

	// Enhanced skeleton loader
	const showLoader = () => {
		if (typeof settings.skeletonLoader === "function") {
			$container.append(settings.skeletonLoader());
		} else {
			$container.append(`
				<div class="d-flex flex-column align-items-center justify-content-center" style="height: ${settings.height};">
					<div class="spinner-border text-primary mb-3" role="status">
						<span class="visually-hidden">${settings.loaderMessage}</span>
					</div>
					<div class="text-muted">${settings.loaderMessage}</div>
					<div class="progress mt-3" style="width: 200px;">
						<div class="progress-bar progress-bar-striped progress-bar-animated" 
							 role="progressbar" style="width: 100%"></div>
					</div>
				</div>
			`);
		}
	};

	showLoader();

	const url = base_url() + fileLoc;
	let view = "";

	// Enhanced MIME types mapping with categories
	const mimeTypeCategories = {
		// Documents
		documents: {
			"application/pdf": { viewer: "pdf", icon: "fas fa-file-pdf", color: "#dc3545" },
			"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet": { viewer: "google", icon: "fas fa-file-excel", color: "#198754" },
			"application/vnd.ms-excel": { viewer: "google", icon: "fas fa-file-excel", color: "#198754" },
			"application/vnd.openxmlformats-officedocument.wordprocessingml.document": { viewer: "google", icon: "fas fa-file-word", color: "#0d6efd" },
			"application/msword": { viewer: "google", icon: "fas fa-file-word", color: "#0d6efd" },
			"application/vnd.openxmlformats-officedocument.presentationml.presentation": { viewer: "google", icon: "fas fa-file-powerpoint", color: "#fd7e14" },
			"application/vnd.ms-powerpoint": { viewer: "google", icon: "fas fa-file-powerpoint", color: "#fd7e14" },
			"text/plain": { viewer: "text", icon: "fas fa-file-alt", color: "#6c757d" },
			"text/csv": { viewer: "text", icon: "fas fa-file-csv", color: "#198754" },
		},
		// Images
		images: {
			"image/jpeg": { viewer: "image", icon: "fas fa-image", color: "#0dcaf0" },
			"image/jpg": { viewer: "image", icon: "fas fa-image", color: "#0dcaf0" },
			"image/png": { viewer: "image", icon: "fas fa-image", color: "#0dcaf0" },
			"image/gif": { viewer: "image", icon: "fas fa-image", color: "#0dcaf0" },
			"image/bmp": { viewer: "image", icon: "fas fa-image", color: "#0dcaf0" },
			"image/webp": { viewer: "image", icon: "fas fa-image", color: "#0dcaf0" },
			"image/svg+xml": { viewer: "image", icon: "fas fa-image", color: "#0dcaf0" },
		},
		// Videos
		videos: {
			"video/mp4": { viewer: "video", icon: "fas fa-video", color: "#6f42c1" },
			"video/webm": { viewer: "video", icon: "fas fa-video", color: "#6f42c1" },
			"video/ogg": { viewer: "video", icon: "fas fa-video", color: "#6f42c1" },
			"video/avi": { viewer: "video", icon: "fas fa-video", color: "#6f42c1" },
		},
		// Audio
		audios: {
			"audio/mp3": { viewer: "audio", icon: "fas fa-music", color: "#d63384" },
			"audio/wav": { viewer: "audio", icon: "fas fa-music", color: "#d63384" },
			"audio/ogg": { viewer: "audio", icon: "fas fa-music", color: "#d63384" },
			"audio/mpeg": { viewer: "audio", icon: "fas fa-music", color: "#d63384" },
		}
	};

	// Get all supported MIME types
	const getAllSupportedTypes = () => {
		const allTypes = {};
		Object.values(mimeTypeCategories).forEach(category => {
			Object.assign(allTypes, category);
		});
		return allTypes;
	};

	const supportedMimeTypes = getAllSupportedTypes();

	// Enhanced fetch with retry, timeout, and progress
	const fetchWithRetry = async (url, retries = 1) => {
		const controller = new AbortController();
		const timeoutId = setTimeout(() => controller.abort(), settings.timeout);

		for (let attempt = 1; attempt <= retries; attempt++) {
			try {
				const response = await fetch(url, {
					signal: controller.signal,
					headers: {
						'Cache-Control': 'no-cache',
					}
				});

				clearTimeout(timeoutId);

				if (response.ok) {
					// Check file size if possible
					const contentLength = response.headers.get('content-length');
					if (contentLength && parseInt(contentLength) > settings.maxFileSize) {
						throw new Error(`File too large: ${(parseInt(contentLength) / 1024 / 1024).toFixed(2)}MB`);
					}
					return response;
				}

				if (attempt === retries) {
					throw new Error(`HTTP ${response.status}: ${response.statusText}`);
				}

				await new Promise(resolve => setTimeout(resolve, 1000 * attempt));
			} catch (error) {
				clearTimeout(timeoutId);
				
				if (error.name === 'AbortError') {
					throw new Error('Request timeout - file took too long to load');
				}
				
				if (attempt === retries) {
					throw error;
				}

				await new Promise(resolve => setTimeout(resolve, 1000 * attempt));
			}
		}
	};

	// Enhanced error display
	const showError = (message, details = null) => {
		const errorView = `
			<div class="alert alert-danger d-flex align-items-center" role="alert">
				<i class="fas fa-exclamation-triangle me-2"></i>
				<div>
					<strong>Preview Error:</strong> ${message}
					${details ? `<br><small class="text-muted">${details}</small>` : ''}
				</div>
			</div>
			<div class="text-center mt-3">
				<button class="btn btn-outline-primary" onclick="location.reload()">
					<i class="fas fa-refresh me-1"></i> Retry
				</button>
				${settings.enableDownload ? `
					<a href="${url}" class="btn btn-outline-secondary ms-2" download>
						<i class="fas fa-download me-1"></i> Download
					</a>
				` : ''}
			</div>
		`;
		$container.html(errorView);
	};

	// Create action buttons
	const createActionButtons = (fileType) => {
		let buttons = '';
		
		if (settings.enableDownload) {
			buttons += `
				<button class="btn btn-sm btn-outline-primary me-2" onclick="downloadFile('${url}', '${fileLoc.split('/').pop()}')">
					<i class="fas fa-download me-1"></i> Download
				</button>
			`;
		}
		
		if (settings.enableFullscreen) {
			buttons += `
				<button class="btn btn-sm btn-outline-secondary me-2" onclick="toggleFullscreen('${settings.display_id}')">
					<i class="fas fa-expand me-1"></i> Fullscreen
				</button>
			`;
		}
		
		if (fileType === 'image' && settings.enableRotation) {
			buttons += `
				<button class="btn btn-sm btn-outline-info me-2" onclick="rotateImage('${settings.display_id}')">
					<i class="fas fa-redo me-1"></i> Rotate
				</button>
			`;
		}
		
		return buttons ? `<div class="mb-3 text-center">${buttons}</div>` : '';
	};

	try {
		// Check if file type is supported
		const fileInfo = supportedMimeTypes[fileMime];
		
		if (!fileInfo) {
			throw new Error(`Unsupported file type: ${fileMime}`);
		}

		// Verify file accessibility
		await fetchWithRetry(url, settings.retry);

		const viewerUrl = "https://docs.google.com/gview?url=" + encodeURIComponent(url) + "&embedded=true";
		const actionButtons = createActionButtons(fileInfo.viewer);

		// Create view based on file type
		switch (fileInfo.viewer) {
			case 'image':
				view = `
					${actionButtons}
					<div class="text-center image-container" style="position: relative;">
						<img 
							src="${url}" 
							alt="Preview" 
							class="img-fluid preview-image" 
							style="max-width: 100%; max-height: ${settings.height}; object-fit: contain; transition: transform 0.3s ease;"
							onload="this.style.opacity='1'"
							onerror="showContainerError('${settings.display_id}', '${settings.errorMessage}')"
							ondragstart="return false"
						/>
						<div class="image-overlay" style="position: absolute; bottom: 10px; left: 10px; background: rgba(0,0,0,0.7); color: white; padding: 5px 10px; border-radius: 3px; font-size: 0.8em;">
							<i class="${fileInfo.icon}" style="color: ${fileInfo.color}"></i>
							${fileLoc.split('/').pop()}
						</div>
					</div>
				`;
				break;

			case 'pdf':
				// Multiple fallback options for PDF viewing
				view = `
					${actionButtons}
					<div class="pdf-viewer-container">
						<div class="pdf-viewer-tabs mb-2">
							<button class="btn btn-sm btn-outline-primary active" onclick="switchPdfViewer('${settings.display_id}', 'embed')">
								<i class="fas fa-file-pdf me-1"></i> Native
							</button>
							<button class="btn btn-sm btn-outline-secondary ms-2" onclick="switchPdfViewer('${settings.display_id}', 'google')">
								<i class="fab fa-google me-1"></i> Google
							</button>
							<button class="btn btn-sm btn-outline-info ms-2" onclick="switchPdfViewer('${settings.display_id}', 'mozilla')">
								<i class="fab fa-firefox me-1"></i> Mozilla
							</button>
						</div>
						<div id="pdf-viewer-embed-${settings.display_id}" class="pdf-viewer active">
							<embed 
								src="${url}#toolbar=1&navpanes=1&scrollbar=1" 
								type="application/pdf" 
								width="${settings.width}" 
								height="${settings.height}"
								style="border: 1px solid #dee2e6; border-radius: 0.375rem;"
							/>
						</div>
						<div id="pdf-viewer-google-${settings.display_id}" class="pdf-viewer" style="display: none;">
							<iframe 
								src="${viewerUrl}" 
								width="${settings.width}" 
								height="${settings.height}" 
								frameborder="0"
								style="border: 1px solid #dee2e6; border-radius: 0.375rem;"
								onload="handleIframeLoad(this, '${settings.display_id}')"
								onerror="handleIframeError(this, '${settings.display_id}')"
							></iframe>
						</div>
						<div id="pdf-viewer-mozilla-${settings.display_id}" class="pdf-viewer" style="display: none;">
							<iframe 
								src="https://mozilla.github.io/pdf.js/web/viewer.html?file=${encodeURIComponent(url)}" 
								width="${settings.width}" 
								height="${settings.height}" 
								frameborder="0"
								style="border: 1px solid #dee2e6; border-radius: 0.375rem;"
							></iframe>
						</div>
					</div>
				`;
				break;

			case 'video':
				view = `
					${actionButtons}
					<div class="text-center">
						<video 
							controls 
							style="max-width: 100%; max-height: ${settings.height};"
							preload="metadata"
						>
							<source src="${url}" type="${fileMime}">
							Your browser does not support the video tag.
						</video>
					</div>
				`;
				break;

			case 'audio':
				view = `
					${actionButtons}
					<div class="text-center">
						<div class="card" style="max-width: 500px; margin: 0 auto;">
							<div class="card-body">
								<h5 class="card-title">
									<i class="${fileInfo.icon}" style="color: ${fileInfo.color}"></i>
									${fileLoc.split('/').pop()}
								</h5>
								<audio controls style="width: 100%;" preload="metadata">
									<source src="${url}" type="${fileMime}">
									Your browser does not support the audio element.
								</audio>
							</div>
						</div>
					</div>
				`;
				break;

			case 'text':
				// For text files, fetch and display content
				const textResponse = await fetch(url);
				const textContent = await textResponse.text();
				view = `
					${actionButtons}
					<div class="card">
						<div class="card-header">
							<i class="${fileInfo.icon}" style="color: ${fileInfo.color}"></i>
							${fileLoc.split('/').pop()}
						</div>
						<div class="card-body">
							<pre style="max-height: ${settings.height}; overflow-y: auto; white-space: pre-wrap; font-size: 0.9em;">${textContent}</pre>
						</div>
					</div>
				`;
				break;

			case 'google':
			default:
				view = `
					${actionButtons}
					<div class="position-relative">
						<iframe 
							src="${viewerUrl}" 
							width="${settings.width}" 
							height="${settings.height}" 
							frameborder="0"
							style="border: 1px solid #dee2e6; border-radius: 0.375rem;"
							onload="this.style.opacity='1'"
							onerror="showContainerError('${settings.display_id}', '${settings.errorMessage}')"
						></iframe>
						<div style="position: absolute; top: 10px; right: 10px; background: rgba(255,255,255,0.9); padding: 5px 10px; border-radius: 3px; font-size: 0.8em;">
							<i class="${fileInfo.icon}" style="color: ${fileInfo.color}"></i>
							${fileLoc.split('/').pop()}
						</div>
					</div>
				`;
				break;
		}

		// Clear and populate the container
		$container.empty().css("display", "block").append(view);

		// Handle modal/offcanvas with enhanced styling
		if (settings.modal_id) {
			const $modal = $(`#${settings.modal_id}`);

			if (settings.modal_type === "modal") {
				$modal.modal("show").css("z-index", 2000);
				// Add backdrop blur effect
				$modal.on('shown.bs.modal', function() {
					$('body').addClass('modal-backdrop-blur');
				}).on('hidden.bs.modal', function() {
					$('body').removeClass('modal-backdrop-blur');
				});
			} else if (settings.modal_type === "offcanvas") {
				$modal.offcanvas("toggle").css("z-index", 2000);
			}
		}

		console.log(`Successfully loaded ${fileInfo.viewer} file: ${fileLoc}`);

	} catch (error) {
		console.error("Error loading document:", error);
		showError(settings.errorMessage, error.message);
	}
};

// Utility functions
const showContainerError = (containerId, message) => {
	const $container = $(`#${containerId}`);
	$container.html(`
		<div class="alert alert-danger text-center" role="alert">
			<i class="fas fa-exclamation-triangle mb-2"></i>
			<div>${message}</div>
		</div>
	`);
};

const downloadFile = (url, filename) => {
	const link = document.createElement('a');
	link.href = url;
	link.download = filename;
	document.body.appendChild(link);
	link.click();
	document.body.removeChild(link);
};

const toggleFullscreen = (containerId) => {
	const container = document.getElementById(containerId);
	
	if (!document.fullscreenElement && !document.webkitFullscreenElement && 
		!document.mozFullScreenElement && !document.msFullscreenElement) {
		
		// Enter fullscreen
		const requestFullscreen = container.requestFullscreen || 
			container.webkitRequestFullscreen || 
			container.mozRequestFullScreen || 
			container.msRequestFullscreen;
		
		if (requestFullscreen) {
			requestFullscreen.call(container).then(() => {
				// Add fullscreen styles
				container.classList.add('fullscreen-active');
				
				// Update button text/icon
				const fullscreenBtn = container.querySelector('[onclick*="toggleFullscreen"]');
				if (fullscreenBtn) {
					fullscreenBtn.innerHTML = '<i class="fas fa-compress me-1"></i> Exit Fullscreen';
				}
				
				// Make content fill the screen
				const content = container.querySelector('iframe, embed, img, video, audio, .card');
				if (content) {
					content.style.width = '100vw';
					content.style.height = '100vh';
					content.style.maxWidth = '100vw';
					content.style.maxHeight = '100vh';
				}
				
				// Handle PDF viewers specifically
				const pdfViewers = container.querySelectorAll('.pdf-viewer iframe, .pdf-viewer embed');
				pdfViewers.forEach(viewer => {
					viewer.style.width = '100vw';
					viewer.style.height = '100vh';
				});
				
			}).catch(err => {
				console.error('Error entering fullscreen:', err);
				// Fallback to custom fullscreen
				createCustomFullscreen(containerId);
			});
		} else {
			// Fallback for unsupported browsers
			createCustomFullscreen(containerId);
		}
	} else {
		// Exit fullscreen
		const exitFullscreen = document.exitFullscreen || 
			document.webkitExitFullscreen || 
			document.mozCancelFullScreen || 
			document.msExitFullscreen;
		
		if (exitFullscreen) {
			exitFullscreen.call(document).then(() => {
				container.classList.remove('fullscreen-active');
				restoreNormalView(containerId);
			});
		} else {
			// Exit custom fullscreen
			exitCustomFullscreen(containerId);
		}
	}
};

// Custom fullscreen implementation as fallback
const createCustomFullscreen = (containerId) => {
	const container = document.getElementById(containerId);
	
	// Create fullscreen overlay
	const overlay = document.createElement('div');
	overlay.id = `fullscreen-overlay-${containerId}`;
	overlay.className = 'custom-fullscreen-overlay';
	overlay.innerHTML = `
		<div class="fullscreen-header">
			<div class="fullscreen-controls">
				<button class="btn btn-light btn-sm me-2" onclick="exitCustomFullscreen('${containerId}')">
					<i class="fas fa-compress me-1"></i> Exit Fullscreen
				</button>
				<button class="btn btn-light btn-sm" onclick="exitCustomFullscreen('${containerId}')">
					<i class="fas fa-times"></i>
				</button>
			</div>
		</div>
		<div class="fullscreen-content" id="fullscreen-content-${containerId}"></div>
	`;
	
	// Clone and move content to overlay
	const originalContent = container.innerHTML;
	const contentClone = container.cloneNode(true);
	contentClone.id = `${containerId}-fullscreen-clone`;
	
	// Store original content for restoration
	container.setAttribute('data-original-content', originalContent);
	
	// Append to body
	document.body.appendChild(overlay);
	document.getElementById(`fullscreen-content-${containerId}`).appendChild(contentClone);
	
	// Add escape key listener
	const escapeHandler = (e) => {
		if (e.key === 'Escape') {
			exitCustomFullscreen(containerId);
		}
	};
	document.addEventListener('keydown', escapeHandler);
	overlay.setAttribute('data-escape-handler', 'true');
	
	// Prevent body scrolling
	document.body.style.overflow = 'hidden';
	
	// Update content dimensions
	const content = contentClone.querySelector('iframe, embed, img, video, audio, .card');
	if (content) {
		content.style.width = '100%';
		content.style.height = 'calc(100vh - 60px)';
		content.style.maxWidth = '100%';
		content.style.maxHeight = 'calc(100vh - 60px)';
	}
};

const exitCustomFullscreen = (containerId) => {
	const overlay = document.getElementById(`fullscreen-overlay-${containerId}`);
	if (overlay) {
		// Remove escape key listener
		const escapeHandler = (e) => {
			if (e.key === 'Escape') {
				exitCustomFullscreen(containerId);
			}
		};
		document.removeEventListener('keydown', escapeHandler);
		
		// Restore body scrolling
		document.body.style.overflow = '';
		
		// Remove overlay
		overlay.remove();
	}
	
	// Restore normal view
	restoreNormalView(containerId);
};

const restoreNormalView = (containerId) => {
	const container = document.getElementById(containerId);
	
	// Update button text/icon
	const fullscreenBtn = container.querySelector('[onclick*="toggleFullscreen"]');
	if (fullscreenBtn) {
		fullscreenBtn.innerHTML = '<i class="fas fa-expand me-1"></i> Fullscreen';
	}
	
	// Restore original dimensions
	const content = container.querySelector('iframe, embed, img, video, audio, .card');
	if (content) {
		content.style.width = '';
		content.style.height = '';
		content.style.maxWidth = '';
		content.style.maxHeight = '';
	}
	
	// Handle PDF viewers specifically
	const pdfViewers = container.querySelectorAll('.pdf-viewer iframe, .pdf-viewer embed');
	pdfViewers.forEach(viewer => {
		viewer.style.width = '';
		viewer.style.height = '';
	});
};

let imageRotation = 0;
const rotateImage = (containerId) => {
	const $img = $(`#${containerId} .preview-image`);
	imageRotation += 90;
	if (imageRotation >= 360) imageRotation = 0;
	$img.css('transform', `rotate(${imageRotation}deg)`);
};

// PDF viewer switching functionality
const switchPdfViewer = (containerId, viewerType) => {
	// Hide all viewers
	$(`#${containerId} .pdf-viewer`).hide().removeClass('active');
	$(`#${containerId} .pdf-viewer-tabs button`).removeClass('active btn-primary').addClass('btn-outline-primary');
	
	// Show selected viewer
	$(`#pdf-viewer-${viewerType}-${containerId}`).show().addClass('active');
	$(`#${containerId} .pdf-viewer-tabs button`).eq(viewerType === 'embed' ? 0 : viewerType === 'google' ? 1 : 2)
		.removeClass('btn-outline-primary').addClass('btn-primary active');
};

// Handle iframe load events for better error handling
const handleIframeLoad = (iframe, containerId) => {
	// Check if Google Docs viewer shows "No preview available"
	setTimeout(() => {
		try {
			const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
			const bodyText = iframeDoc.body.innerText || iframeDoc.body.textContent;
			
			if (bodyText.includes('No preview available') || bodyText.includes('Sorry, we can\'t display this file')) {
				// Switch to native viewer automatically
				switchPdfViewer(containerId, 'embed');
				showToast('Google Viewer unavailable, switched to native viewer', 'warning');
			}
		} catch (e) {
			// Cross-origin restrictions prevent access, assume it's working
			console.log('Cannot access iframe content due to CORS, assuming successful load');
		}
	}, 2000);
};

const handleIframeError = (iframe, containerId) => {
	console.error('Iframe failed to load, switching to native viewer');
	switchPdfViewer(containerId, 'embed');
	showToast('Viewer failed to load, switched to native viewer', 'error');
};

// Toast notification system
const showToast = (message, type = 'info') => {
	const toastId = 'preview-toast-' + Date.now();
	const toastColors = {
		success: 'text-bg-success',
		warning: 'text-bg-warning', 
		error: 'text-bg-danger',
		info: 'text-bg-info'
	};
	
	const toastHtml = `
		<div id="${toastId}" class="toast ${toastColors[type] || toastColors.info}" role="alert" style="position: fixed; top: 20px; right: 20px; z-index: 9999;">
			<div class="toast-body">
				<i class="fas fa-${type === 'success' ? 'check' : type === 'warning' ? 'exclamation-triangle' : type === 'error' ? 'times' : 'info-circle'} me-2"></i>
				${message}
			</div>
		</div>
	`;
	
	$('body').append(toastHtml);
	const toast = new bootstrap.Toast(document.getElementById(toastId), { delay: 4000 });
	toast.show();
	
	// Remove from DOM after hiding
	$(`#${toastId}`).on('hidden.bs.toast', function() {
		$(this).remove();
	});
};

// Optional: Add CSS for enhanced styling
const addPreviewStyles = () => {
	if (!document.getElementById('preview-styles')) {
		const style = document.createElement('style');
		style.id = 'preview-styles';
		style.textContent = `
			.modal-backdrop-blur {
				backdrop-filter: blur(5px);
			}
			.image-container:hover .image-overlay {
				opacity: 1;
			}
			.image-overlay {
				opacity: 0.7;
				transition: opacity 0.3s ease;
			}
			.preview-image {
				box-shadow: 0 4px 8px rgba(0,0,0,0.1);
				border-radius: 8px;
			}
			.progress-bar-animated {
				animation: progress-bar-stripes 1s linear infinite;
			}
			.pdf-viewer-tabs button {
				transition: all 0.3s ease;
			}
			.pdf-viewer {
				transition: opacity 0.3s ease;
			}
			.toast {
				min-width: 300px;
			}
			.custom-fullscreen-overlay {
				position: fixed;
				top: 0;
				left: 0;
				width: 100vw;
				height: 100vh;
				background: #000;
				z-index: 9999;
				display: flex;
				flex-direction: column;
			}
			.fullscreen-header {
				background: rgba(0, 0, 0, 0.8);
				padding: 10px 20px;
				display: flex;
				justify-content: flex-end;
				align-items: center;
				min-height: 50px;
			}
			.fullscreen-content {
				flex: 1;
				overflow: auto;
				display: flex;
				align-items: center;
				justify-content: center;
				padding: 10px;
			}
			.fullscreen-content > div {
				width: 100%;
				height: 100%;
			}
			.fullscreen-active {
				background: #000 !important;
			}
			.fullscreen-active iframe,
			.fullscreen-active embed,
			.fullscreen-active img,
			.fullscreen-active video {
				border: none !important;
				border-radius: 0 !important;
				box-shadow: none !important;
			}
			/* Handle native fullscreen styling */
			.fullscreen-active .pdf-viewer-tabs {
				position: absolute;
				top: 10px;
				left: 10px;
				z-index: 1000;
				background: rgba(0, 0, 0, 0.7);
				padding: 5px;
				border-radius: 5px;
			}
			.fullscreen-active .pdf-viewer-tabs button {
				font-size: 0.8em;
				padding: 5px 10px;
			}
		`;
		document.head.appendChild(style);
	}
};

// Initialize styles when the script loads
$(document).ready(() => {
	addPreviewStyles();
	
	// Handle fullscreen change events
	const fullscreenEvents = ['fullscreenchange', 'webkitfullscreenchange', 'mozfullscreenchange', 'MSFullscreenChange'];
	
	fullscreenEvents.forEach(event => {
		document.addEventListener(event, () => {
			// Find all containers with fullscreen functionality
			const containers = document.querySelectorAll('[id*="showDocument"], [class*="preview-container"]');
			
			containers.forEach(container => {
				if (!document.fullscreenElement && !document.webkitFullscreenElement && 
					!document.mozFullScreenElement && !document.msFullscreenElement) {
					// Exited fullscreen
					container.classList.remove('fullscreen-active');
					restoreNormalView(container.id);
				}
			});
		});
	});
});