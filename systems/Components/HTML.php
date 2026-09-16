<?php

namespace Components;

class HTML
{
    /**
     * Generate an unordered list (ul) HTML element.
     *
     * @return string Generated HTML
     */
    public static function ul(array $items)
    {
        return '<ul>' . self::generateListItems($items) . '</ul>';
    }

    /**
     * Generate an ordered list (ol) HTML element.
     *
     * @return string Generated HTML
     */
    public static function ol(array $items)
    {
        return '<ol>' . self::generateListItems($items) . '</ol>';
    }

    /** @return string Generated HTML */
    public static function div($content, $attributes = [])
    {
        $html = '<div';
        foreach ($attributes as $key => $value) {
            $html .= ' ' . htmlspecialchars($key) . '="' . htmlspecialchars($value) . '"';
        }
        $html .= '>' . htmlspecialchars($content) . '</div>';
        return $html;
    }

    /**
     * Generate an image (img) HTML element.
     *
     * @return string Generated HTML
     */
    public static function image($src, $alt = '', $attributes = [])
    {
        $html = '<img src="' . htmlspecialchars($src) . '" alt="' . htmlspecialchars($alt) . '"';
        foreach ($attributes as $key => $value) {
            $html .= ' ' . htmlspecialchars($key) . '="' . htmlspecialchars($value) . '"';
        }
        $html .= '>';
        return $html;
    }

    /**
     * Generate a link (a) HTML element.
     *
     * @return string Generated HTML
     */
    public static function href($href, $text, $attributes = [])
    {
        $html = '<a href="' . htmlspecialchars($href) . '"';
        foreach ($attributes as $key => $value) {
            $html .= ' ' . htmlspecialchars($key) . '="' . htmlspecialchars($value) . '"';
        }
        $html .= '>' . htmlspecialchars($text) . '</a>';
        return $html;
    }

    /**
     * Generate a <link> HTML element for CSS files.
     *
     * @return string Generated HTML
     */
    public static function css($href, $attributes = [])
    {
        $html = "<link href=\"" . htmlspecialchars($href) . "\" rel=\"stylesheet\"";
        foreach ($attributes as $key => $value) {
            $html .= " " . htmlspecialchars($key) . "=\"" . htmlspecialchars($value) . "\"";
        }
        $html .= ">";
        return $html;
    }

    /**
     * @param array $data Two-dimensional array representing the table data
     * @return string Generated HTML
     */
    public static function table(array $data, $attributes = [])
    {
        $html = '<table';
        foreach ($attributes as $key => $value) {
            $html .= ' ' . htmlspecialchars($key) . '="' . htmlspecialchars($value) . '"';
        }
        $html .= '>';
        foreach ($data as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . htmlspecialchars($cell) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</table>';
        return $html;
    }

    /**
     * Generate list items for ul or ol.
     *
     * @return string Generated HTML for list items
     */
    private static function generateListItems(array $items)
    {
        $html = '';
        foreach ($items as $item) {
            $html .= '<li>' . htmlspecialchars($item) . '</li>';
        }
        return $html;
    }
}