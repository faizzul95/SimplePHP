// Global variables for validation
let validationErrors = {};
let validationDebug = false;

/**
 * Main validation function
 * @param {HTMLElement|string} formElement - Form element or form ID
 * @param {Object} rules - Validation rules
 * @param {Object} messages - Custom messages (optional)
 * @param {string} attributeType - Attribute type to use ('name' or 'id', default: 'name')
 * @returns {boolean} - True if validation passes
 */
function validationJs(formElement, rules, messages = {}, attributeType = 'name') {
    // Clear previous errors
    validationErrors = {};

    // Define classes for validation (Bootstrap classes)
    const validationSuccessClass = 'is-valid';
    const validationErrorClass = 'is-invalid';
    
    try {
        // Get form element
        let form;
        if (typeof formElement === 'string') {
            form = document.getElementById(formElement.replace('#', ''));
        } else {
            form = formElement;
        }
        
        if (!form) {
            throw new Error('Form element not found');
        }
        
        // Debug info
        if (validationDebug) {
            console.log('Validation started for form:', form);
            console.log('Rules:', rules);
            console.log('Messages:', messages);
        }
        
        let isValid = true;
        
        // Validate each field
        for (let fieldName in rules) {
            const fieldRules = rules[fieldName];
            
            // Check if field name ends with [] (array field)
            if (fieldName.endsWith('[]')) {
                const baseFieldName = fieldName.slice(0, -2);
                const fieldElements = form.querySelectorAll(`[${attributeType}="${fieldName}"]`);
                
                if (fieldElements.length === 0) {
                    if (validationDebug) {
                        console.warn(`Array field elements not found: ${fieldName}`);
                    }
                    continue;
                }
                
                // Validate each array element
                fieldElements.forEach((fieldElement, index) => {
                    const indexedFieldName = `${baseFieldName}_${index}`;
                    const fieldValue = getFieldValue(fieldElement);
                    const fieldMessages = messages[fieldName] || {};
                    const fieldLabel = fieldMessages.label || baseFieldName.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                    
                    // Remove previous validation classes before evaluating
                    fieldElement.classList.remove(validationErrorClass, validationSuccessClass);
                    
                    if (validationDebug) {
                        console.log(`Validating array field: ${indexedFieldName}, value:`, fieldValue);
                    }
                    
                    let fieldIsValid = true;
                    const ruleArray = parseRules(fieldRules);
                    
                    for (let rule of ruleArray) {
                        const result = validateRule(fieldValue, rule, fieldElement, form, attributeType);
                        
                        if (!result.valid) {
                            isValid = false;
                            fieldIsValid = false;
                            const errorMessage = getErrorMessage(baseFieldName, rule, fieldMessages, `${fieldLabel} #${index + 1}`, result.message);
                            
                            if (!validationErrors[indexedFieldName]) {
                                validationErrors[indexedFieldName] = [];
                            }
                            validationErrors[indexedFieldName].push(errorMessage);
                            
                            // Add error class to field
                            fieldElement.classList.add(validationErrorClass);
                            
                            if (validationDebug) {
                                console.log(`Validation failed for ${indexedFieldName}:`, errorMessage);
                            }
                            break;
                        }
                    }
                    
                    // If validation passed, add success class
                    if (fieldIsValid) {
                        fieldElement.classList.add(validationSuccessClass);
                    }
                });
            } else {
                // Regular field validation (non-array)
                const fieldElement = form.querySelector(`[${attributeType}="${fieldName}"]`);
                
                if (!fieldElement) {
                    if (validationDebug) {
                        console.warn(`Field element not found: ${fieldName}`);
                    }
                    continue;
                }
                
                const fieldValue = getFieldValue(fieldElement);
                const fieldMessages = messages[fieldName] || {};
                const fieldLabel = fieldMessages.label || fieldName.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                
                // Remove previous validation classes before evaluating
                fieldElement.classList.remove(validationErrorClass, validationSuccessClass);
                
                if (validationDebug) {
                    console.log(`Validating field: ${fieldName}, value:`, fieldValue);
                }
                
                let fieldIsValid = true;
                const ruleArray = parseRules(fieldRules);
                
                for (let rule of ruleArray) {
                    const result = validateRule(fieldValue, rule, fieldElement, form, attributeType);
                    
                    if (!result.valid) {
                        isValid = false;
                        fieldIsValid = false;
                        const errorMessage = getErrorMessage(fieldName, rule, fieldMessages, fieldLabel, result.message);
                        
                        if (!validationErrors[fieldName]) {
                            validationErrors[fieldName] = [];
                        }
                        validationErrors[fieldName].push(errorMessage);
                        
                        // Add error class to field
                        fieldElement.classList.add(validationErrorClass);
                        
                        if (validationDebug) {
                            console.log(`Validation failed for ${fieldName}:`, errorMessage);
                        }
                        break;
                    }
                }
                
                // If validation passed, add success class
                if (fieldIsValid) {
                    fieldElement.classList.add(validationSuccessClass);
                }
            }
        }
        
        if (validationDebug) {
            console.log('Validation completed. Is valid:', isValid);
            console.log('Errors:', validationErrors);
        }
        
        return isValid;
        
    } catch (error) {
        console.error('Validation error:', error);
        validationErrors['_global'] = ['Validation system error: ' + error.message];
        return false;
    }
}

/**
 * Get validation errors
 * @param {string} type - Error display type ('raw', 'toastr')
 * @param {string} mode - Display mode ('single', 'multi')
 * @returns {Object|void} - Error messages or displays them
 */
function validationJsError(type = 'raw', mode = 'single') {
    const errorMessages = {};
    
    // Flatten errors
    for (let field in validationErrors) {
        if (validationErrors[field].length > 0) {
            errorMessages[field] = validationErrors[field][0]; // Get first error for each field
        }
    }
    
    if (type === 'raw') {
        return errorMessages;
    }
    
    // For demonstration purposes, we'll show errors in a div instead of toastr
    if (type === 'toastr') {
        showToastrErrors(errorMessages, mode);
        return;
    }
    
    return errorMessages;
}

/**
 * Enable/disable debug mode
 * @param {boolean} enable - Enable debug mode
 */
function validationJsDebug(enable = true) {
    validationDebug = enable;
}

/**
 * Get field value based on element type
 * @param {HTMLElement} element - Form element
 * @returns {*} - Field value
 */
function getFieldValue(element) {
    if (!element) return '';

    const tagName = element.tagName.toLowerCase();
    const elemType = element.type ? element.type.toLowerCase() : '';

    if (elemType === 'file') {
        return element.files;
    } else if (elemType === 'checkbox') {
        return element.checked ? element.value || '1' : '';
    } else if (elemType === 'radio') {
        const form = element.closest('form');
        if (form) {
            const checked = form.querySelector(`input[name="${element.name}"]:checked`);
            return checked ? checked.value : '';
        }
        return element.checked ? element.value : '';
    } else if (tagName === 'select' && element.multiple) {
        const selectedOptions = Array.from(element.selectedOptions).map(option => option.value);
        return selectedOptions.length > 0 ? selectedOptions : [];
    } else {
        // Handle regular text inputs, textareas, single selects, etc.
        return element.value || '';
    }
}

/**
 * Parse rule string into array of rule objects
 * @param {string} rulesString - Rules string like "required|string|min:5"
 * @returns {Array} - Array of rule objects
 */
function parseRules(rulesString) {
    if (typeof rulesString !== 'string') {
        return [];
    }
    
    return rulesString.split('|').map(rule => {
        const parts = rule.split(':');
        const name = parts[0];
        const parameters = parts.slice(1).join(':').split(',').filter(p => p !== '');
        
        return { name, parameters };
    });
}

/**
 * Validate a single rule
 * @param {*} value - Field value
 * @param {Object} rule - Rule object
 * @param {HTMLElement} element - Form element
 * @param {HTMLElement} form - Form element
 * @param {string} attributeType - Attribute type
 * @returns {Object} - Validation result
 */
