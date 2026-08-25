<?php
/**
 * Seed deterministic, generic sandbox records through public WordPress APIs.
 *
 * @package NotewareAdminTablesSandbox
 * @license GPL-2.0-or-later
 */

$target_count = max(1, (int) (getenv('NAT_FIXTURE_COUNT') ?: 60));
$post_type    = 'nat_demo_record';

if (! post_type_exists($post_type)) {
    WP_CLI::error('The generic sandbox post type is not registered.');
}

$existing_query = new WP_Query(
    array(
        'post_type'              => $post_type,
        'post_status'            => 'any',
        'posts_per_page'         => $target_count,
        'fields'                 => 'ids',
        'orderby'                => 'ID',
        'order'                  => 'ASC',
        'meta_key'               => '_nat_fixture_index',
        'no_found_rows'          => true,
        'update_post_meta_cache' => true,
        'update_post_term_cache' => false,
    )
);

$existing = array();
foreach ($existing_query->posts as $post_id) {
    $fixture_index = (int) get_post_meta((int) $post_id, '_nat_fixture_index', true);
    if ($fixture_index > 0) {
        $existing[$fixture_index] = (int) $post_id;
    }
}

wp_defer_term_counting(true);
wp_defer_comment_counting(true);
wp_suspend_cache_invalidation(true);

$created = 0;
$choices = array('alpha', 'beta', 'gamma');

$attachment_ids = get_posts(
    array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_key'       => '_nat_fixture_image',
    )
);

if ($attachment_ids) {
    $fixture_image_id = (int) $attachment_ids[0];
} else {
    $image_bytes = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAIAAAACUFjqAAAAFElEQVR42mNkYPj/n4EIwDiqkL4KAdIJAx7JMS9eAAAAAElFTkSuQmCC',
        true
    );

    if (false === $image_bytes) {
        WP_CLI::error('Could not decode the generic fixture image.');
    }

    $upload = wp_upload_bits('nat-demo-image.png', null, $image_bytes);
    if (! empty($upload['error'])) {
        WP_CLI::error((string) $upload['error']);
    }

    $fixture_image_id = wp_insert_attachment(
        array(
            'post_mime_type' => 'image/png',
            'post_title'     => 'Generic demo image',
            'post_status'    => 'inherit',
        ),
        $upload['file']
    );

    require_once ABSPATH . 'wp-admin/includes/image.php';
    wp_update_attachment_metadata(
        $fixture_image_id,
        wp_generate_attachment_metadata($fixture_image_id, $upload['file'])
    );
    update_post_meta($fixture_image_id, '_nat_fixture_image', 1);
}

for ($index = 1; $index <= $target_count; $index++) {
    if (isset($existing[$index])) {
        if ($target_count > 500) {
            continue;
        }
        $post_id = $existing[$index];
    } else {
        $post_id = wp_insert_post(
            array(
                'post_type'    => $post_type,
                'post_status'  => 'publish',
                'post_title'   => sprintf('Demo record %05d', $index),
                'post_content' => sprintf('Generic fixture content for record %d.', $index),
                'post_date'    => gmdate('Y-m-d H:i:s', strtotime('2024-01-01 +' . $index . ' hours')),
            ),
            true
        );

        if (is_wp_error($post_id)) {
            wp_suspend_cache_invalidation(false);
            wp_defer_comment_counting(false);
            wp_defer_term_counting(false);
            WP_CLI::error($post_id->get_error_message());
        }

        ++$created;
    }

    update_post_meta($post_id, '_nat_fixture_index', $index);
    update_post_meta($post_id, 'nat_demo_note', 0 === $index % 10 ? '' : 'Note ' . $index);

    // Seed every adapter densely for the normal fixture and sparsely for the 10k profile.
    if ($target_count <= 500 || 0 === $index % 25) {
        update_field('field_nat_demo_text', 0 === $index % 11 ? '' : 'Text ' . $index, $post_id);
        update_field('field_nat_demo_number', 0 === $index % 9 ? 0 : $index * 3, $post_id);
        update_field('field_nat_demo_enabled', 0 === $index % 2, $post_id);
        update_field('field_nat_demo_choice', $choices[$index % count($choices)], $post_id);
        update_field('field_nat_demo_date', gmdate('Ymd', strtotime('2024-01-01 +' . $index . ' days')), $post_id);
        update_field('field_nat_demo_image', $fixture_image_id, $post_id);
    }
}

wp_suspend_cache_invalidation(false);
wp_defer_comment_counting(false);
wp_defer_term_counting(false);

clean_post_cache(0);

WP_CLI::success(
    sprintf(
        'Generic sandbox fixture contains %d records (%d created).',
        $target_count,
        $created
    )
);
