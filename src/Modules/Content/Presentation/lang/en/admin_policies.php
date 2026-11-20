<?php

declare(strict_types=1);

// Content › Policies (flags-2026-11.md S3.7; the owner's answers of 2026-11-20). The words the form shares with pages
// are admin_documents'; the kinds' names are policies'. New words count as approved for now: the owner reads the whole
// site's wording later (reminder 16).
return [
    'title' => 'Policies',
    'subtitle' => 'Terms, privacy, returns and the rest: one text can serve several stores.',
    'new' => 'New Policy',
    'empty' => 'No policies yet.',
    'empty_body' => 'A policy is published at once in every store it serves, at its own address.',
    'item' => 'policy',
    'columns' => [
        'kind' => 'Kind',
        'stores' => 'Stores',
        'version' => 'Version',
    ],
    'fields' => [
        'kind' => 'Kind',
        'kind_fixed' => 'Kind: :kind. It stays as it is once the policy is made.',
        'stores' => 'Stores',
        'stores_helper' => 'The stores this text serves. A save changes only the stores ticked.',
        'stores_required' => 'Tick at least one store.',
        'also_serves' => 'Also serves: :stores (not yours to change)',
        'version' => 'Version :number',
    ],
    // The builder's picks of wording (owner, 2026-11-20): said before the save, as the person ticks and types.
    'notices' => [
        'removed' => ':stores will have no published :kind.',
        'split' => ':ticked will get its own copy; :others will keep the current text.',
    ],
    'removed_confirm' => [
        'title' => 'Take these stores off the policy?',
        'confirm' => 'Save Changes',
    ],
    'publish' => 'Publish Policy',
    'unpublish' => [
        'menu' => 'Unpublish Policy',
        'title' => 'Unpublish this policy?',
        'body' => 'Tick the stores to unpublish it in. Shoppers there see its text marked as no longer valid. A store left without Terms and Conditions takes no orders. Its versions stay.',
        'confirm' => 'Unpublish Policy',
        // The owner's decisions of 2026-11-20: per store, as a save is; a store turned off keeps a copy of its own.
        'stores' => 'Unpublish in',
        'not_yours' => 'Not yours to change',
        'some' => ':stores will keep a copy of their own as a draft, to edit and publish again; :others will keep the published policy.',
        'all' => 'The policy is unpublished in every store it serves.',
        'pick' => 'Tick at least one store.',
    ],
    'delete' => [
        'menu' => 'Delete Policy',
        'title' => 'Delete this policy?',
        'body' => 'The draft is deleted for good in the stores ticked, and its addresses there are free again.',
        'confirm' => 'Delete Policy',
        'blocked' => 'Published before, so its versions are kept. Unpublish it instead.',
        'stores' => 'Delete in',
        'some' => ':stores will be taken off this draft; :others will keep it.',
    ],
    // Publish needs the job in every store the policy serves (S3.7).
    'publish_blocked' => 'It also serves :stores, where you can\'t publish it.',
    'saved' => 'Policy saved',
    'published' => 'Policy published',
    'unpublished' => 'Policy unpublished',
    'deleted' => 'Policy deleted',
    'deleted_in' => 'Draft deleted in the stores ticked',
    'turned_off' => 'Unpublished in the stores ticked. This is their own copy, kept as a draft.',
    'split_saved' => 'Saved as a policy of its own for the stores ticked',
    'list_separator' => ', ',
];