function validateRule(value, rule, element, form, attributeType) {
    const { name, parameters } = rule;
    
    try {
        switch (name) {
            case 'required':
                return validateRequired(value, element);
            
            case 'required_if':
                return validateRequiredIf(value, parameters, form, attributeType);

            case 'required_with':
                return validateRequiredWith(value, parameters, form, attributeType);
                
            case 'required_unless':
                return validateRequiredUnless(value, parameters, form, attributeType);
            
            case 'string':
                return validateString(value);
            
            case 'numeric':
            case 'double':
            case 'float':
                return validateNumeric(value);
            
            case 'integer':
                return validateInteger(value);
            
            case 'email':
                return validateEmail(value);
            
            case 'array':
                return validateArray(value);
            
            case 'file':
                return validateFile(value);
            
            case 'size':
                return validateSize(value, parameters, element);
            
            case 'mimes':
                return validateMimes(value, parameters);
            
            case 'min':
                return validateMin(value, parameters, element);
            
            case 'max':
                return validateMax(value, parameters, element);
            
            case 'between':
                return validateBetween(value, parameters, element);
            
            case 'date':
                return validateDate(value);
            
            case 'date_format':
                return validateDateFormat(value, parameters);
            
            case 'after':
                return validateAfter(value, parameters);
            
            case 'before':
                return validateBefore(value, parameters);
            
            case 'after_or_equal':
                return validateAfterOrEqual(value, parameters, form, attributeType);
            
            case 'before_or_equal':
                return validateBeforeOrEqual(value, parameters, form, attributeType);
            
            case 'weekend':
                return validateWeekend(value);
            
            case 'time':
                return validateTime(value);
            
            case 'url':
                return validateUrl(value);
            
            case 'boolean':
                return validateBoolean(value);
            
            case 'confirmed':
                return validateConfirmed(value, element, form, attributeType);
            
            case 'alpha':
                return validateAlpha(value);
            
            case 'alpha_num':
                return validateAlphaNum(value);
            
            case 'same':
                return validateSame(value, parameters, form, attributeType);
            
            case 'different':
                return validateDifferent(value, parameters, form, attributeType);
            
            case 'in':
                return validateIn(value, parameters);
            
            case 'not_in':
                return validateNotIn(value, parameters);
            
            case 'regex':
                return validateRegex(value, parameters);
            
            case 'json':
                return validateJson(value);
            
            case 'ip':
                return validateIp(value);
            
            case 'ipv4':
                return validateIpv4(value);
            
            case 'ipv6':
                return validateIpv6(value);
            
            case 'uuid':
                return validateUuid(value);
            
            case 'digits':
                return validateDigits(value, parameters);
            
            case 'digits_between':
                return validateDigitsBetween(value, parameters);

            case 'currency':
                return validateCurrency(value, parameters);
                
            case 'min_length':
                return validateMinLength(value, parameters);
                
            case 'max_length':
                return validateMaxLength(value, parameters);
                
            case 'alpha_dash':
                return validateAlphaDash(value);
                
            case 'lowercase':
                return validateLowercase(value);
                
            case 'uppercase':
                return validateUppercase(value);
                
            case 'decimal':
                return validateDecimal(value, parameters);
                
            case 'gt':
                return validateGreaterThan(value, parameters, form, attributeType);
                
            case 'lt':
                return validateLessThan(value, parameters, form, attributeType);
                
            case 'lte':
                return validateLessThanOrEqual(value, parameters, form, attributeType);
                
            case 'dimensions':
                return validateDimensions(value, parameters);
                
            case 'nullable':
                return validateNullable(value);
                
            case 'sometimes':
                return { valid: true }; // Always valid as it's just a marker
                
            case 'contains':
                return validateContains(value, parameters);
                
            case 'doesnt_contain':
                return validateDoesntContain(value, parameters);
                
            case 'accepted':
                return validateAccepted(value);
                
            case 'image':
                return validateImage(value);
            
            default:
                return { valid: true };
        }
    } catch (error) {
        console.error(`Error validating rule ${name}:`, error);
        return { valid: false, message: `Validation error for rule ${name}` };
    }
}

// Validation rule implementations
/**
 * Validates if a field is required
 * @param {*} value - The field value to validate
 * @param {HTMLElement} element - The form element
 * @returns {Object} - Object with valid property indicating validation result
 */
function validateRequired(value, element) {
    if (element.type === 'file') {
        return { valid: value && value.length > 0 };
    }
    
    if (Array.isArray(value)) {
        return { valid: value.length > 0 };
    }
    
    return { valid: value !== null && value !== undefined && String(value).trim() !== '' };
}

/**
 * Validates if a field is required conditionally based on another field's value
 * @param {*} value - The field value to validate
 * @param {Array} parameters - Array of parameters [field, operator, ...values]
 * @param {HTMLElement} form - The form element
 * @param {string} attributeType - The attribute type to use ('name' or 'id')
 * @returns {Object} - Object with valid property indicating validation result
 */
function validateRequiredIf(value, parameters, form, attributeType) {
    if (parameters.length < 2) return { valid: true };
    
    const [field, operator, ...values] = parameters;
    const targetElement = form.querySelector(`[${attributeType}="${field}"]`);
    
    if (!targetElement) return { valid: true };
    
    const targetValue = getFieldValue(targetElement);
    let shouldBeRequired = false;
    
    const normalizeValue = (val) => {
        if (val === null || val === undefined || val === '') return null;
        if (typeof val === 'string' && val.toLowerCase() === 'null') return null;
        return String(val);
    };
    
    const isNullOrEmpty = (val) => {
        return val === null || val === undefined || val === '' || 
               (typeof val === 'string' && val.toLowerCase() === 'null');
    };
    
    const isBooleanTrue = (val) => {
        if (typeof val === 'boolean') return val;
        if (typeof val === 'string') {
            const lower = val.toLowerCase();
            return lower === 'true' || lower === '1' || lower === 'yes' || lower === 'on';
        }
        if (typeof val === 'number') return val !== 0;
        return false;
    };
    
    const isBooleanFalse = (val) => {
        if (typeof val === 'boolean') return !val;
        if (typeof val === 'string') {
            const lower = val.toLowerCase();
            return lower === 'false' || lower === '0' || lower === 'no' || lower === 'off' || lower === '';
        }
        if (typeof val === 'number') return val === 0;
        if (val === null || val === undefined) return true;
        return false;
    };
    
    const normalizedTargetValue = normalizeValue(targetValue);
    const normalizedComparisonValues = values.map(normalizeValue);
    
    switch (operator) {
        case '=':
        case '==':
            if (normalizedComparisonValues.includes('null') || normalizedComparisonValues.includes(null)) {
                shouldBeRequired = isNullOrEmpty(targetValue);
            } else {
                shouldBeRequired = normalizedComparisonValues.includes(normalizedTargetValue);
            }
            break;
            
        case '!=':
        case '!==':
            if (normalizedComparisonValues.includes('null') || normalizedComparisonValues.includes(null)) {
                shouldBeRequired = !isNullOrEmpty(targetValue);
            } else {
                shouldBeRequired = !normalizedComparisonValues.includes(normalizedTargetValue);
            }
            break;
            
        case '>':
            if (!isNullOrEmpty(targetValue) && !isNaN(targetValue)) {
                const numTarget = parseFloat(targetValue);
                shouldBeRequired = normalizedComparisonValues.some(val => 
                    !isNullOrEmpty(val) && !isNaN(val) && numTarget > parseFloat(val)
                );
            }
            break;
            
        case '>=':
            if (!isNullOrEmpty(targetValue) && !isNaN(targetValue)) {
                const numTarget = parseFloat(targetValue);
                shouldBeRequired = normalizedComparisonValues.some(val => 
                    !isNullOrEmpty(val) && !isNaN(val) && numTarget >= parseFloat(val)
                );
            }
            break;
            
        case '<':
            if (!isNullOrEmpty(targetValue) && !isNaN(targetValue)) {
                const numTarget = parseFloat(targetValue);
                shouldBeRequired = normalizedComparisonValues.some(val => 
                    !isNullOrEmpty(val) && !isNaN(val) && numTarget < parseFloat(val)
                );
            }
            break;
            
        case '<=':
            if (!isNullOrEmpty(targetValue) && !isNaN(targetValue)) {
                const numTarget = parseFloat(targetValue);
                shouldBeRequired = normalizedComparisonValues.some(val => 
                    !isNullOrEmpty(val) && !isNaN(val) && numTarget <= parseFloat(val)
                );
            }
            break;
            
        case 'in':
            shouldBeRequired = normalizedComparisonValues.includes(normalizedTargetValue);
            break;
            
        case 'not_in':
            shouldBeRequired = !normalizedComparisonValues.includes(normalizedTargetValue);
            break;
            
        case 'empty':
            shouldBeRequired = isNullOrEmpty(targetValue);
            break;
            
        case 'not_empty':
            shouldBeRequired = !isNullOrEmpty(targetValue);
            break;
            
        case 'true':
            shouldBeRequired = isBooleanTrue(targetValue);
            break;
            
        case 'false':
            shouldBeRequired = isBooleanFalse(targetValue);
            break;
            
        case 'contains':
            if (typeof targetValue === 'string') {
                shouldBeRequired = normalizedComparisonValues.some(val => 
                    val && targetValue.toLowerCase().includes(String(val).toLowerCase())
                );
            }
            break;
            
        case 'not_contains':
            if (typeof targetValue === 'string') {
                shouldBeRequired = !normalizedComparisonValues.some(val => 
                    val && targetValue.toLowerCase().includes(String(val).toLowerCase())
                );
            } else {
                shouldBeRequired = true;
            }
            break;
            
        case 'starts_with':
            if (typeof targetValue === 'string') {
                shouldBeRequired = normalizedComparisonValues.some(val => 
                    val && targetValue.toLowerCase().startsWith(String(val).toLowerCase())
                );
            }
            break;
            
        case 'ends_with':
            if (typeof targetValue === 'string') {
                shouldBeRequired = normalizedComparisonValues.some(val => 
                    val && targetValue.toLowerCase().endsWith(String(val).toLowerCase())
                );
            }
            break;
            
        case 'regex':
            if (values.length > 0) {
                try {
                    const pattern = new RegExp(values[0], values[1] || '');
                    shouldBeRequired = pattern.test(String(targetValue));
                } catch (e) {
                    console.warn('Invalid regex pattern in required_if validation:', values[0]);
                    shouldBeRequired = false;
                }
            }
            break;
            
        case 'length':
            const targetLength = String(targetValue).length;
            if (values.length === 1) {
                shouldBeRequired = targetLength === parseInt(values[0]);
            } else if (values.length === 2) {
                const minLength = parseInt(values[0]) || 0;
                const maxLength = parseInt(values[1]) || Infinity;
                shouldBeRequired = targetLength >= minLength && targetLength <= maxLength;
            }
            break;
            
        case 'count':
            let count = 0;
            if (Array.isArray(targetValue)) {
                count = targetValue.length;
            } else if (targetElement.type === 'checkbox') {
                const form = targetElement.closest('form');
                const checkedBoxes = form.querySelectorAll(`input[name="${targetElement.name}"]:checked`);
                count = checkedBoxes.length;
            } else if (targetElement.type === 'file') {
                count = targetElement.files ? targetElement.files.length : 0;
            }
            
            if (values.length === 1) {
                shouldBeRequired = count === parseInt(values[0]);
            } else if (values.length === 2) {
                const minCount = parseInt(values[0]) || 0;
                const maxCount = parseInt(values[1]) || Infinity;
                shouldBeRequired = count >= minCount && count <= maxCount;
            }
            break;
    }
    
    if (shouldBeRequired) {
        return validateRequired(value, [], form, attributeType);
    }
    
    return { valid: true };
}

