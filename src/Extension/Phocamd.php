<?php

/**
 * @package     Phoca.Plugin
 * @subpackage  Fields.phocamd
 *
 * @copyright   (C) 2026 Jan Pavelka
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Phoca\Plugin\Fields\Phocamd\Extension;

use Joomla\CMS\Event\Model\BeforeSaveEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\Component\Fields\Administrator\Helper\FieldsHelper;
use Joomla\Component\Fields\Administrator\Plugin\FieldsPlugin;
use Joomla\Event\SubscriberInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Phoca MD Fields Plugin
 *
 * Provides a Markdown custom field for Joomla articles. The markdown content
 * is converted to HTML via Parsedown and written into the article's introtext
 * and fulltext fields on every save. Use ===more=== as a separator to split
 * intro and full text.
 *
 * @since  1.0.0
 */
final class Phocamd extends FieldsPlugin implements SubscriberInterface
{
    /**
     * Disable legacy listener discovery — only SubscriberInterface events.
     *
     * @var    bool
     * @since  1.0.0
     */
    protected $allowLegacyListeners = false;

    /**
     * Track whether the admin script has already been injected during this
     * request to avoid duplicate output when multiple phocamd fields exist.
     *
     * @var    bool
     * @since  1.0.0
     */
    private static bool $scriptInjected = false;

    /**
     * Returns events this subscriber listens to.
     *
     * Merges the parent FieldsPlugin events (type registration, form DOM
     * preparation, field rendering) with the custom onContentBeforeSave
     * event for markdown-to-HTML conversion.
     *
     * @return  array<string, string>
     *
     * @since   1.0.0
     */
    public static function getSubscribedEvents(): array
    {
        return array_merge(parent::getSubscribedEvents(), [
            'onContentBeforeSave' => 'onContentBeforeSave',
        ]);
    }

    // ─── Markdown → HTML conversion on article save ──────────────────────

