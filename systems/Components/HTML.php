<?php

namespace Components;

class HTML
{
    /**
     * Generate an unordered list (ul) HTML element.
     *
     * @param array $items List items
     * @return string Generated HTML
     */
    public static function ul(array $items)
    {
        return '<ul>' . self::generateListItems($items) . '</ul>';
    }

    /**
     * Generate an ordered list (ol) HTML element.
     *
     * @param array $items List items
     * @return string Generated HTML
     */
    public static function ol(array $items)
    {
        return '<ol>' . self::generateListItems($items) . '</ol>';
    }

    /**
     * Generate a div HTML element.
     *
     * @param string $content Content inside the div
     * @param array $attributes Additional attributes for the div
     * @return string Generated HTML
     */
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
     * @param string $src Image source
     * @param string $alt Alternative text for the image
     * @param array $attributes Additional attributes for the image
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
     * @param string $href URL of the link
     * @param string $text Text to display for the link
     * @param array $attributes Additional attributes for the link
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
     * @param string $href URL of the CSS file
     * @param array $attributes Additional attributes for the link
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
     * Generate an HTML table.
     *
     * @param array $data Two-dimensional array representing the table data
     * @param array $attributes Additional attributes for the table
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
     * @param array $items List items
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