/**
 * Validates if a field is required unless another field meets specific conditions
 * @param {*} value - The field value to validate
 * @param {Array} parameters - Array of parameters [field, operator, ...values]
 * @param {HTMLElement} form - The form element
 * @param {string} attributeType - The attribute type to use ('name' or 'id')
 * @returns {Object} - Object with valid property indicating validation result
 */
function validateRequiredUnless(value, parameters, form, attributeType) {
    try {
        if (parameters.length < 2) return { valid: true };
        
        const [field, operator, ...values] = parameters;
        const targetElement = form.querySelector(`[${attributeType}="${field}"]`);
        
        if (!targetElement) {
            // If target field doesn't exist, assume field is required
            return validateRequired(value, [], form, attributeType);
        }
        
        const targetValue = getFieldValue(targetElement);
        let conditionMet = false;
        
        // Helper function to normalize value for comparison
        const normalizeValue = (val) => {
            // Handle null, undefined, empty string cases
            if (val === null || val === undefined || val === '') {
                return null;
            }
            // Handle string "null" case
            if (typeof val === 'string' && val.toLowerCase() === 'null') {
                return null;
            }
            return String(val);
        };
        
        // Helper function to check if value is considered "null/empty"
        const isNullOrEmpty = (val) => {
            return val === null || 
                   val === undefined || 
                   val === '' || 
                   (typeof val === 'string' && val.toLowerCase() === 'null');
        };
        
        // Helper function to check if value is boolean-like
        const isBooleanTrue = (val) => {
            if (typeof val === 'boolean') return val;
            if (typeof val === 'string') {
                const lower = val.toLowerCase();
                return lower === 'true' || lower === '1' || lower === 'yes' || lower === 'on';
            }
            if (typeof val === 'number') return val !== 0;
            return false;
        };
        
        // Helper function to check if value is boolean false-like
        const isBooleanFalse = (val) => {
            if (typeof val === 'boolean') return !val;
            if (typeof val === 'string') {
                const lower = val.toLowerCase();
                return lower === 'false' || lower === '0' || lower === 'no' || lower === 'off' || lower === '';
            }
            if (typeof val === 'number') return val === 0;
            if (val === null || val === undefined) return true;
            return false;
        };
        
        const normalizedTargetValue = normalizeValue(targetValue);
        const normalizedComparisonValues = values.map(normalizeValue);
        
        // Check condition based on operator
        switch (operator) {
            case '=':
            case '==':
                // Check if target value matches any of the comparison values
                if (normalizedComparisonValues.includes('null') || normalizedComparisonValues.includes(null)) {
                    conditionMet = isNullOrEmpty(targetValue);
                } else {
                    conditionMet = normalizedComparisonValues.includes(normalizedTargetValue);
                }
                break;
                
            case '!=':
            case '!==':
                // Check if target value does NOT match any of the comparison values
                if (normalizedComparisonValues.includes('null') || normalizedComparisonValues.includes(null)) {
                    conditionMet = !isNullOrEmpty(targetValue);
                } else {
                    conditionMet = !normalizedComparisonValues.includes(normalizedTargetValue);
                }
                break;
                
            case '>':
                if (!isNullOrEmpty(targetValue) && !isNaN(targetValue)) {
                    const numTarget = parseFloat(targetValue);
                    conditionMet = normalizedComparisonValues.some(val => 
                        !isNullOrEmpty(val) && !isNaN(val) && numTarget > parseFloat(val)
                    );
                }
                break;
                
            case '>=':
                if (!isNullOrEmpty(targetValue) && !isNaN(targetValue)) {
                    const numTarget = parseFloat(targetValue);
                    conditionMet = normalizedComparisonValues.some(val => 
                        !isNullOrEmpty(val) && !isNaN(val) && numTarget >= parseFloat(val)
                    );
                }
                break;
                
            case '<':
                if (!isNullOrEmpty(targetValue) && !isNaN(targetValue)) {
                    const numTarget = parseFloat(targetValue);
                    conditionMet = normalizedComparisonValues.some(val => 
                        !isNullOrEmpty(val) && !isNaN(val) && numTarget < parseFloat(val)
                    );
                }
                break;
                
            case '<=':
                if (!isNullOrEmpty(targetValue) && !isNaN(targetValue)) {
                    const numTarget = parseFloat(targetValue);
                    conditionMet = normalizedComparisonValues.some(val => 
                        !isNullOrEmpty(val) && !isNaN(val) && numTarget <= parseFloat(val)
                    );
                }
                break;
                
            case 'in':
                conditionMet = normalizedComparisonValues.includes(normalizedTargetValue);
                break;
                
            case 'not_in':
                conditionMet = !normalizedComparisonValues.includes(normalizedTargetValue);
                break;
                
            case 'empty':
                conditionMet = isNullOrEmpty(targetValue);
                break;
                
            case 'not_empty':
                conditionMet = !isNullOrEmpty(targetValue);
                break;
                
            case 'true':
                conditionMet = isBooleanTrue(targetValue);
                break;
                
            case 'false':
                conditionMet = isBooleanFalse(targetValue);
                break;
                
            case 'contains':
                if (typeof targetValue === 'string') {
                    conditionMet = normalizedComparisonValues.some(val => 
                        val && targetValue.toLowerCase().includes(String(val).toLowerCase())
                    );
                }
                break;
                
            case 'not_contains':
                if (typeof targetValue === 'string') {
                    conditionMet = !normalizedComparisonValues.some(val => 
                        val && targetValue.toLowerCase().includes(String(val).toLowerCase())
                    );
                } else {
                    conditionMet = true; // Non-string values don't contain anything
                }
                break;
                
            case 'starts_with':
                if (typeof targetValue === 'string') {
                    conditionMet = normalizedComparisonValues.some(val => 
                        val && targetValue.toLowerCase().startsWith(String(val).toLowerCase())
                    );
                }
                break;
                
            case 'ends_with':
                if (typeof targetValue === 'string') {
                    conditionMet = normalizedComparisonValues.some(val => 
                        val && targetValue.toLowerCase().endsWith(String(val).toLowerCase())
                    );
                }
                break;
                
            case 'regex':
                if (values.length > 0) {
                    try {
                        const pattern = new RegExp(values[0], values[1] || '');
                        conditionMet = pattern.test(String(targetValue));
                    } catch (e) {
                        console.warn('Invalid regex pattern in required_unless validation:', values[0]);
                        conditionMet = false;
                    }
                }
                break;
                
            case 'length':
                const targetLength = String(targetValue).length;
                if (values.length === 1) {
                    // Exact length
                    conditionMet = targetLength === parseInt(values[0]);
                } else if (values.length === 2) {
                    // Length range
                    const minLength = parseInt(values[0]) || 0;
                    const maxLength = parseInt(values[1]) || Infinity;
                    conditionMet = targetLength >= minLength && targetLength <= maxLength;
                }
                break;
                
            case 'count':
                // For arrays, checkboxes, or multiple selects
                let count = 0;
                if (Array.isArray(targetValue)) {
                    count = targetValue.length;
                } else if (targetElement.type === 'checkbox') {
                    const form = targetElement.closest('form');
                    const checkedBoxes = form.querySelectorAll(`input[name="${targetElement.name}"]:checked`);
                    count = checkedBoxes.length;
                } else if (targetElement.type === 'file') {
                    count = targetElement.files ? targetElement.files.length : 0;
                }
                
                if (values.length === 1) {
                    conditionMet = count === parseInt(values[0]);
                } else if (values.length === 2) {
                    const minCount = parseInt(values[0]) || 0;
                    const maxCount = parseInt(values[1]) || Infinity;
                    conditionMet = count >= minCount && count <= maxCount;
                }
                break;
                
            default:
                // Fallback to simple equality check
                conditionMet = normalizedComparisonValues.includes(normalizedTargetValue);
                break;
        }
        
        // For required_unless, the field is NOT required if the condition is met
        if (conditionMet) {
            return { valid: true }; // Condition met, field is optional
        }
        
        // Condition not met, field is required
        const requiredResult = validateRequired(value, [], form, attributeType);
        if (!requiredResult.valid) {
           return requiredResult;
        }
        
        return { valid: true };
        
    } catch (error) {
        console.error('Error in validateRequiredUnless:', error);
        return { 
            valid: false, 
            message: 'Validation error occurred'
        };
    }
}

/**
 * Validates if a value is a string
 * @param {*} value - The value to validate
 * @returns {Object} - Object with valid property indicating validation result
 */
function validateString(value) {
    return { valid: typeof value === 'string' || value === null || value === undefined || value === '' };
}

