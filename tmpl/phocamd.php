<?php

/**
 * @package     Phoca.Plugin
 * @subpackage  Fields.phocamd
 *
 * @copyright   (C) 2026 Jan Pavelka
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

/**
 * Frontend rendering template for the Phoca MD field.
 *
 * The markdown content is converted to HTML and stored in the article's
 * introtext/fulltext on save, so the field value itself (raw markdown)
 * is not rendered on the frontend — the article body already contains
 * the converted HTML.
 *
 * This file must exist so that FieldsPlugin::onCustomFieldsGetTypes()
 * registers the 'phocamd' type. It intentionally outputs nothing.
 *
 * @var  \stdClass  $field  The custom field object.
 */

$value = $field->value ?? '';

if ($value === '') {
    return;
}

// The field value is raw markdown. Since the converted HTML is already
// in the article body (introtext/fulltext), we do not render it again
// to avoid duplication. Uncomment below if you want to show the raw
// markdown value rendered as HTML in a standalone field display context.
// require_once JPATH_PLUGINS . '/fields/phocamd/vendor/Parsedown.php';
// $parsedown = new \Parsedown();
// $parsedown->setSafeMode(true);
// echo $parsedown->text($value);
