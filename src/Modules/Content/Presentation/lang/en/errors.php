<?php

declare(strict_types=1);

// Content's error messages, by error type (content.{key}, content.md §7), and the names of the fields they
// point at. New words count as approved for now: the owner reads the whole site's wording later (reminder 16).
return [
    'invalid_content_input' => [
        'title' => 'Check the details',
        'detail' => 'The :attribute is not valid.',
        'rules' => [
            'too_long' => 'The :attribute holds more than :max characters.',
            'required' => 'Type the :attribute.',
            'one_line' => 'The :attribute must stay on one line.',
            'format' => 'The :attribute is not written in a way the editor reads.',
            'link' => 'A link goes to a page of the shop, such as /categories/chairs, or to a full https:// address.',
            'heading_level' => 'A heading is written with # or ##.',
            'image' => 'An image goes on a line of its own, with a description: ![description](image:1).',
            'too_many_images' => 'The :attribute holds more than :max images.',
            'table_columns' => 'A table has :min to :max columns.',
            'table_row_columns' => 'The table has :found columns here and :expected above.',
            'table_rows' => 'A table has 1 to :max rows under its heading.',
            'too_many_blocks' => 'The :attribute holds more than :max paragraphs, headings, lists, images and tables.',
            'too_many_items' => 'A list holds :max items at most.',
            'kind' => 'There is no such kind of item.',
            'store' => 'There is no such store.',
            'language' => 'The language is Arabic or English.',
            'too_large' => 'This text is too long to send at once. Translate it in parts, or type the English.',
            // The same refusals, on the line of the typed text they are on (§1.2).
            'too_long_line' => 'Line :line: the :attribute holds more than :max characters.',
            'required_line' => 'Line :line: type the :attribute.',
            'one_line_line' => 'Line :line: the :attribute must stay on one line.',
            'format_line' => 'Line :line: the editor cannot read this.',
            'link_line' => 'Line :line: a link goes to a page of the shop, such as /categories/chairs, or to a full https:// address.',
            'heading_level_line' => 'Line :line: a heading is written with # or ##.',
            'image_line' => 'Line :line: an image goes on a line of its own, with a description: ![description](image:1).',
            'too_many_images_line' => 'Line :line: the :attribute holds more than :max images.',
            'table_columns_line' => 'Line :line: a table has :min to :max columns.',
            'table_row_columns_line' => 'Line :line: the table has :found columns here and :expected above.',
            'table_rows_line' => 'Line :line: a table has 1 to :max rows under its heading.',
            'too_many_blocks_line' => 'Line :line: the :attribute holds more than :max paragraphs, headings, lists, images and tables.',
            'too_many_items_line' => 'Line :line: a list holds :max items at most.',
        ],
    ],
    'missing_english' => [
        'title' => 'English missing',
        'detail' => 'Type the English of the :attribute before publishing.',
    ],
    'slug_taken' => [
        'title' => 'Address taken',
        'detail' => 'Another item uses the address ":slug", or used it before. Choose another.',
    ],
    'stale_edit' => [
        'title' => 'Changed meanwhile',
        'detail' => ':by saved this at :at. Your text is still here: copy what you need, then open the page again.',
    ],
    'theme_clash' => [
        'title' => 'Another theme then',
        'detail' => 'The theme :theme runs in :store from :starts to :ends. Choose other dates.',
    ],
    'popup_clash' => [
        'title' => 'Another pop-up then',
        'detail' => 'The pop-up :popup runs in :store from :starts to :ends. Choose other dates.',
    ],
    'notice_taken' => [
        'title' => 'Notice in use',
        'detail' => 'Another campaign uses this notice. Choose another notice.',
    ],
    'notice_not_draft' => [
        'title' => 'Notice changed',
        'detail' => 'This notice is no longer a draft of this store. Choose another notice.',
    ],
    'campaign_not_changeable' => [
        'title' => 'Can\'t change this now',
        'detail' => 'This campaign can\'t be changed that way in its state.',
    ],
    'category_not_empty' => [
        'title' => 'Category in use',
        'detail' => 'This category holds posts. Move them to another category first.',
    ],
    'not_deletable' => [
        'title' => 'Can\'t delete this',
        'detail' => 'Only a draft can be deleted. Unpublish it, or move it back to draft, first.',
    ],
    'image_not_ready' => [
        'title' => 'Image not ready',
        'detail' => ':image is not ready.',
        'rules' => [
            'pending' => ':image is still being prepared. Try again in a minute.',
            // The spec's own words (content.md §1.1 rule 10).
            'failed' => 'Upload this image again.',
        ],
    ],
    'campaign_prices_not_allowed' => [
        'title' => 'Prices need permission',
        'detail' => 'Changing prices in :store needs the permission to edit prices there.',
    ],
    'redirect_invalid' => [
        'title' => 'Check the redirect',
        'detail' => 'This redirect is not valid.',
    ],
    'sheet_refused' => [
        'title' => 'Check the sheet',
        'detail' => 'This sheet can\'t be read.',
    ],
    'translation_unavailable' => [
        'title' => 'Not translated',
        'detail' => 'Failed to translate. Type the English instead.',
        'rules' => [
            'not_set' => 'Translation isn\'t set up on this server.',
            'quota' => 'Failed to translate: this month\'s allowance is used up. Type the English instead.',
            'busy' => 'Failed to translate: too many requests. Try again in a minute.',
            'refused' => 'Failed to translate. Type the English instead.',
            'unreachable' => 'Failed to translate. Type the English instead.',
            'too_long' => 'This text is too long to translate at once. Translate it in parts, or type the English.',
            'daily_limit' => 'Failed to translate: you have translated as much as one person may today. Type the English instead.',
        ],
    ],
    'store_off' => [
        'title' => 'This store is off',
        'detail' => 'Only someone who may switch stores on and off works in the Content screens of a store that is off.',
    ],
    'content_not_found' => [
        'title' => 'Not found',
        'detail' => 'This item isn\'t available.',
    ],
    'fields' => [
        'text' => 'text',
        'body' => 'text',
        'kind' => 'kind of item',
        'store' => 'store',
        'from' => 'language',
        'boxes' => 'boxes',
    ],
];