/**
 * Validates if a value is numeric
 * @param {*} value - The value to validate
 * @returns {Object} - Object with valid property indicating validation result
 */
function validateNumeric(value) {
    if (value === '' || value === null || value === undefined) return { valid: true };
    // Reject if contains 'e' or 'E' (scientific notation)
    if (typeof value === 'string' && /[eE]/.test(value)) return { valid: false };
    return { valid: !isNaN(value) && !isNaN(parseFloat(value)) };
}

/**
 * Validates if a value is an integer
 * @param {*} value - The value to validate
 * @returns {Object} - Object with valid property indicating validation result
 */
function validateInteger(value) {
    if (value === '' || value === null || value === undefined) return { valid: true };
    // Reject if contains 'e' or 'E' (scientific notation)
    if (typeof value === 'string' && /[eE]/.test(value)) return { valid: false };
    return { valid: Number.isInteger(Number(value)) };
}

/**
 * Validates if a value is a valid email address
 * @param {*} value - The value to validate
 * @returns {Object} - Object with valid property indicating validation result
 */
function validateEmail(value) {
    if (value === '' || value === null || value === undefined) return { valid: true };
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return { valid: emailRegex.test(value) };
}

/**
 * Validates if a value is an array or can be converted to an array
 * @param {*} value - The value to validate
 * @returns {Object} - Object with valid property indicating validation result
 */
function validateArray(value) {
    if (value === '' || value === null || value === undefined) return { valid: true };
    
    // Handle comma-separated string as array
    if (typeof value === 'string') {
        value = value.split(',').map(item => item.trim()).filter(item => item !== '');
    }
    
    return { valid: Array.isArray(value) };
}

/**
 * Validates if a value is a file input
 * @param {*} value - The value to validate (expected to be a FileList)
 * @returns {Object} - Object with valid property indicating validation result
 */
function validateFile(value) {
    return { valid: value && value instanceof FileList };
}

/**
 * Validates the size of a value (file size, array length, string length)
 * @param {*} value - The value to validate
 * @param {Array} parameters - Array containing size parameters
 * @param {HTMLElement} element - The form element
 * @returns {Object} - Object with valid property indicating validation result
 */
function validateSize(value, parameters, element) {
    if (!value || value.length === 0) return { valid: true };
    
    const maxSize = parameters[0] ? parseFloat(parameters[0]) : 4; // Default 4MB
    const maxSizeBytes = maxSize * 1024 * 1024;
    
    if (element.type === 'file') {
        for (let file of value) {
            if (file.size > maxSizeBytes) {
                return { valid: false };
            }
        }
    }
    
    return { valid: true };
}

/**
 * Validates file MIME types
 * @param {FileList} value - The files to validate
 * @param {Array} parameters - Array of allowed MIME types
 * @returns {Object} - Object with valid property indicating validation result
 */
function validateMimes(value, parameters) {
    if (!value || value.length === 0) return { valid: true };
    
    const allowedTypes = parameters.map(type => type.toLowerCase());
    
    for (let file of value) {
        const extension = file.name.toLowerCase().split('.').pop();
        if (!allowedTypes.includes(extension)) {
            return { valid: false };
        }
    }
    
    return { valid: true };
}

/**
 * Helper function to check if a value is numeric
 * @param {*} value - The value to check
 * @returns {boolean} - True if the value is numeric, false otherwise
 */
function isNumericValue(value) {
    // Check if value is already a number
    if (typeof value === 'number') {
        return !isNaN(value) && isFinite(value);
    }
    
    // Check if string represents a valid number
    if (typeof value === 'string') {
        const trimmed = value.trim();
        if (trimmed === '' || trimmed === '.' || trimmed === '-' || trimmed === '+') return false;
        
        // More robust numeric validation using regex
        const numericRegex = /^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/;
        if (!numericRegex.test(trimmed)) return false;
        
        const parsed = parseFloat(trimmed);
        return !isNaN(parsed) && isFinite(parsed);
    }
    
    return false;
}

/**
 * Validates if a value meets minimum requirements (length, size, value)
 * @param {*} value - The value to validate
 * @param {Array} parameters - Array containing the minimum value
 * @param {HTMLElement} element - The form element
 * @returns {Object} - Object with valid property indicating validation result
 */
function validateMin(value, parameters, element) {
    if (value === '' || value === null || value === undefined) return { valid: true };
    
    const min = parseFloat(parameters[0]);
    
    if (element.type === 'file') {
        return { valid: value.length >= min };
    }
    
    if (isNumericValue(value)) {
        return { valid: parseFloat(value) >= min };
    }
    
    if (Array.isArray(value) || (typeof value === 'string' && value.includes(','))) {
        const arr = Array.isArray(value) ? value : value.split(',').map(item => item.trim()).filter(item => item !== '');
        return { valid: arr.length >= min };
    }
    
    // Fall back to string length validation
    if (typeof value === 'string') {
        return { valid: value.length >= min };
    }
    
    // For other types, try to parse as number
    return { valid: parseFloat(value) >= min };
}

/**
 * Validates if a value meets maximum requirements (length, size, value)
 * @param {*} value - The value to validate
 * @param {Array} parameters - Array containing the maximum value
 * @param {HTMLElement} element - The form element
 * @returns {Object} - Object with valid property indicating validation result
 */
function validateMax(value, parameters, element) {
    if (value === '' || value === null || value === undefined) return { valid: true };
    
    const max = parseFloat(parameters[0]);
    
    if (element.type === 'file') {
        return { valid: value.length <= max };
    }

    if (isNumericValue(value)) {
        return { valid: parseFloat(value) <= max };
    }
    
    if (Array.isArray(value) || (typeof value === 'string' && value.includes(','))) {
        const arr = Array.isArray(value) ? value : value.split(',').map(item => item.trim()).filter(item => item !== '');
        return { valid: arr.length <= max };
    }
    
    // Fall back to string length validation
    if (typeof value === 'string') {
        return { valid: value.length <= max };
    }
    
    // For other types, try to parse as number
    return { valid: parseFloat(value) <= max };
}

/**
 * Validates if a value is between a minimum and maximum value
 * @param {*} value - The value to validate
 * @param {Array} parameters - Array containing the minimum and maximum values
 * @param {HTMLElement} element - The form element
 * @returns {Object} - Object with valid property indicating validation result
 */
function validateBetween(value, parameters, element) {
    if (value === '' || value === null || value === undefined) return { valid: true };
    
    const [min, max] = parameters.map(p => parseFloat(p));
    
    if (element.type === 'time') {
        const timeToMinutes = (timeStr) => {
            const [hours, minutes] = timeStr.split(':').map(Number);
            return hours * 60 + minutes;
        };
        
        const valueMinutes = timeToMinutes(value);
        const minMinutes = timeToMinutes(parameters[0]);
        const maxMinutes = timeToMinutes(parameters[1]);
        
        return { valid: valueMinutes >= minMinutes && valueMinutes <= maxMinutes };
    }
    
    if (typeof value === 'string') {
        return { valid: value.length >= min && value.length <= max };
    }
    
    const numValue = parseFloat(value);
    return { valid: numValue >= min && numValue <= max };
}

/**
 * Validates if a value is a valid date
 * @param {*} value - The date value to validate
 * @returns {Object} - Object with valid property indicating validation result
 */
function validateDate(value) {
    if (value === '' || value === null || value === undefined) return { valid: true };
    
    const date = new Date(value);
    return { valid: !isNaN(date.getTime()) };
}

/**
 * Validates if a date value matches the specified format
 * @param {string} value - The date string to validate
 * @param {Array} parameters - Array containing the date format
 * @returns {Object} - Object with valid property indicating validation result
 */
function validateDateFormat(value, parameters) {
    if (value === '' || value === null || value === undefined) return { valid: true };
    
    const format = parameters[0];
    if (!format) return { valid: false };
    
    // Date format patterns mapping
    const formatPatterns = {
        // Year formats
        'Y-m-d': /^\d{4}-\d{1,2}-\d{1,2}$/,
        'YYYY-MM-DD': /^\d{4}-\d{2}-\d{2}$/,
        'Y/m/d': /^\d{4}\/\d{1,2}\/\d{1,2}$/,
        'YYYY/MM/DD': /^\d{4}\/\d{2}\/\d{2}$/,
        
        // Month/Day/Year formats (US style)
        'm/d/Y': /^\d{1,2}\/\d{1,2}\/\d{4}$/,
        'MM/DD/YYYY': /^\d{2}\/\d{2}\/\d{4}$/,
        'm-d-Y': /^\d{1,2}-\d{1,2}-\d{4}$/,
        'MM-DD-YYYY': /^\d{2}-\d{2}-\d{4}$/,
        
        // Day/Month/Year formats (European style)
        'd/m/Y': /^\d{1,2}\/\d{1,2}\/\d{4}$/,
        'DD/MM/YYYY': /^\d{2}\/\d{2}\/\d{4}$/,
        'd-m-Y': /^\d{1,2}-\d{1,2}-\d{4}$/,
        'DD-MM-YYYY': /^\d{2}-\d{2}-\d{4}$/,
        'd.m.Y': /^\d{1,2}\.\d{1,2}\.\d{4}$/,
        'DD.MM.YYYY': /^\d{2}\.\d{2}\.\d{4}$/,
        
        // With time formats
        'Y-m-d H:i': /^\d{4}-\d{1,2}-\d{1,2} \d{1,2}:\d{2}$/,
        'YYYY-MM-DD HH:mm': /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/,
        'Y-m-d H:i:s': /^\d{4}-\d{1,2}-\d{1,2} \d{1,2}:\d{2}:\d{2}$/,
        'YYYY-MM-DD HH:mm:ss': /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/,
        
        // Time only formats
        'H:i': /^\d{1,2}:\d{2}$/,
        'HH:mm': /^\d{2}:\d{2}$/,
        'H:i:s': /^\d{1,2}:\d{2}:\d{2}$/,
        'HH:mm:ss': /^\d{2}:\d{2}:\d{2}$/,
        'h:i A': /^\d{1,2}:\d{2} (AM|PM)$/i,
        'hh:mm A': /^\d{2}:\d{2} (AM|PM)$/i,
        
        // ISO formats
        'c': /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}([+-]\d{2}:\d{2}|Z)$/,
        'ISO8601': /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{3})?([+-]\d{2}:\d{2}|Z)$/,
        
        // Month/Year formats
        'm/Y': /^\d{1,2}\/\d{4}$/,
        'MM/YYYY': /^\d{2}\/\d{4}$/,
        'm-Y': /^\d{1,2}-\d{4}$/,
        'MM-YYYY': /^\d{2}-\d{4}$/,
        
        // Text date formats
        'F j, Y': /^[A-Za-z]+ \d{1,2}, \d{4}$/,
        'M j, Y': /^[A-Za-z]{3} \d{1,2}, \d{4}$/,
        'j F Y': /^\d{1,2} [A-Za-z]+ \d{4}$/,
        'j M Y': /^\d{1,2} [A-Za-z]{3} \d{4}$/
    };
    
    const regex = formatPatterns[format];
    if (!regex) {
        console.warn(`Unsupported date format: ${format}`);
        return { valid: false };
    }
    
    // Check regex pattern first
    if (!regex.test(value)) {
        return { valid: false };
    }
    
    // Additional validation based on format type
    return validateDateLogic(value, format);
}

