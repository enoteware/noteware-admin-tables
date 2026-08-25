<?php
/**
 * Plugin Name:       Noteware Admin Tables
 * Plugin URI:        https://github.com/enoteware/noteware-admin-tables
 * Description:       Build useful WordPress admin list tables with configurable columns, filters, editing, export, and saved views.
 * Version:           0.0.1-dev
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Noteware
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain:       noteware-admin-tables
 *
 * @package NotewareAdminTables
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('NAT_VERSION', '0.0.1-dev');
define('NAT_PLUGIN_FILE', __FILE__);
define('NAT_PLUGIN_DIR', __DIR__);

require_once NAT_PLUGIN_DIR . '/src/Plugin.php';

Noteware\AdminTables\Plugin::boot();
