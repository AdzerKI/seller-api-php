<?php

namespace Opotopo\SellerApi\Catalog;

final class Text
{
    private const HIDDEN = '@<(script|style)[^>]*?>.*?</\1>@si';

    private const BLOCK_END = '#<br\s*/?>|</(p|div|li|h[1-6]|tr|blockquote)>#i';

    private const BLANK_LINES = "/\n{3,}/";

    public static function plain(string $html): string
    {
        $text = strip_tags(preg_replace([self::HIDDEN, self::BLOCK_END], ['', "\n"], $html));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace(self::BLANK_LINES, "\n\n", str_replace("\r\n", "\n", $text)));
    }
}
