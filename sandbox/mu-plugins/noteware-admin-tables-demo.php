<?php
/**
 * Generic site configuration for the local development sandbox.
 *
 * This file is deliberately outside the distributable plugin. It demonstrates
 * the public configuration hooks without coupling core code to one site.
 *
 * @package NotewareAdminTablesSandbox
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

add_action(
    'init',
    static function (): void {
        register_post_type(
            'nat_demo_record',
            array(
                'labels'       => array(
                    'name'          => 'Demo records',
                    'singular_name' => 'Demo record',
                    'add_new_item'  => 'Add demo record',
                    'edit_item'     => 'Edit demo record',
                ),
                'public'       => false,
                'show_ui'      => true,
                'show_in_menu' => true,
                'supports'     => array('title', 'editor', 'author', 'thumbnail'),
                'map_meta_cap' => true,
            )
        );
    }
);

add_filter(
    'noteware_admin_tables_config',
    static function (array $config): array {
        $config['nat_demo_record'] = array(
            'columns' => array(
                array(
                    'key'        => 'nat_demo_id',
                    'label'      => 'ID',
                    'source'     => 'native',
                    'type'       => 'number',
                    'field'      => 'id',
                    'sortable'   => true,
                    'filterable' => false,
                    'editable'   => false,
                    'choices'    => array(),
                ),
                array(
                    'key'        => 'nat_demo_text',
                    'label'      => 'Demo text',
                    'source'     => 'acf',
                    'type'       => 'text',
                    'field'      => 'nat_demo_text',
                    'field_key'  => 'field_nat_demo_text',
                    'sortable'   => true,
                    'filterable' => true,
                    'editable'   => false,
                    'choices'    => array(),
                ),
                array(
                    'key'        => 'nat_demo_number',
                    'label'      => 'Demo number',
                    'source'     => 'acf',
                    'type'       => 'number',
                    'field'      => 'nat_demo_number',
                    'field_key'  => 'field_nat_demo_number',
                    'sortable'   => true,
                    'filterable' => true,
                    'editable'   => false,
                    'choices'    => array(),
                ),
                array(
                    'key'        => 'nat_demo_enabled',
                    'label'      => 'Enabled',
                    'source'     => 'acf',
                    'type'       => 'boolean',
                    'field'      => 'nat_demo_enabled',
                    'field_key'  => 'field_nat_demo_enabled',
                    'sortable'   => true,
                    'filterable' => true,
                    'editable'   => false,
                    'choices'    => array(
                        '0' => 'No',
                        '1' => 'Yes',
                    ),
                ),
                array(
                    'key'        => 'nat_demo_choice',
                    'label'      => 'Demo choice',
                    'source'     => 'acf',
                    'type'       => 'select',
                    'field'      => 'nat_demo_choice',
                    'field_key'  => 'field_nat_demo_choice',
                    'sortable'   => true,
                    'filterable' => true,
                    'editable'   => false,
                    'choices'    => array(
                        'alpha' => 'Alpha',
                        'beta'  => 'Beta',
                        'gamma' => 'Gamma',
                    ),
                ),
                array(
                    'key'        => 'nat_demo_date',
                    'label'      => 'Demo date',
                    'source'     => 'acf',
                    'type'       => 'date',
                    'field'      => 'nat_demo_date',
                    'field_key'  => 'field_nat_demo_date',
                    'sortable'   => true,
                    'filterable' => true,
                    'editable'   => false,
                    'choices'    => array(),
                ),
                array(
                    'key'        => 'nat_demo_image',
                    'label'      => 'Demo image',
                    'source'     => 'acf',
                    'type'       => 'image',
                    'field'      => 'nat_demo_image',
                    'field_key'  => 'field_nat_demo_image',
                    'sortable'   => false,
                    'filterable' => false,
                    'editable'   => false,
                    'choices'    => array(),
                ),
                array(
                    'key'        => 'nat_demo_note',
                    'label'      => 'Demo note',
                    'source'     => 'meta',
                    'type'       => 'text',
                    'field'      => 'nat_demo_note',
                    'sortable'   => true,
                    'filterable' => true,
                    'editable'   => true,
                    'choices'    => array(),
                ),
            ),
        );

        return $config;
    }
);

add_action(
    'acf/include_fields',
    static function (): void {
        if (! function_exists('acf_add_local_field_group')) {
            return;
        }

        acf_add_local_field_group(
            array(
                'key'      => 'group_nat_demo_fields',
                'title'    => 'Admin table demo fields',
                'fields'   => array(
                    array(
                        'key'   => 'field_nat_demo_text',
                        'label' => 'Demo text',
                        'name'  => 'nat_demo_text',
                        'type'  => 'text',
                    ),
                    array(
                        'key'   => 'field_nat_demo_number',
                        'label' => 'Demo number',
                        'name'  => 'nat_demo_number',
                        'type'  => 'number',
                    ),
                    array(
                        'key'   => 'field_nat_demo_enabled',
                        'label' => 'Enabled',
                        'name'  => 'nat_demo_enabled',
                        'type'  => 'true_false',
                    ),
                    array(
                        'key'           => 'field_nat_demo_choice',
                        'label'         => 'Demo choice',
                        'name'          => 'nat_demo_choice',
                        'type'          => 'select',
                        'choices'       => array(
                            'alpha' => 'Alpha',
                            'beta'  => 'Beta',
                            'gamma' => 'Gamma',
                        ),
                        'return_format' => 'value',
                    ),
                    array(
                        'key'            => 'field_nat_demo_date',
                        'label'          => 'Demo date',
                        'name'           => 'nat_demo_date',
                        'type'           => 'date_picker',
                        'display_format' => 'F j, Y',
                        'return_format'  => 'Y-m-d',
                    ),
                    array(
                        'key'           => 'field_nat_demo_image',
                        'label'         => 'Demo image',
                        'name'          => 'nat_demo_image',
                        'type'          => 'image',
                        'return_format' => 'id',
                        'preview_size'  => 'thumbnail',
                    ),
                ),
                'location' => array(
                    array(
                        array(
                            'param'    => 'post_type',
                            'operator' => '==',
                            'value'    => 'nat_demo_record',
                        ),
                    ),
                ),
            )
        );
    }
);