/**
 * Helper function to validate a date string against a specific format
 * @param {string} value - The date string to validate
 * @param {string} format - The date format to validate against
 * @returns {Object} - Object with valid property indicating validation result
 */
function validateDateLogic(value, format) {
    try {
        let date;
        
        // Handle different date parsing based on format
        if (format.includes('c') || format.includes('ISO8601')) {
            // ISO format
            date = new Date(value);
        } else if (format.match(/^[HhMmSsAi:\s]+$/)) {
            // Time only formats - create date with today's date
            const today = new Date().toISOString().split('T')[0];
            const timeValue = value.replace(/AM|PM/i, '').trim();
            let [hours, minutes, seconds = '00'] = timeValue.split(':');
            
            if (value.match(/PM/i) && parseInt(hours) !== 12) {
                hours = parseInt(hours) + 12;
            } else if (value.match(/AM/i) && parseInt(hours) === 12) {
                hours = '00';
            }
            
            date = new Date(`${today}T${hours.padStart(2, '0')}:${minutes}:${seconds}`);
        } else if (format.includes('/')) {
            // Handle different slash-separated formats
            const parts = value.split('/');
            if (format.startsWith('Y') || format.startsWith('YYYY')) {
                // YYYY/MM/DD
                date = new Date(parts[0], parts[1] - 1, parts[2]);
            } else if (format.startsWith('m') || format.startsWith('MM')) {
                // MM/DD/YYYY (US format)
                date = new Date(parts[2], parts[0] - 1, parts[1]);
            } else if (format.startsWith('d') || format.startsWith('DD')) {
                // DD/MM/YYYY (European format)
                date = new Date(parts[2], parts[1] - 1, parts[0]);
            }
        } else if (format.includes('-')) {
            // Handle different dash-separated formats
            const parts = value.split(/[-\s]/);
            if (format.startsWith('Y') || format.startsWith('YYYY')) {
                // YYYY-MM-DD format
                const datePart = parts[0] + '-' + parts[1] + '-' + parts[2];
                if (parts.length > 3) {
                    // Has time component
                    const timeParts = parts.slice(3).join(':');
                    date = new Date(datePart + 'T' + timeParts);
                } else {
                    date = new Date(datePart);
                }
            } else if (format.startsWith('m') || format.startsWith('MM')) {
                // MM-DD-YYYY (US format)
                date = new Date(parts[2], parts[0] - 1, parts[1]);
            } else if (format.startsWith('d') || format.startsWith('DD')) {
                // DD-MM-YYYY (European format)
                date = new Date(parts[2], parts[1] - 1, parts[0]);
            }
        } else if (format.includes('.')) {
            // Handle dot-separated formats (European)
            const parts = value.split('.');
            date = new Date(parts[2], parts[1] - 1, parts[0]);
        } else if (format.includes('F') || format.includes('M')) {
            // Handle text month formats
            date = new Date(value);
        } else {
            // Default parsing
            date = new Date(value);
        }
        
        // Validate the date is valid
        if (isNaN(date.getTime())) {
            return { valid: false };
        }
        
        // Additional checks for specific formats
        if (format === 'Y-m-d' || format === 'YYYY-MM-DD') {
            const formatted = date.toISOString().split('T')[0];
            const normalizedValue = value.length === 8 ? 
                `${value.slice(0,4)}-${value.slice(4,6).padStart(2,'0')}-${value.slice(6,8).padStart(2,'0')}` : 
                value;
            return { valid: formatted === normalizedValue || formatted === value };
        }
        
        // For month/year validation
        if (format.match(/^m{1,2}[-\/]Y{4}$/)) {
            const parts = value.split(/[-\/]/);
            const month = parseInt(parts[0]);
            return { valid: month >= 1 && month <= 12 };
        }
        
        return { valid: true };
        
    } catch (error) {
        return { valid: false };
    }
}

/**
 * Validates if a date is after another date
 * @param {string} value - The date string to validate
 * @param {Array} parameters - Array containing the reference date
 * @returns {Object} - Object with valid property indicating validation result
 */
function validateAfter(value, parameters) {
    if (value === '' || value === null || value === undefined) return { valid: true };
    
    const compareDate = new Date(parameters[0]);
    const valueDate = new Date(value);
    
    if (isNaN(compareDate.getTime()) || isNaN(valueDate.getTime())) {
        return { valid: false };
    }
    
    return { valid: valueDate > compareDate };
}

/**
 * Validates if a date is before another date
 * @param {string} value - The date string to validate
 * @param {Array} parameters - Array containing the reference date
 * @returns {Object} - Object with valid property indicating validation result
 */
function validateBefore(value, parameters) {
    if (value === '' || value === null || value === undefined) return { valid: true };
    
    const compareDate = new Date(parameters[0]);
    const valueDate = new Date(value);
    
    if (isNaN(compareDate.getTime()) || isNaN(valueDate.getTime())) {
        return { valid: false };
    }
    
    return { valid: valueDate < compareDate };
}

/**
 * Validates if a date is after or equal to another date
 * @param {string} value - The date string to validate
 * @param {Array} parameters - Array containing the reference date or field name
 * @param {HTMLElement} form - The form element
 * @param {string} attributeType - The attribute type to use ('name' or 'id')
 * @returns {Object} - Object with valid property indicating validation result
 */
function validateAfterOrEqual(value, parameters, form, attributeType) {
    if (value === '' || value === null || value === undefined) return { valid: true };
    
    let compareDate;
    
    // Check if parameter is a field name
    const compareElement = form.querySelector(`[${attributeType}="${parameters[0]}"]`);
    if (compareElement) {
        const compareValue = getFieldValue(compareElement);
        compareDate = new Date(compareValue);
    } else {
        compareDate = new Date(parameters[0]);
    }
    
    const valueDate = new Date(value);
    
    if (isNaN(compareDate.getTime()) || isNaN(valueDate.getTime())) {
        return { valid: false };
    }
    
    return { valid: valueDate >= compareDate };
}

/**
 * Validates if a date is before or equal to another date
 * @param {string} value - The date string to validate
 * @param {Array} parameters - Array containing the reference date or field name
 * @param {HTMLElement} form - The form element
 * @param {string} attributeType - The attribute type to use ('name' or 'id')
 * @returns {Object} - Object with valid property indicating validation result
 */
function validateBeforeOrEqual(value, parameters, form, attributeType) {
    if (value === '' || value === null || value === undefined) return { valid: true };
    
    let compareDate;
    
    // Check if parameter is a field name
    const compareElement = form.querySelector(`[${attributeType}="${parameters[0]}"]`);
    if (compareElement) {
        const compareValue = getFieldValue(compareElement);
        compareDate = new Date(compareValue);
    } else {
        compareDate = new Date(parameters[0]);
    }
    
    const valueDate = new Date(value);
    
    if (isNaN(compareDate.getTime()) || isNaN(valueDate.getTime())) {
        return { valid: false };
    }
    
    return { valid: valueDate <= compareDate };
}

/**
 * Validates if a date falls on a weekend (Saturday or Sunday)
 * @param {string} value - The date string to validate
 * @returns {Object} - Object with valid property indicating validation result
 */
function validateWeekend(value) {
    if (value === '' || value === null || value === undefined) return { valid: true };
    
    const date = new Date(value);
    if (isNaN(date.getTime())) return { valid: false };
    
    const dayOfWeek = date.getDay(); // 0 = Sunday, 6 = Saturday
    return { valid: dayOfWeek === 0 || dayOfWeek === 6 };
}

/**
 * Validates if a value is a valid time in 24-hour format (HH:MM)
 * @param {string} value - The time string to validate
 * @returns {Object} - Object with valid property indicating validation result
 */
