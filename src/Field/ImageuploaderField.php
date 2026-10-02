<?php
namespace Joomla\Plugin\System\Phocacartimageupload\Field;

defined('_JEXEC') or die;

use Joomla\CMS\Form\FormField;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Session\Session;

class ImageuploaderField extends FormField
{
    protected $type = 'Imageuploader';

    protected function getInput()
    {
        // Get data from field attributes
        $configJson = (string) $this->element['uploader_config'];
        $langJson = (string) $this->element['uploader_lang'];
        $imagesJson = (string) $this->element['uploader_images'];

        $config = json_decode(html_entity_decode($configJson), true);
        $lang = json_decode(html_entity_decode($langJson), true);
        $existingImages = json_decode(html_entity_decode($imagesJson), true);

        if (!$config || !$lang) {
             return '<div class="alert alert-warning">Image Uploader Configuration missing</div>';
        }

        HTMLHelper::_('bootstrap.tooltip', '.hasTooltip');

        $baseImagePath = $config['baseImagePath'];

        // Build gallery HTML for existing images
        $galleryHtml = '';
        if (!empty($existingImages)) {
            foreach ($existingImages as $index => $img) {
                $isMain = $index === 0;
                $mainBadge = $isMain ? '<span class="phocacart-imageupload-main-badge">' . htmlspecialchars($lang['PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_MAIN_IMAGE_LABEL'] ?? 'Main') . '</span>' : '';

                // Check if path is consistent (current ID or temp folder)
                $pathParts = explode('/', $img['fullPath']);
                $folder = (count($pathParts) > 1) ? $pathParts[0] : '';
                $isCorrectFolder = false;
                if (!empty($config['productId']) && (int)$config['productId'] > 0 && $folder == $config['productId']) {
                    $isCorrectFolder = true;
                } elseif (!empty($config['tempToken']) && $folder == $config['tempToken']) {
                    $isCorrectFolder = true;
                }

                $warningHtml = '';
                if (!$isCorrectFolder) {
                    $description = htmlspecialchars($lang['PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_DIFFERENT_PATH'] ?? 'Shared Image Caution');
                    $warningHtml = '<span class="phocacart-imageupload-warning-icon icon-warning text-warning hasTooltip" title="' . $description . '" aria-hidden="true"></span>';
                }

                $infoHtml = '<span class="phocacart-imageupload-info-icon icon-info text-primary hasTooltip" title="' . htmlspecialchars($img['fullPath']) . '" aria-hidden="true"></span>';

                // Build thumbnail URL
                // Phoca Cart stores thumbnails in "<folder of the image>/thumbs/",
                // where <folder> may be nested (e.g. "product/sub/test.jpg").
                $cleanPath = ltrim(str_replace('\\', '/', $img['fullPath']), '/');
                $dir       = dirname($cleanPath);
                $dir       = ($dir === '.' || $dir === '') ? '' : $dir . '/';
                $encode    = static function (string $path): string {
                    return implode('/', array_map('rawurlencode', explode('/', $path)));
                };

                if ($dir !== '') {
                    $thumbUrl = $baseImagePath . $encode($dir) . 'thumbs/phoca_thumb_m_' . rawurlencode(basename($cleanPath));
                } else {
                    $thumbUrl = $baseImagePath . $encode($cleanPath);
                }
                $originalUrl = $baseImagePath . $encode($cleanPath);

                $deleteTitle = htmlspecialchars($lang['PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_DELETE'] ?? 'Delete');
                if (!$isCorrectFolder) {
                    $deleteTitle .= ' - ' . htmlspecialchars($lang['PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_DIFFERENT_PATH'] ?? 'Shared Image Caution');
                }

                $galleryHtml .= '<div class="phocacart-imageupload-item" data-filename="' . htmlspecialchars($img['filename']) . '" data-fullpath="' . htmlspecialchars($img['fullPath']) . '">';
                $galleryHtml .= $mainBadge;
                $galleryHtml .= '<div class="phocacart-imageupload-thumb"><img src="' . htmlspecialchars($thumbUrl) . '" alt="' . htmlspecialchars($img['filename']) . '" loading="lazy" onerror="this.onerror=null;this.src=\'' . htmlspecialchars($originalUrl, ENT_QUOTES) . '\';"></div>';
                $galleryHtml .= '<div class="phocacart-imageupload-info"><span class="phocacart-imageupload-filename">' . htmlspecialchars($img['filename']) . '</span></div>';
                $galleryHtml .= '<div class="phocacart-imageupload-actions">';
                $galleryHtml .= $warningHtml;
                $galleryHtml .= $infoHtml;
                $galleryHtml .= '<button type="button" class="phocacart-imageupload-delete-btn hasTooltip" title="' . $deleteTitle . '"><span class="icon-trash" aria-hidden="true"></span></button>';
                $galleryHtml .= '</div>';
                $galleryHtml .= '</div>';
            }
        }

        $deleteAllDisplay = count($existingImages) > 0 ? 'inline-flex' : 'none';

        $html = '
<div class="phocacart-imageupload-uploader" id="phocacart-imageupload-uploader">
    <div class="phocacart-imageupload-dropzone">
        <div class="phocacart-imageupload-dropzone-text">
            <span class="icon-upload" aria-hidden="true"></span>
            <span>' . htmlspecialchars($lang['PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_DRAG_DROP_IMAGES_HERE'] ?? 'Drag & Drop') . '</span>
        </div>
        <button type="button" class="btn btn-primary phocacart-imageupload-select-btn">
            <span class="icon-folder-open" aria-hidden="true"></span>
            ' . htmlspecialchars($lang['PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_SELECT_FILES'] ?? 'Select') . '
        </button>
        <button type="button" class="btn btn-danger phocacart-imageupload-delete-all-btn" style="display:' . $deleteAllDisplay . ';">
            <span class="icon-trash" aria-hidden="true"></span>
            ' . htmlspecialchars($lang['PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_DELETE_ALL_IMAGES'] ?? 'Delete All') . '
        </button>
        <input type="file" class="phocacart-imageupload-file-input" multiple accept="image/*" style="display:none;">
    </div>
    <div class="phocacart-imageupload-progress">
        <div class="phocacart-imageupload-progress-bar">
            <div class="phocacart-imageupload-progress-bar-fill"></div>
        </div>
    </div>
    <div class="phocacart-imageupload-error"></div>
    <div class="phocacart-imageupload-gallery">' . $galleryHtml . '</div>
</div>';

        // Note: The hidden input field 'phocacartimageupload_images_data' is used to pass image data to the backend.
        // It is manually included here to ensure it's present in the DOM for the JS uploader.
        $inputValue = htmlspecialchars(json_encode($existingImages), ENT_QUOTES, 'UTF-8');
        $html .= '<input type="hidden" name="jform[phocacartimageupload_images_data]" id="jform_phocacartimageupload_images_data" value="' . $inputValue . '">';

        return $html;
    }
}
