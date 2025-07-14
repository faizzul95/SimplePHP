<?php

namespace Components;

/**
 * Input Class
 *
 * @category  Form Input
 * @package   Input
 * @author    Mohd Fahmy Izwan Zulkhafri <faizzul14@gmail.com>
 * @license   http://opensource.org/licenses/gpl-3.0.html GNU Public License
 * @link      -
 * @version   1.0.0
 */
class Input
{
    /**
     * Generate HTML input field of type text.
     *
     * @param string $name Name attribute of the input field.
     * @param string $value Value attribute of the input field.
     * @param array $attributes Additional attributes for the input field.
     * @return string HTML representation of the input field.
     */
    public static function text($name, $value = '', $attributes = array())
    {
        return self::generateInput('text', $name, $value, $attributes);
    }

    /**
     * Generate HTML input field of type radio.
     *
     * @param string $name Name attribute of the input field.
     * @param string $value Value attribute of the input field.
     * @param bool $checked Whether the radio button should be checked.
     * @param array $attributes Additional attributes for the input field.
     * @return string HTML representation of the input field.
     */
    public static function radio($name, $value = '', $checked = false, $attributes = array())
    {
        if ($checked) {
            $attributes['checked'] = 'checked';
        }
        return self::generateInput('radio', $name, $value, $attributes);
    }

    /**
     * Generate HTML textarea.
     *
     * @param string $name Name attribute of the textarea.
     * @param string $value Value of the textarea.
     * @param array $attributes Additional attributes for the textarea.
     * @return string HTML representation of the textarea.
     */
    public static function textarea($name, $value = '', $attributes = array())
    {
        $attributes['name'] = $name;
        return '<textarea ' . self::formatAttributes($attributes) . '>' . htmlspecialchars($value) . '</textarea>';
    }

    /**
     * Generate HTML select dropdown.
     *
     * @param string $name Name attribute of the select dropdown.
     * @param array $options Associative array of options (value => label).
     * @param string $selected Value of the selected option.
     * @param array $attributes Additional attributes for the select dropdown.
     * @return string HTML representation of the select dropdown.
     */
    public static function select($name, $options = array(), $selected = '', $attributes = array())
    {
        $html = '<select name="' . $name . '"' . self::formatAttributes($attributes) . '>';
        foreach ($options as $value => $label) {
            $isSelected = ($value == $selected) ? 'selected="selected"' : '';
            $html .= '<option value="' . htmlspecialchars($value) . '" ' . $isSelected . '>' . htmlspecialchars($label) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }

    /**
     * Generate HTML input field of type checkbox.
     *
     * @param string $name Name attribute of the input field.
     * @param string $value Value attribute of the input field.
     * @param bool $checked Whether the checkbox should be checked.
     * @param array $attributes Additional attributes for the input field.
     * @return string HTML representation of the input field.
     */
    public static function checkbox($name, $value = '', $checked = false, $attributes = array())
    {
        if ($checked) {
            $attributes['checked'] = 'checked';
        }
        return self::generateInput('checkbox', $name, $value, $attributes);
    }

    /**
     * Generate HTML input field of type hidden.
     *
     * @param string $name Name attribute of the input field.
     * @param string $value Value attribute of the input field.
     * @param array $attributes Additional attributes for the input field.
     * @return string HTML representation of the input field.
     */
    public static function hidden($name, $value = '', $attributes = array())
    {
        return self::generateInput('hidden', $name, $value, $attributes);
    }

    /**
     * Generate HTML input field of type number.
     *
     * @param string $name Name attribute of the input field.
     * @param string $value Value attribute of the input field.
     * @param array $attributes Additional attributes for the input field.
     * @return string HTML representation of the input field.
     */
    public static function number($name, $value = '', $attributes = array())
    {
        return self::generateInput('number', $name, $value, $attributes);
    }

    /**
     * Generate HTML input field of type password.
     *
     * @param string $name Name attribute of the input field.
     * @param string $value Value attribute of the input field.
     * @param array $attributes Additional attributes for the input field.
     * @return string HTML representation of the input field.
     */
    public static function password($name, $value = '', $attributes = array())
    {
        return self::generateInput('password', $name, $value, $attributes);
    }

    /**
     * Generate HTML input field of type date.
     *
     * @param string $name Name attribute of the input field.
     * @param string $value Value attribute of the input field.
     * @param array $attributes Additional attributes for the input field.
     * @return string HTML representation of the input field.
     */
    public static function date($name, $value = '', $attributes = array())
    {
        return self::generateInput('date', $name, $value, $attributes);
    }

    /**
     * Generate HTML input field of type time.
     *
     * @param string $name Name attribute of the input field.
     * @param string $value Value attribute of the input field.
     * @param array $attributes Additional attributes for the input field.
     * @return string HTML representation of the input field.
     */
    public static function time($name, $value = '', $attributes = array())
    {
        return self::generateInput('time', $name, $value, $attributes);
    }

    /**
     * Generate HTML input field of type email.
     *
     * @param string $name Name attribute of the input field.
     * @param string $value Value attribute of the input field.
     * @param array $attributes Additional attributes for the input field.
     * @return string HTML representation of the input field.
     */
    public static function email($name, $value = '', $attributes = array())
    {
        return self::generateInput('email', $name, $value, $attributes);
    }

    /**
     * Generate HTML input field of type URL.
     *
     * @param string $name Name attribute of the input field.
     * @param string $value Value attribute of the input field.
     * @param array $attributes Additional attributes for the input field.
     * @return string HTML representation of the input field.
     */
    public static function url($name, $value = '', $attributes = array())
    {
        return self::generateInput('url', $name, $value, $attributes);
    }

    /**
     * Generate HTML input field of type file.
     *
     * @param string $name Name attribute of the input field.
     * @param array $attributes Additional attributes for the input field.
     * @return string HTML representation of the input field.
     */
    public static function file($name, $attributes = array())
    {
        return self::generateInput('file', $name, '', $attributes);
    }

    /**
     * Generate HTML input field with specified attributes.
     *
     * @param string $type Type of input field.
     * @param string $name Name attribute of the input field.
     * @param string $value Value attribute of the input field.
     * @param array $attributes Additional attributes for the input field.
     * @return string HTML representation of the input field.
     */
    protected static function generateInput($type, $name, $value = '', $attributes = array())
    {
        $attributes['type'] = $type;
        $attributes['name'] = $name;
        $attributes['value'] = htmlspecialchars($value);
        return '<input ' . self::formatAttributes($attributes) . '>';
    }

    /**
     * Format attributes for HTML element.
     *
     * @param array $attributes Associative array of attributes.
     * @return string Formatted attributes for HTML element.
     */
    protected static function formatAttributes($attributes)
    {
        $html = '';
        foreach ($attributes as $key => $value) {
            $html .= ' ' . $key . '="' . htmlspecialchars($value) . '"';
        }
        return $html;
    }
}