function validateTime(value) {
    if (value === '' || value === null || value === undefined) return { valid: true };
    
    const timeRegex = /^([01]?[0-9]|2[0-3]):[0-5][0-9]$/;
    return { valid: timeRegex.test(value) };
}

/**
 * Validates if a value is a valid URL
 * @param {string} value - The URL string to validate
 * @returns {Object} - Object with valid property indicating validation result
 */
function validateUrl(value) {
    if (value === '' || value === null || value === undefined) return { valid: true };
    
    try {
        new URL(value);
        return { valid: true };
    } catch {
        return { valid: false };
    }
}

/**
 * Validates if a value represents a boolean
 * @param {*} value - The value to validate
 * @returns {Object} - Object with valid property indicating validation result
 */
function validateBoolean(value) {
    if (value === '' || value === null || value === undefined) return { valid: true };
    
    const booleanValues = ['true', 'false', '1', '0', 1, 0, true, false];
    return { valid: booleanValues.includes(value) };
}

/**
 * Validates if a value matches its confirmation field
 * @param {*} value - The value to validate
 * @param {HTMLElement} element - The form element
 * @param {HTMLElement} form - The form element
 * @param {string} attributeType - The attribute type to use ('name' or 'id')
 * @returns {Object} - Object with valid property indicating validation result
 */
function validateConfirmed(value, element, form, attributeType) {
    const confirmationFieldName = element.getAttribute(attributeType) + '_confirmation';
    const confirmationElement = form.querySelector(`[${attributeType}="${confirmationFieldName}"]`);
    
    if (!confirmationElement) {
        return { valid: false, message: 'Confirmation field not found' };
    }
    
    const confirmationValue = getFieldValue(confirmationElement);
    return { valid: value === confirmationValue };
}

/**
 * Validates if a value contains only alphabetic characters
 * @param {string} value - The string to validate
 * @returns {Object} - Object with valid property indicating validation result
 */
function validateAlpha(value) {
    if (value === '' || value === null || value === undefined) return { valid: true };
    
    const alphaRegex = /^[a-zA-Z]+$/;
    return { valid: alphaRegex.test(value) };
}

/**
 * Validates if a value contains only alphanumeric characters
 * @param {string} value - The string to validate
 * @returns {Object} - Object with valid property indicating validation result
 */
function validateAlphaNum(value) {
    if (value === '' || value === null || value === undefined) return { valid: true };
    
    const alphaNumRegex = /^[a-zA-Z0-9]+$/;
    return { valid: alphaNumRegex.test(value) };
}

/**
 * Validates if a value is the same as another field's value
 * @param {*} value - The value to validate
 * @param {Array} parameters - Array containing the field name to compare with
 * @param {HTMLElement} form - The form element
 * @param {string} attributeType - The attribute type to use ('name' or 'id')
 * @returns {Object} - Object with valid property indicating validation result
 */
function validateSame(value, parameters, form, attributeType) {
    const compareElement = form.querySelector(`[${attributeType}="${parameters[0]}"]`);
    
    if (!compareElement) {
        return { valid: false, message: 'Comparison field not found' };
    }
    
    const compareValue = getFieldValue(compareElement);
    return { valid: value === compareValue };
}

/**
 * Validates if a value is different from another field's value
 * @param {*} value - The value to validate
 * @param {Array} parameters - Array containing the field name to compare with
 * @param {HTMLElement} form - The form element
 * @param {string} attributeType - The attribute type to use ('name' or 'id')
 * @returns {Object} - Object with valid property indicating validation result
 */
function validateDifferent(value, parameters, form, attributeType) {
    const compareElement = form.querySelector(`[${attributeType}="${parameters[0]}"]`);
    
    if (!compareElement) {
        return { valid: true }; // If comparison field doesn't exist, validation passes
    }
    
    const compareValue = getFieldValue(compareElement);
    return { valid: value !== compareValue };
}

/**
 * Validates if a value is in a list of allowed values
 * @param {*} value - The value to validate
 * @param {Array} parameters - Array of allowed values
 * @returns {Object} - Object with valid property indicating validation result
 */
function validateIn(value, parameters) {
    if (value === '' || value === null || value === undefined) return { valid: true };
    
    return { valid: parameters.includes(String(value)) };
}

/**
 * Validates if a value is not in a list of disallowed values
 * @param {*} value - The value to validate
 * @param {Array} parameters - Array of disallowed values
 * @returns {Object} - Object with valid property indicating validation result
 */
function validateNotIn(value, parameters) {
    if (value === '' || value === null || value === undefined) return { valid: true };
    
    return { valid: !parameters.includes(String(value)) };
}

function validateRegex(value, parameters) {
    if (value === '' || value === null || value === undefined) return { valid: true };
    
    try {
        const regexString = parameters[0];
        // Remove leading and trailing slashes if present
        const cleanRegex = regexString.replace(/^\/|\/$/g, '');
        const regex = new RegExp(cleanRegex);
        return { valid: regex.test(value) };
    } catch (error) {
        return { valid: false, message: 'Invalid regex pattern' };
    }
}

function validateJson(value) {
    if (value === '' || value === null || value === undefined) return { valid: true };
    
    try {
        JSON.parse(value);
        return { valid: true };
    } catch {
        return { valid: false };
    }
}

function validateIp(value) {
    if (value === '' || value === null || value === undefined) return { valid: true };
    
    return { 
        valid: validateIpv4(value).valid || validateIpv6(value).valid 
    };
}

function validateIpv4(value) {
    if (value === '' || value === null || value === undefined) return { valid: true };
    
    const ipv4Regex = /^((25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/;
    return { valid: ipv4Regex.test(value) };
}

function validateIpv6(value) {
    if (value === '' || value === null || value === undefined) return { valid: true };
    
    const ipv6Regex = /^(([0-9a-fA-F]{1,4}:){7,7}[0-9a-fA-F]{1,4}|([0-9a-fA-F]{1,4}:){1,7}:|([0-9a-fA-F]{1,4}:){1,6}:[0-9a-fA-F]{1,4}|([0-9a-fA-F]{1,4}:){1,5}(:[0-9a-fA-F]{1,4}){1,2}|([0-9a-fA-F]{1,4}:){1,4}(:[0-9a-fA-F]{1,4}){1,3}|([0-9a-fA-F]{1,4}:){1,3}(:[0-9a-fA-F]{1,4}){1,4}|([0-9a-fA-F]{1,4}:){1,2}(:[0-9a-fA-F]{1,4}){1,5}|[0-9a-fA-F]{1,4}:((:[0-9a-fA-F]{1,4}){1,6})|:((:[0-9a-fA-F]{1,4}){1,7}|:)|fe80:(:[0-9a-fA-F]{0,4}){0,4}%[0-9a-zA-Z]{1,}|::(ffff(:0{1,4}){0,1}:){0,1}((25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9])\.){3,3}(25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9])|([0-9a-fA-F]{1,4}:){1,4}:((25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9])\.){3,3}(25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9]))$/;
    return { valid: ipv6Regex.test(value) };
}

function validateUuid(value) {
    if (value === '' || value === null || value === undefined) return { valid: true };
    
    const uuidRegex = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
    return { valid: uuidRegex.test(value) };
}

function validateDigits(value, parameters) {
    if (value === '' || value === null || value === undefined) return { valid: true };
    
    const expectedLength = parseInt(parameters[0]);
    const digitsRegex = /^\d+$/;
    
    return { 
        valid: digitsRegex.test(value) && String(value).length === expectedLength 
    };
}

function validateDigitsBetween(value, parameters) {
    if (value === '' || value === null || value === undefined) return { valid: true };
    
    const minLength = parseInt(parameters[0]);
    const maxLength = parseInt(parameters[1]);
    const digitsRegex = /^\d+$/;
    const length = String(value).length;
    
    return { 
        valid: digitsRegex.test(value) && length >= minLength && length <= maxLength 
    };
}

function validateCurrency(value, parameters) {
    if (value === '' || value === null || value === undefined) return { valid: true };
    
    const maxLength = parameters[0] ? parseInt(parameters[0]) : 16;
    const valueStr = String(value);
    
    if (valueStr.length > maxLength) {
        return { valid: false };
    }
    
    if (/[eE]/.test(valueStr)) {
        return { valid: false };
    }
    
    // Currency regex: only numbers, comma, and dot
    // Allows patterns like: 123, 123.45, 1,234.56, 1234, .50, etc.
    const currencyRegex = /^[0-9,\.]+$/;
    
    if (!currencyRegex.test(valueStr)) {
        return { valid: false };
    }
    
    const parts = valueStr.split('.');
    
    if (parts.length > 2) {
        return { valid: false };
    }
    
    if (parts.length === 2) {
        const decimalPart = parts[1];
        if (!/^\d+$/.test(decimalPart)) {
            return { valid: false };
        }
    }
    
    const integerPart = parts[0];
    if (!/^[0-9,]*$/.test(integerPart)) {
        return { valid: false };
    }
    
    return { valid: true };
}

function validateMinLength(value, parameters) {
    if (value === '' || value === null || value === undefined) return { valid: true };
    
    try {
        const minLength = parseInt(parameters[0]);
        const valueStr = String(value);
        
        return { valid: valueStr.length >= minLength };
    } catch (error) {
        console.error('Error in min_length validation:', error);
        return { valid: false };
    }
}

