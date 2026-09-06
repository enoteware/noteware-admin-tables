<?php
/**
 * Explicit distinction between public-API detection and activated compatibility proof.
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

namespace Noteware\AdminTables\Integration;

final class IntegrationCatalog
{
    /** @return array<string, array{api_available: ?bool, read_fields: list<string>, writes: false, activated_tested: false}> */
    public static function report(PublicFunctions $api): array
    {
        $report = array();
        foreach (array('meta_box', 'jetengine', 'toolset_types', 'pods', 'gravity_forms', 'yoast_seo', 'rank_math', 'seopress', 'events_calendar', 'buddypress', 'beaver_builder', 'media_library_assistant') as $id) {
            $report[$id] = array('api_available' => null, 'read_fields' => array(), 'writes' => false, 'activated_tested' => false);
        }
        $report['meta_box']['api_available'] = $api->available('rwmb_get_field_settings') && $api->available('rwmb_get_value');
        $report['meta_box']['read_fields'] = array('configured non-clone scalar post fields');
        $report['yoast_seo']['api_available'] = $api->available('YoastSEO');
        $report['yoast_seo']['read_fields'] = array('title', 'description', 'canonical');
        return $report;
    }
}