    /**
     * Convert the markdown field value to HTML and inject it into the
     * article's introtext / fulltext before the article is persisted.
     *
     * @param   BeforeSaveEvent  $event  The before-save event.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onContentBeforeSave(BeforeSaveEvent $event): void
    {
        $context = $event->getContext();

        // Only process com_content articles
        if ($context !== 'com_content.article') {
            return;
        }

        $item  = $event->getItem();
        $data  = $event->getData() ?? [];

        // Find the actual name of the custom field that has type 'phocamd'
        $fieldName = 'phocamd'; // fallback
        $fields = FieldsHelper::getFields('com_content.article', $item, true);
        if (\is_array($fields)) {
            foreach ($fields as $f) {
                if ($f->type === 'phocamd') {
                    $fieldName = $f->name;
                    break;
                }
            }
        }

        // 1. Try to read the markdown from the submitted POST data
        $mdContent = trim((string) ($data['com_fields'][$fieldName] ?? ''));

        // 2. Fallback: read the previously stored raw value from jcfields
        if ($mdContent === '' && !empty($item->jcfields) && \is_array($item->jcfields)) {
            foreach ($item->jcfields as $jcField) {
                if (($jcField->name ?? '') === $fieldName || ($jcField->type ?? '') === 'phocamd') {
                    $mdContent = trim((string) ($jcField->rawvalue ?? ''));
                    break;
                }
            }
        }

        // Nothing to convert — leave the article untouched
        if ($mdContent === '') {
            return;
        }

        // 2.5 Extract Main Title if enabled
        if ($this->params->get('extract_title', 0)) {
            // Look for a top-level heading at the very beginning of the string (ignoring leading whitespace)
            if (preg_match('/^\s*#\s+(.+)(?:\r?\n|$)/', $mdContent, $matches)) {
                //$item->title = trim($matches[1]);
                $cleanTitle = trim($matches[1]);
                $cleanTitle = preg_replace('/\[([^\]]+)\]\([^\)]+\)/', '$1', $cleanTitle);
                $cleanTitle = preg_replace('/[*_~`]/', '', $cleanTitle);
                $item->title = trim($cleanTitle);

                // Remove the matched title line from the markdown content
                $mdContent = preg_replace('/^\s*#\s+(.+)(?:\r?\n|$)/', '', $mdContent, 1);
                $mdContent = ltrim($mdContent); // clean up any lingering leading whitespace
            }
        }

        // 3. Split on ===more=== separator (unless escaped with a backslash: \===more===)
        $parts = preg_split('/(?<!\\\\)===more===/', $mdContent, 2);

        $introSource    = trim($parts[0] ?? '');
        $fulltextSource = trim($parts[1] ?? '');

        // Remove the backslash from any escaped \===more===
        $introSource    = str_replace('\===more===', '===more===', $introSource);
        $fulltextSource = str_replace('\===more===', '===more===', $fulltextSource);

        // 4. Load Parsedown and convert
        try {
            require_once __DIR__ . '/../../vendor/Parsedown.php';

            $parsedown = new \Parsedown();
            $parsedown->setSafeMode(true);

            $item->introtext = $parsedown->text($introSource);
            $item->fulltext  = $fulltextSource !== '' ? $parsedown->text($fulltextSource) : '';
        } catch (\Throwable $e) {
            // Fail-safe: log the error and skip — never corrupt the article
            Log::add(
                'Phoca MD: Parsedown conversion failed — ' . $e->getMessage(),
                Log::ERROR,
                'plg_fields_phocamd'
            );
        }
    }

    // ─── Admin form field customisation ──────────────────────────────────

    /**
     * Override the parent DOM preparation to render the field as a monospace
     * textarea with JavaScript that locks the TinyMCE editor when the
     * markdown field contains content.
     *
     * @param   \stdClass    $field   The custom field definition.
     * @param   \DOMElement  $parent  The parent fieldset DOM node.
     * @param   Form         $form    The form being prepared.
     *
     * @return  \DOMElement|null
     *
     * @since   1.0.0
     */
    public function onCustomFieldsPrepareDom($field, \DOMElement $parent, Form $form): ?\DOMElement
    {
        $fieldNode = parent::onCustomFieldsPrepareDom($field, $parent, $form);

        if (!$fieldNode) {
            return null;
        }

        // Render as standard Joomla textarea in the admin form
        $fieldNode->setAttribute('type', 'textarea');
        $fieldNode->setAttribute('rows', '20');
        $fieldNode->setAttribute('filter', 'raw');

        // Merge our CSS class with any existing class from field params
        $existingClass = $fieldNode->getAttribute('class');
        $fieldNode->setAttribute(
            'class',
            trim($existingClass . ' phocamd-field')
        );

        // Inject CSS + JS once per request
        $this->injectAdminAssets();

        return $fieldNode;
    }

    // ─── Asset injection ─────────────────────────────────────────────────

    /**
     * Inject CSS and JavaScript into the admin document via WebAssetManager
     * for the textarea styling, editor lock, and warning behaviours.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function injectAdminAssets(): void
    {
        if (self::$scriptInjected) {
            return;
        }

        self::$scriptInjected = true;

        $app = $this->getApplication();

        // Only inject on backend (administrator)
        if (!$app->isClient('administrator')) {
            return;
        }

        $wa = $app->getDocument()->getWebAssetManager();
        $registry = $wa->getRegistry();

        // Ensure our asset file is registered (useful during development when testing outside standard zip install)
        if (!$registry->exists('script', 'plg_fields_phocamd.admin')) {
            $registry->addRegistryFile('media/plg_fields_phocamd/joomla.asset.json');
        }

        Text::script('PLG_FIELDS_PHOCAMD_EDITOR_LOCKED');

        $wa->useStyle('plg_fields_phocamd.admin')
           ->useScript('plg_fields_phocamd.admin');
    }
}