function validateMaxLength(value, parameters) {
    if (value === '' || value === null || value === undefined) return { valid: true };
    
    try {
        const maxLength = parseInt(parameters[0]);
        const valueStr = String(value);
        
        return { valid: valueStr.length <= maxLength };
    } catch (error) {
        console.error('Error in max_length validation:', error);
        return { valid: false };
    }
}

function validateAlphaDash(value) {
    if (value === '' || value === null || value === undefined) return { valid: true };
    
    try {
        // Allows alpha-numeric characters, dashes, and underscores
        const alphaDashRegex = /^[a-zA-Z0-9_-]+$/;
        return { valid: alphaDashRegex.test(value) };
    } catch (error) {
        console.error('Error in alpha_dash validation:', error);
        return { valid: false };
    }
}

function validateLowercase(value) {
    if (value === '' || value === null || value === undefined) return { valid: true };
    
    try {
        // Check if the string is all lowercase
        return { valid: String(value).toLowerCase() === String(value) };
    } catch (error) {
        console.error('Error in lowercase validation:', error);
        return { valid: false };
    }
}

function validateUppercase(value) {
    if (value === '' || value === null || value === undefined) return { valid: true };
    
    try {
        // Check if the string is all uppercase
        return { valid: String(value).toUpperCase() === String(value) };
    } catch (error) {
        console.error('Error in uppercase validation:', error);
        return { valid: false };
    }
}

function validateDecimal(value, parameters) {
    if (value === '' || value === null || value === undefined) return { valid: true };
    
    try {
        // Default values
        let minDecimalPlaces = 1;
        let maxDecimalPlaces = null;
        
        // Parse parameters if provided
        if (parameters && parameters.length > 0) {
            if (parameters.length === 1) {
                // Single parameter: exact number of decimal places
                minDecimalPlaces = parseInt(parameters[0]);
                maxDecimalPlaces = minDecimalPlaces;
            } else if (parameters.length >= 2) {
                // Min and max decimal places
                minDecimalPlaces = parseInt(parameters[0]);
                maxDecimalPlaces = parseInt(parameters[1]);
            }
        }
        
        // Check if value is a valid number
        if (!/^[+-]?\d*\.?\d+$/.test(String(value))) {
            return { valid: false };
        }
        
        // Extract decimal part
        const parts = String(value).split('.');
        if (parts.length === 1) {
            // No decimal part
            return { valid: minDecimalPlaces === 0 };
        }
        
        const decimalPlaces = parts[1].length;
        
        // Check if decimal places match requirements
        if (maxDecimalPlaces !== null) {
            return { valid: decimalPlaces >= minDecimalPlaces && decimalPlaces <= maxDecimalPlaces };
        } else {
            return { valid: decimalPlaces >= minDecimalPlaces };
        }
    } catch (error) {
        console.error('Error in decimal validation:', error);
        return { valid: false };
    }
}

function validateGreaterThan(value, parameters, form, attributeType) {
    if (value === '' || value === null || value === undefined) return { valid: true };
    
    try {
        if (parameters.length === 0) return { valid: false };
        
        let compareValue;
        
        // Check if parameter is a field name
        const compareElement = form.querySelector(`[${attributeType}="${parameters[0]}"]`);
        if (compareElement) {
            compareValue = getFieldValue(compareElement);
        } else {
            compareValue = parameters[0];
        }
        
        // If both are numeric, compare as numbers
        if (isNumericValue(value) && isNumericValue(compareValue)) {
            return { valid: parseFloat(value) > parseFloat(compareValue) };
        }
        
        // Otherwise compare as strings
        return { valid: String(value).length > String(compareValue).length };
    } catch (error) {
        console.error('Error in gt validation:', error);
        return { valid: false };
    }
}

function validateLessThan(value, parameters, form, attributeType) {
    if (value === '' || value === null || value === undefined) return { valid: true };
    
    try {
        if (parameters.length === 0) return { valid: false };
        
        let compareValue;
        
        // Check if parameter is a field name
        const compareElement = form.querySelector(`[${attributeType}="${parameters[0]}"]`);
        if (compareElement) {
            compareValue = getFieldValue(compareElement);
        } else {
            compareValue = parameters[0];
        }
        
        // If both are numeric, compare as numbers
        if (isNumericValue(value) && isNumericValue(compareValue)) {
            return { valid: parseFloat(value) < parseFloat(compareValue) };
        }
        
        // Otherwise compare as strings
        return { valid: String(value).length < String(compareValue).length };
    } catch (error) {
        console.error('Error in lt validation:', error);
        return { valid: false };
    }
}

function validateLessThanOrEqual(value, parameters, form, attributeType) {
    if (value === '' || value === null || value === undefined) return { valid: true };
    
    try {
        if (parameters.length === 0) return { valid: false };
        
        let compareValue;
        
        // Check if parameter is a field name
        const compareElement = form.querySelector(`[${attributeType}="${parameters[0]}"]`);
        if (compareElement) {
            compareValue = getFieldValue(compareElement);
        } else {
            compareValue = parameters[0];
        }
        
        // If both are numeric, compare as numbers
        if (isNumericValue(value) && isNumericValue(compareValue)) {
            return { valid: parseFloat(value) <= parseFloat(compareValue) };
        }
        
        // Otherwise compare as strings
        return { valid: String(value).length <= String(compareValue).length };
    } catch (error) {
        console.error('Error in lte validation:', error);
        return { valid: false };
    }
}

function validateDimensions(value, parameters) {
    if (value === '' || value === null || value === undefined || !value || !value.length) return { valid: true };
    
    try {
        // Early return if no files
        if (!(value instanceof FileList) || value.length === 0) return { valid: false };
        
        // Parse parameters
        const constraints = {};
        for (const param of parameters) {
            const [key, val] = param.split('=');
            if (key && val) {
                constraints[key.trim()] = parseInt(val.trim());
            }
        }
        
        // We can only validate client-side for certain image file types
        const file = value[0];
        const validImageTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'];
        
        if (!validImageTypes.includes(file.type)) {
            // If not an image we can process, return valid and let server validate
            return { valid: true };
        }
        
        return new Promise((resolve) => {
            const img = new Image();
            const objectURL = URL.createObjectURL(file);
            
            img.onload = function() {
                URL.revokeObjectURL(objectURL);
                const width = img.width;
                const height = img.height;
                let valid = true;
                
                // Check each constraint
                if (constraints.min_width && width < constraints.min_width) valid = false;
                if (constraints.max_width && width > constraints.max_width) valid = false;
                if (constraints.min_height && height < constraints.min_height) valid = false;
                if (constraints.max_height && height > constraints.max_height) valid = false;
                if (constraints.width && width !== constraints.width) valid = false;
                if (constraints.height && height !== constraints.height) valid = false;
                
                resolve({ valid });
            };
            
            img.onerror = function() {
                URL.revokeObjectURL(objectURL);
                resolve({ valid: false });
            };
            
            img.src = objectURL;
        });
    } catch (error) {
        console.error('Error in dimensions validation:', error);
        return { valid: false };
    }
}

function validateNullable() {
    // Always valid - just a marker that null values are allowed
    return { valid: true };
}

function validateRequiredWith(value, parameters, form, attributeType) {
    try {
        // Check if any of the specified fields have values
        let anyFieldHasValue = false;
        
        for (const fieldName of parameters) {
            const fieldElement = form.querySelector(`[${attributeType}="${fieldName}"]`);
            if (!fieldElement) continue;
            
            const fieldValue = getFieldValue(fieldElement);
            if (fieldValue !== null && fieldValue !== undefined && String(fieldValue).trim() !== '') {
                anyFieldHasValue = true;
                break;
            }
        }
        
        // If any field has value, then validate as required
        if (anyFieldHasValue) {
            return validateRequired(value);
        }
        
        // Otherwise, field is optional
        return { valid: true };
    } catch (error) {
        console.error('Error in required_with validation:', error);
        return { valid: false };
    }
}

function validateContains(value, parameters) {
    if (value === '' || value === null || value === undefined) return { valid: true };
    
    try {
        const valueStr = String(value);
        for (const param of parameters) {
            if (!valueStr.includes(param)) {
                return { valid: false };
            }
        }
        
        return { valid: true };
    } catch (error) {
        console.error('Error in contains validation:', error);
        return { valid: false };
    }
}

function validateDoesntContain(value, parameters) {
    if (value === '' || value === null || value === undefined) return { valid: true };
    
    try {
        const valueStr = String(value);
        for (const param of parameters) {
            if (valueStr.includes(param)) {
                return { valid: false };
            }
        }
        
        return { valid: true };
    } catch (error) {
        console.error('Error in doesnt_contain validation:', error);
        return { valid: false };
    }
}

function validateAccepted(value) {
    try {
        const acceptedValues = [true, 'true', 1, '1', 'yes', 'on'];
        return { valid: acceptedValues.includes(value) };
    } catch (error) {
        console.error('Error in accepted validation:', error);
        return { valid: false };
    }
}

function validateImage(value) {
    if (value === '' || value === null || value === undefined || !value) return { valid: true };
    
    try {
        if (!(value instanceof FileList)) return { valid: false };
        if (value.length === 0) return { valid: true }; // Empty is valid if not required
        
        const file = value[0];
        const validImageTypes = [
            'image/jpeg', 
            'image/png', 
            'image/gif', 
            'image/webp', 
            'image/svg+xml', 
            'image/bmp',
            'image/tiff'
        ];
        
        return { valid: validImageTypes.includes(file.type) };
    } catch (error) {
        console.error('Error in image validation:', error);
        return { valid: false };
    }
}

/**
    * Get error message for a validation rule
    * @param {string} fieldName - Field name
    * @param {Object} rule - Rule object
    * @param {Object} fieldMessages - Custom field messages
    * @param {string} fieldLabel - Field label
    * @param {string} customMessage - Custom error message from validation
    * @returns {string} - Error message
    */
function getErrorMessage(fieldName, rule, fieldMessages, fieldLabel, customMessage) {
    const { name, parameters } = rule;
    
    // Helper function to replace all placeholders in a message
    const replacePlaceholders = (message) => {
        // Basic placeholders
        let replacedMessage = message
            .replace(/:label/g, fieldLabel)
            .replace(/:attribute/g, fieldLabel)
            .replace(/:field/g, fieldName)
            .replace(/:value/g, 'the input');
        
        // Parameter placeholders
        if (parameters && parameters.length > 0) {
            // Replace indexed parameters: :param[0], :param[1], etc.
            parameters.forEach((param, index) => {
                replacedMessage = replacedMessage.replace(
                    new RegExp(`:param\\[${index}\\]`, 'g'), 
                    param
                );
            });
            
            // Common named placeholders
            replacedMessage = replacedMessage
                .replace(/:min/g, parameters[0])
                .replace(/:max/g, parameters.length > 1 ? parameters[1] : parameters[0]);
                
            // Special handling for specific rules
            switch (name) {
                case 'between':
                    replacedMessage = replacedMessage
                        .replace(/:min_value/g, parameters[0])
                        .replace(/:max_value/g, parameters[1]);
                    break;
                case 'mimes':
                case 'in':
                case 'not_in':
                    replacedMessage = replacedMessage
                        .replace(/:values/g, parameters.join(', '));
                    break;
                case 'dimensions':
                    // Extract constraint values from parameters like 'min_width=100'
                    const constraints = {};
                    parameters.forEach(param => {
                        const [key, val] = param.split('=');
                        if (key && val) {
                            constraints[key.trim()] = val.trim();
                            replacedMessage = replacedMessage
                                .replace(new RegExp(`:${key.trim()}`, 'g'), val.trim());
                        }
                    });
                    break;
            }
        }
        
        return replacedMessage;
    };
    
    // Check for custom message for this specific rule
    if (fieldMessages[name]) {
        return replacePlaceholders(fieldMessages[name]);
    }
    
    // Use custom message from validation if available
    if (customMessage) {
        return replacePlaceholders(customMessage);
    }
    
    // Default messages
    const defaultMessages = {
        required: `The ${fieldLabel} field is required.`,
        required_if: `The ${fieldLabel} field is required when specified conditions are met.`,
        string: `The ${fieldLabel} field must be a string.`,
        numeric: `The ${fieldLabel} field must be a number.`,
        integer: `The ${fieldLabel} field must be an integer.`,
        email: `The ${fieldLabel} field must be a valid email address.`,
        array: `The ${fieldLabel} field must be an array.`,
        file: `The ${fieldLabel} field must be a file.`,
        size: `The ${fieldLabel} file size must not exceed ${parameters[0] || 8}MB.`,
        mimes: `The ${fieldLabel} file must be a file of type: ${parameters.join(', ')}.`,
        min: `The ${fieldLabel} field must be at least ${parameters[0]}.`,
        max: `The ${fieldLabel} field must not be greater than ${parameters[0]}.`,
        between: `The ${fieldLabel} field must be between ${parameters[0]} and ${parameters[1]}.`,
        date: `The ${fieldLabel} field must be a valid date.`,
        date_format: `The ${fieldLabel} field must match the format ${parameters[0]}.`,
        after: `The ${fieldLabel} field must be a date after ${parameters[0]}.`,
        before: `The ${fieldLabel} field must be a date before ${parameters[0]}.`,
        after_or_equal: `The ${fieldLabel} field must be a date after or equal to ${parameters[0]}.`,
        before_or_equal: `The ${fieldLabel} field must be a date before or equal to ${parameters[0]}.`,
        weekend: `The ${fieldLabel} field must be a weekend date.`,
        time: `The ${fieldLabel} field must be a valid time.`,
        url: `The ${fieldLabel} field must be a valid URL.`,
        boolean: `The ${fieldLabel} field must be true or false.`,
        confirmed: `The ${fieldLabel} confirmation does not match.`,
        alpha: `The ${fieldLabel} field must contain only letters.`,
        alpha_num: `The ${fieldLabel} field must contain only letters and numbers.`,
        same: `The ${fieldLabel} field must match ${parameters[0]}.`,
        different: `The ${fieldLabel} field must be different from ${parameters[0]}.`,
        in: `The selected ${fieldLabel} is invalid.`,
        not_in: `The selected ${fieldLabel} is invalid.`,
        regex: `The ${fieldLabel} field format is invalid.`,
        json: `The ${fieldLabel} field must be a valid JSON string.`,
        ip: `The ${fieldLabel} field must be a valid IP address.`,
        ipv4: `The ${fieldLabel} field must be a valid IPv4 address.`,
        ipv6: `The ${fieldLabel} field must be a valid IPv6 address.`,
        uuid: `The ${fieldLabel} field must be a valid UUID.`,
        digits: `The ${fieldLabel} field must be ${parameters[0]} digits.`,
        digits_between: `The ${fieldLabel} field must be between ${parameters[0]} and ${parameters[1]} digits.`,
        min_length: `The ${fieldLabel} must be at least ${parameters[0]} characters.`,
        max_length: `The ${fieldLabel} may not be greater than ${parameters[0]} characters.`,
        alpha_dash: `The ${fieldLabel} may only contain letters, numbers, dashes and underscores.`,
        lowercase: `The ${fieldLabel} must be lowercase.`,
        uppercase: `The ${fieldLabel} must be uppercase.`,
        decimal: `The ${fieldLabel} must have ${parameters.length > 1 ? `${parameters[0]} to ${parameters[1]}` : parameters[0]} decimal places.`,
        gt: `The ${fieldLabel} must be greater than ${parameters[0]}.`,
        lt: `The ${fieldLabel} must be less than ${parameters[0]}.`,
        lte: `The ${fieldLabel} must be less than or equal to ${parameters[0]}.`,
        dimensions: `The ${fieldLabel} has invalid image dimensions.`,
        required_with: `The ${fieldLabel} field is required when ${parameters.join(', ')} is present.`,
        required_unless: `The ${fieldLabel} field is required unless ${parameters[0]} is in ${parameters.slice(1).join(', ')}.`,
        contains: `The ${fieldLabel} field must contain: ${parameters.join(', ')}.`,
        doesnt_contain: `The ${fieldLabel} field must not contain: ${parameters.join(', ')}.`,
        accepted: `The ${fieldLabel} must be accepted.`,
        image: `The ${fieldLabel} must be an image.`
    };
    
    const defaultMessage = defaultMessages[name] || `The ${fieldLabel} field is invalid.`;
    return replacePlaceholders(defaultMessage);
}

/**
 * Show errors using toastr if available, fallback to DOM manipulation
 * @param {Object} errorMessages - Error messages
 * @param {string} mode - Display mode ('single' or 'multi')
 */
function showToastrErrors(errorMessages, mode = 'single') {
    // Check if toastr is available
    if (typeof toastr !== 'undefined') {
        let optionsToastr = {
            enableHtml: true,
            closeButton: true,
            debug: false,
            newestOnTop: true,
            progressBar: true,
            positionClass: "toast-top-right",
            preventDuplicates: true,
            showDuration: "850",
            hideDuration: "1000",
            timeOut: "5000",
            extendedTimeOut: "1000",
            showEasing: "swing",
            hideEasing: "linear",
            showMethod: "fadeIn",
            hideMethod: "fadeOut"
        };

        if (mode === 'single') {
            const errorList = Object.values(errorMessages);
            if (errorList.length > 0) {
                // Show all errors in a single toastr notification with ul/li format
                const errorText = `<ul style="margin: 0; padding-left: 20px; line-height: 1.5;">${errorList.map(error => `<li style="margin-bottom: 5px;">${error}</li>`).join('')}</ul>`;
                toastr.error(errorText, 'Validation Errors', optionsToastr);
            }
        } else {
            // Multi mode - show each error separately
            Object.values(errorMessages).forEach(error => {
                toastr.error(error, 'Error', optionsToastr);
            });
        }
    } else {
        // Fallback to original DOM manipulation if toastr is not available
        const messagesDiv = document.getElementById('validation_messages');
        if (!messagesDiv) {
            console.warn('Neither toastr nor #validation_messages element found for displaying errors');
            return;
        }
        
        messagesDiv.innerHTML = '';
        
        if (mode === 'single') {
            const errorList = Object.values(errorMessages);
            if (errorList.length > 0) {
                const errorDiv = document.createElement('div');
                errorDiv.className = 'error-list';
                errorDiv.innerHTML = `
                    <strong>Validation Errors:</strong>
                    <ul>
                        ${errorList.map(error => `<li>${error}</li>`).join('')}
                    </ul>
                `;
                messagesDiv.appendChild(errorDiv);
            }
        } else {
            // Multi mode - show each error separately
            Object.values(errorMessages).forEach(error => {
                const errorDiv = document.createElement('div');
                errorDiv.className = 'error-list';
                errorDiv.innerHTML = `<strong>Error:</strong> ${error}`;
                messagesDiv.appendChild(errorDiv);
            });
        }
    }
}