<?php
namespace Joomla\Plugin\System\Phocacartimageupload\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Log\Log;
use Joomla\Event\SubscriberInterface;
use Joomla\Filesystem\Folder;
use Joomla\Filesystem\File;

class Phocacartimageupload extends CMSPlugin implements SubscriberInterface
{
    protected $autoloadLanguage = true;
    protected $processedImages = null;

    /**
     * GD's own decoder function for each MIME type this plugin knows how to
     * fully decode-verify. This is not a business-configured "allowed types"
     * list (that always comes from Phoca Cart's own
     * PhocacartUtilsSettings::getImageExtensions() at the point of use) - it
     * is simply which built-in PHP/GD function is capable of decoding a
     * given MIME type. Referenced from a single place so the set of
     * decodable formats never has to be kept in sync across methods.
     *
     * @var    array<string, string>
     * @since  6.1.4
     */
    private const MIME_DECODE_HANDLERS = [
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png'  => 'imagecreatefrompng',
        'image/gif'  => 'imagecreatefromgif',
        'image/webp' => 'imagecreatefromwebp',
        'image/avif' => 'imagecreatefromavif',
        'image/bmp'  => 'imagecreatefrombmp',
    ];

    public static function getSubscribedEvents(): array
    {
        return [
            'onContentPrepareForm' => 'onContentPrepareForm',
            'onAjaxPhocacartimageupload' => 'onAjaxPhocacartimageupload',
            'onContentBeforeSave' => 'onContentBeforeSave',
            'onContentAfterSave' => 'onContentAfterSave',
            'onContentAfterDelete' => 'onContentAfterDelete',
        ];
    }

    public function onContentPrepareForm($event): void
    {
        // For modern events, arguments are wrapped in the Event object
        // Standard Joomla onContentPrepareForm passes ($form, $data)
        // In SubscriberInterface, we receive $event
        // $form = $event->getArgument(0);

        $form = $event->getArgument(0);
        if (!($form instanceof Form)) {
            return;
        }

        $formName = $form->getName();
        if ($formName !== 'com_phocacart.phocacartitem') {
            return;
        }

        // Check if we are in admin
        $app = Factory::getApplication();
        if (!$app->isClient('administrator')) {
            return;
        }

        // Load Phoca Cart libraries
        if (!class_exists('PhocacartFileThumbnail')) {
            $bootstrapFile = JPATH_ADMINISTRATOR . '/components/com_phocacart/libraries/bootstrap.php';
            if (file_exists($bootstrapFile)) {
                require_once $bootstrapFile;
            }
        }

        $input = $app->getInput();
        $productId = $input->getInt('id', 0);

        $tempToken = $app->getSession()->get('phocacart.image.temp_token', '');
        if (empty($tempToken)) {
            $tempToken = 'temp_' . substr(md5($app->getSession()->getId()), 0, 12);
            $app->getSession()->set('phocacart.image.temp_token', $tempToken);
        }

        // Remove original image fields
        $form->removeField('image');
        $form->removeField('additional_images');

        // Build uploader config
        $cParams = \PhocacartUtils::getComponentParameters();
        $maxFileSize = (int) $cParams->get('upload_maxsize', 3145728); // This is in bytes
        $allowedExtensions = \PhocacartUtilsSettings::getImageExtensions(2);// Allowed image extensions
        $pathImages = \PhocacartPath::getPath('productimage');

        $uploaderConfig = [
            'uploadUrl'         => Uri::root() . 'administrator/index.php?option=com_ajax&plugin=phocacartimageupload&group=system&format=json&action=upload',
            'deleteUrl'         => Uri::root() . 'administrator/index.php?option=com_ajax&plugin=phocacartimageupload&group=system&format=json&action=delete',
            'productId'         => $productId,
            'tempToken'         => $tempToken,
            'csrfToken'         => Session::getFormToken(),
            'maxFileSize'       => $maxFileSize, // Already in bytes from core
            'allowedExtensions' => $allowedExtensions, // Already an array from core
            'baseImagePath'     => Uri::root() . $pathImages['orig_rel_ds'],
            'maxImages'         => (int) $this->params->get('max_images', 0),
            'uploadBatchSize'   => (int) $this->params->get('upload_batch_size', 5)
        ];

        $langStrings = [
            'PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_DRAG_DROP_IMAGES_HERE'       => Text::_('PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_DRAG_DROP_IMAGES_HERE'),
            'PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_SELECT_FILES'                => Text::_('PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_SELECT_FILES'),
            'PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_MAIN_IMAGE_LABEL'            => Text::_('PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_MAIN_IMAGE_LABEL'),
            'PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_UPLOADING'                   => Text::_('PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_UPLOADING'),
            'PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_DELETE_IMAGE_CONFIRM'        => Text::_('PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_DELETE_IMAGE_CONFIRM'),
            'PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_DELETE_ALL_IMAGES'           => Text::_('PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_DELETE_ALL_IMAGES'),
            'PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_DELETE'                      => Text::_('PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_DELETE'),
            'PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_DELETE_ALL_IMAGES_CONFIRM'   => Text::_('PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_DELETE_ALL_IMAGES_CONFIRM'),
            'PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_UPLOAD_FAILED'         => Text::_('PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_UPLOAD_FAILED'),
            'PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_DELETE_FAILED'         => Text::_('PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_DELETE_FAILED'),
            'PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_INVALID_FILE_TYPE'     => Text::_('PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_INVALID_FILE_TYPE'),
            'PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_FILE_TOO_LARGE'        => Text::_('PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_FILE_TOO_LARGE'),
            'PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_DIFFERENT_PATH'              => Text::_('PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_DIFFERENT_PATH'),
            'PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_NO_ID_OR_TOKEN'        => Text::_('PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_NO_ID_OR_TOKEN'),
            'PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_NO_FILES_UPLOADED'     => Text::_('PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_NO_FILES_UPLOADED'),
            'PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_FILENAME_MISSING'      => Text::_('PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_FILENAME_MISSING'),
            'PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_INVALID_TOKEN'         => Text::_('PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_INVALID_TOKEN'),
            'PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_ACCESS_DENIED'         => Text::_('PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_ACCESS_DENIED'),
            'PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_UNKNOWN_ACTION'        => Text::_('PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_UNKNOWN_ACTION'),
            'PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_MAX_IMAGES_EXCEEDED'   => Text::_('PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_MAX_IMAGES_EXCEEDED')
        ];

        // Load existing images for display
        $existingImages = $this->loadExistingImages($productId);

        // Prepare JSON data for the field
        $configJson = htmlspecialchars(json_encode($uploaderConfig), ENT_QUOTES, 'UTF-8');
        $langJson = htmlspecialchars(json_encode($langStrings), ENT_QUOTES, 'UTF-8');
        $imagesJson = htmlspecialchars(json_encode($existingImages), ENT_QUOTES, 'UTF-8');

        // Add uploader field using custom field type
        // We name it 'image' effectively replacing the original field in the template rendering
        $fieldXml = '<form>
    <fieldset addfieldprefix="Joomla\Plugin\System\Phocacartimageupload\Field">
        <field
            name="image"
            type="Imageuploader"
            label=""
            uploader_config="' . $configJson . '"
            uploader_lang="' . $langJson . '"
            uploader_images="' . $imagesJson . '"
        />
    </fieldset>
</form>';

        $form->load($fieldXml);

        // Register assets
        $wa = $app->getDocument()->getWebAssetManager();
        $wa->registerAndUseStyle('plg_system_phocacartimageupload.uploader', 'media/plg_system_phocacartimageupload/css/phocacart-image-uploader.css', ['version' => 'auto']);
        $wa->registerAndUseScript('plg_system_phocacartimageupload.sortable', 'media/plg_system_phocacartimageupload/vendor/sortable/sortable.min.js', ['version' => 'auto'], ['defer' => true]);
        $wa->registerAndUseScript('plg_system_phocacartimageupload.uploader', 'media/plg_system_phocacartimageupload/js/phocacart-image-uploader.js', ['version' => 'auto'], ['defer' => true]);

        $app->getDocument()->addScriptOptions('phocacartImageUploadConfig', $uploaderConfig);
        $app->getDocument()->addScriptOptions('phocacartImageUploadLang', $langStrings);
        $app->getDocument()->addScriptOptions('phocacartImageUploadExistingImages', $existingImages);
    }

    protected function loadExistingImages(int $productId): array
    {
        $images = [];

        if ($productId < 1) {
            return $images;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        // Get main image
        $query = $db->getQuery(true)
            ->select($db->quoteName('image'))
            ->from($db->quoteName('#__phocacart_products'))
            ->where($db->quoteName('id') . ' = ' . (int)$productId);
        $db->setQuery($query);
        $mainImage = $db->loadResult();

        if (!empty($mainImage)) {
            $images[] = [
                'filename' => basename($mainImage),
                'fullPath' => $mainImage,
                'order'    => 1,
            ];
        }

        // Get additional images
        $query = $db->getQuery(true)
            ->select([$db->quoteName('id'), $db->quoteName('image'), $db->quoteName('ordering')])
            ->from($db->quoteName('#__phocacart_product_images'))
            ->where($db->quoteName('product_id') . ' = ' . (int)$productId)
            ->order($db->quoteName('ordering') . ' ASC');
        $db->setQuery($query);
        $additionalImages = $db->loadObjectList();

        if (!empty($additionalImages)) {
            foreach ($additionalImages as $idx => $img) {
                $images[] = [
                    'id'       => $img->id,
                    'filename' => basename($img->image),
                    'fullPath' => $img->image,
                    'order'    => count($images) + 1,
                ];
            }
        }

        return $images;
    }


    public function onAjaxPhocacartimageupload($event)
    {
        $app = Factory::getApplication();
        $input = $app->getInput();

        // Allow CORS if needed or standard headers
        header('Content-Type: application/json');

        if (!Session::checkToken('request')) {
            echo json_encode(['success' => false, 'message' => Text::_('PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_INVALID_TOKEN')]);
            exit;
        }

        $user = Factory::getUser();
        if ($user->guest || !$user->authorise('core.edit', 'com_phocacart')) {
            echo json_encode(['success' => false, 'message' => Text::_('PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_ACCESS_DENIED')]);
            exit;
        }

        // Load Phoca Cart libraries
        if (!class_exists('PhocacartFileThumbnail')) {
            $bootstrapFile = JPATH_ADMINISTRATOR . '/components/com_phocacart/libraries/bootstrap.php';
            if (file_exists($bootstrapFile)) {
                require_once $bootstrapFile;
            }
        }

        $action = $input->getCmd('action', '');

        $result = ['success' => false, 'message' => Text::_('PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_UNKNOWN_ACTION')];

        switch ($action) {
            case 'upload':
                $result = $this->handleUpload();
                break;
            case 'delete':
                $result = $this->handleDelete();
                break;
        }

        echo json_encode($result);
        exit;
    }

    protected function handleUpload(): array
    {
        $app = Factory::getApplication();
        $input = $app->getInput();

        $productId = $input->getInt('product_id', 0);
        $tempToken = $input->getString('temp_token', '');

        if ($productId > 0) {
            $folderName = (string) $productId;
        } elseif (!empty($tempToken)) {
            $folderName = $tempToken;
        } else {
            return ['success' => false, 'message' => Text::_('PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_NO_ID_OR_TOKEN')];
        }

        $pathImages = \PhocacartPath::getPath('productimage');
        $basePath = JPATH_ROOT . '/' . $pathImages['orig_rel_ds'];
        $uploadPath = $basePath . $folderName;
        $thumbPath = $uploadPath . '/thumbs';

        if (!Folder::exists($uploadPath)) {
            Folder::create($uploadPath);
        }
        if (!Folder::exists($thumbPath)) {
            Folder::create($thumbPath);
        }

        // Defense-in-depth: ensure these upload folders cannot execute scripts
        // even if a file with an unexpected extension ever ends up in them.
        $this->hardenUploadFolder($uploadPath);
        $this->hardenUploadFolder($thumbPath);

        $files = $input->files->get('phocacart_images', [], 'array');

        if (empty($files)) {
            return ['success' => false, 'message' => Text::_('PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_NO_FILES_UPLOADED')];
        }

        // Enforce max images limit
        $maxImages     = (int) $this->params->get('max_images', 50);
        $existingCount = $input->getInt('existing_count', 0);
        if ($maxImages > 0 && ($existingCount + count($files)) > $maxImages) {
            return ['success' => false, 'message' => Text::sprintf('PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_MAX_IMAGES_EXCEEDED', $maxImages)];
        }

        $cParams = \PhocacartUtils::getComponentParameters();
        $maxFileSize = (int) $cParams->get('upload_maxsize', 3145728); // This is in bytes

        // Phoca Cart's own extension => MIME type map is the single source of
        // truth for which image types this site allows. We never hardcode
        // our own list of "acceptable" types here - we only decide, per
        // entry Phoca Cart gives us, whether we are actually capable of
        // fully decoding that MIME type ourselves (via a real GD decode) in
        // order to validate it. Anything Phoca Cart allows that we cannot
        // decode-verify is excluded rather than trusted blindly.
        $imageTypeMap = (array) \PhocacartUtilsSettings::getImageExtensions(2, 2); // ['jpg' => 'image/jpeg', 'png' => 'image/png', ...]

        // GD's own decoder function for each MIME type (see MIME_DECODE_HANDLERS).
        // This is not a list of "allowed" types (that comes entirely from
        // $imageTypeMap above) - it is simply which built-in PHP/GD function
        // is capable of fully decoding a given MIME type, used both to
        // validate a file's real content below and to know a format is
        // actually supported on this server (some builds lack avif/webp
        // support, for instance).
        $decodeHandlers = self::MIME_DECODE_HANDLERS;

        // The stored file's extension is always taken from this map (based
        // on the file's real, decoded content), never from the client-
        // supplied filename - this is what prevents an uploaded file from
        // being stored with an extension the webserver might execute (.php,
        // .phtml, .shtml, .pht, double extensions, etc.) regardless of what
        // extension the attacker put on the original filename or what
        // Content-Type/name check might otherwise have been satisfied.
        $allowedMimeToExtension = [];
        foreach ($imageTypeMap as $ext => $mime) {
            $ext  = strtolower((string) $ext);
            $mime = strtolower((string) $mime);

            if (isset($allowedMimeToExtension[$mime])) {
                continue; // Already have a stored extension for this MIME type (e.g. jpg before jpeg).
            }

            if (!isset($decodeHandlers[$mime]) || !function_exists($decodeHandlers[$mime])) {
                continue; // Phoca Cart allows this type, but we can't decode-verify it - fail closed, don't accept it.
            }

            $allowedMimeToExtension[$mime] = $ext;
        }

        $uploadedFiles = [];

        foreach ($files as $file) {
            if ($file['error'] !== UPLOAD_ERR_OK) {
                continue;
            }

            if ($file['size'] > $maxFileSize) {
                continue;
            }

            if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
                continue;
            }

            // Strictly and fully decode the uploaded file before anything else
            // happens to it. This is a real decode (GD actually parses the
            // whole image), not just a header check or an extension/MIME-type
            // string comparison, so it cannot be fooled by a file that merely
            // *starts* with a valid-looking image header followed by arbitrary
            // attacker data (a classic polyglot upload-bypass technique).
            //
            // Nothing is written anywhere - not to the upload folder, not
            // under any name - unless this decode fully succeeds. If it
            // fails, we skip the file entirely: it is never moved, never
            // renamed, never touched again.
            $decoded = $this->decodeAndValidateImage($file['tmp_name'], $allowedMimeToExtension);

            if ($decoded === null) {
                // A file that claims (via its name and/or a superficial
                // Content-Type) to be an allowed image type but does not
                // decode as one is a strong signal of a deliberate upload
                // bypass attempt (extension smuggling, polyglot payloads,
                // truncated/corrupted crafted files), not an ordinary user
                // error - worth recording.
                try {
                    Log::add(
                        sprintf(
                            'Rejected upload "%s" from user #%d (IP %s): file is not a valid, fully-decodable, allowed image type.',
                            $file['name'] ?? '',
                            Factory::getUser()->id,
                            $input->server->getString('REMOTE_ADDR', '')
                        ),
                        Log::WARNING,
                        'plg_system_phocacartimageupload'
                    );
                } catch (\Throwable $e) {
                    // Logging must never block/break the rejection itself.
                }

                continue;
            }

            [$mimeType, $ext, $image] = [$decoded['mime'], $decoded['extension'], $decoded['image']];

            // The stored extension is derived exclusively from the validated
            // MIME type of the successfully decoded image. The name portion
            // is still sanitized from the client filename for readability,
            // but its extension is discarded entirely.
            $baseName = pathinfo(File::makeSafe($file['name']), PATHINFO_FILENAME);
            if ($baseName === '') {
                $baseName = 'image_' . time();
            }
            $filename = $baseName . '.' . $ext;
            $destFile = $uploadPath . '/' . $filename;

            $existingFilenames = $input->get('existing_filenames', [], 'array');

            $counter = 1;
            while (File::exists($destFile) || in_array($filename, $existingFilenames)) {
                $filename = $baseName . '_' . $counter . '.' . $ext;
                $destFile = $uploadPath . '/' . $filename;
                $counter++;
            }

            // Write the file by RE-ENCODING the decoded pixel data, rather
            // than copying the uploaded bytes verbatim. This guarantees the
            // file that lands in the web-accessible folder contains only
            // genuine image data GD itself produced - any trailing/appended
            // bytes an attacker put after a valid image header are
            // discarded, not just hidden behind a safe extension.
            $saved = $this->encodeAndSaveImage($image, $destFile, $mimeType);
            imagedestroy($image);

            if (!$saved) {
                if (File::exists($destFile)) {
                    File::delete($destFile);
                }
                continue;
            }

            $fileNo = $folderName . '/' . $filename;
            $thumb = \PhocacartFileThumbnail::getOrCreateThumbnail($fileNo, '', 1, 1, 1, 0, 'productimage');

            $uploadedFiles[] = [
                'filename'   => $filename,
                'thumbnails' => [
                    'small'  => $thumb->thumb_name_s_no_rel ?? '',
                    'medium' => $thumb->thumb_name_m_no_rel ?? '',
                    'large'  => $thumb->thumb_name_l_no_rel ?? '',
                ]
            ];
        }

        if (empty($uploadedFiles)) {
            return ['success' => false, 'message' => Text::_('PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_UPLOAD_FAILED')];
        }

        return [
            'success' => true,
            'files'   => $uploadedFiles,
            'folder'  => $folderName
        ];
    }

    /**
     * Write hardening files (.htaccess for Apache, web.config for IIS) into an
     * upload folder so that no file placed in it - regardless of extension -
     * can ever be executed as a script by the webserver. This is
     * defense-in-depth on top of the strict decode-based validation enforced
     * at upload time; it also protects any legacy files that may already
     * exist on disk from before this fix.
     *
     * Writing .htaccess/web.config is controlled by the "harden_upload_folder"
     * plugin parameter (enabled by default), because some hosts/server
     * configurations reject or misinterpret directives in these files (e.g.
     * missing AllowOverride, IIS handler mapping conflicts, other software
     * already managing its own .htaccess in the same tree). The index.html
     * placeholder (directory listing protection) is unconditional and always
     * written, since it cannot break a server configuration.
     *
     * @param   string  $fullPath  Absolute filesystem path to the upload folder.
     *
     * @return  void
     *
     * @since   6.1.4
     */
    private function hardenUploadFolder(string $fullPath): void
    {
        $writeServerConfigFiles = (bool) $this->params->get('harden_upload_folder', 1);

        if ($writeServerConfigFiles) {
            $htaccessFile = $fullPath . '/.htaccess';
            if (!is_file($htaccessFile)) {
                $htaccess = <<<HTACCESS
# Prevent execution of any script in this folder, regardless of extension.
# This folder only ever contains user-uploaded image files.

# <IfModule mod_php.c>
#    php_flag engine off
# </IfModule>
<IfModule mod_php7.c>
    php_flag engine off
</IfModule>
<IfModule mod_php8.c>
    php_flag engine off
</IfModule>

<FilesMatch "\.(?:php[0-9]?|phtml|pht|phar|shtml|shtm|sht|cgi|pl|py|asp|aspx|jsp|jspx|htaccess|htpasswd)$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order deny,allow
        Deny from all
    </IfModule>
</FilesMatch>

<IfModule mod_mime.c>
    RemoveHandler .php .php3 .php4 .php5 .php7 .phtml .pht .phar .cgi .pl .py .asp .aspx .jsp .jspx .shtml .shtm .sht
    RemoveType .php .php3 .php4 .php5 .php7 .phtml .pht .phar .cgi .pl .py .asp .aspx .jsp .jspx .shtml .shtm .sht
</IfModule>

# Options -ExecCGI -Indexes
AddType text/plain .php .php3 .php4 .php5 .php7 .phtml .pht .phar .cgi .pl .py .asp .aspx .jsp .jspx .shtml .shtm .sht

HTACCESS;
                @file_put_contents($htaccessFile, $htaccess);
            }

            $webConfigFile = $fullPath . '/web.config';
            if (!is_file($webConfigFile)) {
                $webConfig = <<<WEBCONFIG
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
    <system.webServer>
        <handlers>
            <clear />
            <add name="StaticFile" path="*" verb="*" modules="StaticFileModule" resourceType="Either" requireAccess="Read" />
        </handlers>
        <security>
            <requestFiltering>
                <fileExtensions allowUnlisted="true">
                    <add fileExtension=".php" allowed="false" />
                    <add fileExtension=".phtml" allowed="false" />
                    <add fileExtension=".asp" allowed="false" />
                    <add fileExtension=".aspx" allowed="false" />
                    <add fileExtension=".cgi" allowed="false" />
                    <add fileExtension=".shtml" allowed="false" />
                    <add fileExtension=".shtm" allowed="false" />
                    <add fileExtension=".sht" allowed="false" />
                </fileExtensions>
            </requestFiltering>
        </security>
    </system.webServer>
</configuration>

WEBCONFIG;
                @file_put_contents($webConfigFile, $webConfig);
            }
        }

        // Directory-listing protection is unconditional: it cannot break a
        // server configuration the way .htaccess/web.config directives can,
        // so it is written regardless of the "harden_upload_folder" setting.
        if (!is_file($fullPath . '/index.html')) {
            @file_put_contents($fullPath . '/index.html', '<!DOCTYPE html><title></title>');
        }
    }

    /**
     * Strictly validate that a file is a genuine, fully-decodable image of an
     * allowed type, and return the decoded GD resource.
     *
     * Unlike a file-extension check or getimagesize() (which only parses
     * header bytes and can be fooled by files that are truncated, corrupted,
     * or have arbitrary trailing/appended data after a valid-looking header -
     * the classic image-header-plus-embedded-script polyglot pattern), this
     * performs a real, full decode of the image data using the same GD
     * function that will later be used to process it. If the file cannot be
     * completely and cleanly decoded, it is rejected outright.
     *
     * Nothing is written to disk by this method. The caller must not write
     * the original uploaded bytes anywhere; instead it should re-encode the
     * returned GD resource to disk, which guarantees the stored file
     * contains only genuine pixel data and cannot carry any attacker-
     * appended payload.
     *
     * @param   string                 $path                  Absolute path to the file to validate (e.g. a PHP tmp upload).
     * @param   array<string, string>  $allowedMimeToExtension MIME type => extension map of acceptable image types.
     *
     * @return  array{mime: string, extension: string, image: \GdImage}|null  Null if the file is not a valid, allowed image.
     *
     * @since   6.1.4
     */
    private function decodeAndValidateImage(string $path, array $allowedMimeToExtension): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($path);

        if (!isset($allowedMimeToExtension[$mimeType])) {
            return null;
        }

        $handler = self::MIME_DECODE_HANDLERS[$mimeType] ?? null;

        if ($handler === null || !function_exists($handler)) {
            return null;
        }

        // Fully decode the file. Suppress the E_WARNING that GD's decoders
        // emit for malformed input - a decode failure is communicated to us
        // via the return value (false), and we handle it explicitly rather
        // than letting a PHP warning leak internal file paths or clutter logs.
        $image = @$handler($path);

        if (!($image instanceof \GdImage)) {
            return null;
        }

        $width  = imagesx($image);
        $height = imagesy($image);

        if ($width < 1 || $height < 1 || $width > 20000 || $height > 20000) {
            imagedestroy($image);
            return null;
        }

        return [
            'mime'      => $mimeType,
            'extension' => $allowedMimeToExtension[$mimeType],
            'image'     => $image,
        ];
    }

    /**
     * Re-encode and save a decoded GD image resource to disk in its own
     * format.
     *
     * This is the only way this plugin should ever persist an uploaded
     * image: writing out freshly re-encoded pixel data (rather than copying
     * the uploaded bytes verbatim) means the stored file cannot contain any
     * attacker-appended trailing data, embedded scripts, or other payload -
     * only what GD itself chooses to write for that format.
     *
     * @param   \GdImage  $image     The decoded GD image.
     * @param   string    $path      Destination path to write to.
     * @param   string    $mimeType  MIME type determining the output format.
     *
     * @return  bool
     *
     * @since   6.1.4
     */
    private function encodeAndSaveImage(\GdImage $image, string $path, string $mimeType): bool
    {
        switch ($mimeType) {
            case 'image/jpeg':
                return imagejpeg($image, $path, 90);
            case 'image/png':
                return imagepng($image, $path, 6);
            case 'image/gif':
                return imagegif($image, $path);
            case 'image/webp':
                return imagewebp($image, $path, 90);
            case 'image/avif':
                return function_exists('imageavif') ? imageavif($image, $path, 80) : false;
            case 'image/bmp':
                return imagebmp($image, $path);
            default:
                return false;
        }
    }

    public function handleDelete(): array
    {
        $app = Factory::getApplication();
        $input = $app->getInput();

        $productId = $input->getInt('product_id', 0);
        $tempToken = $input->getString('temp_token', '');
        $filename  = $input->getString('filename', '');
        $fullpath  = $input->getString('fullpath', '');

        if (empty($filename)) {
            return ['success' => false, 'message' => Text::_('PLG_SYSTEM_PHOCACARTIMAGEUPLOAD_ERROR_FILENAME_MISSING')];
        }

        // Never trust these to be simple relative names: strip any path
        // component/traversal sequence so they can only ever refer to a file
        // directly inside the resolved target directory, never a parent.
        $filename = basename($filename);
        $fullpath = str_replace('\\', '/', $fullpath);
        $fullpath = ltrim($fullpath, '/');

        $pathImages = \PhocacartPath::getPath('productimage');
        $baseRelPath = $pathImages['orig_rel_ds'];
        $baseAbsPath = JPATH_ROOT . '/' . $baseRelPath;

        $realBase = realpath($baseAbsPath);
        if ($realBase === false) {
            return ['success' => true];
        }

        // Determine if we should physically delete
        $deleteImages = (int)$this->params->get('delete_images', 0);
        $doPhysicalDelete = false;

        if ($deleteImages === 1) {
            // Delete regardless of which product/temp folder the file is in,
            // but still only ever a file that resolves inside the images
            // base folder (enforced below via the realpath containment
            // check) - "regardless of path" was never meant to mean
            // "anywhere on disk".
            $doPhysicalDelete = true;
        } else {
            // Only if it's in the current product's folder or temp folder
            $folderName = '';
            if ($productId > 0) {
                $folderName = (string) $productId;
            } elseif (!empty($tempToken)) {
                $folderName = $tempToken;
            }

            if (!empty($folderName) && strpos($fullpath, $folderName . '/') === 0) {
                $doPhysicalDelete = true;
            }
        }

        if ($doPhysicalDelete) {
            $fileToDelete = $baseAbsPath . $fullpath;

            // Containment check: the resolved real path of the target must
            // sit inside the images base folder. This blocks "../" traversal
            // regardless of how it's disguised (encoded, doubled separators,
            // etc.), since realpath() fully resolves the path before we
            // compare it - the earlier basename()/prefix checks above are
            // useful hygiene but this is the check that actually matters.
            $realTarget = realpath($fileToDelete);

            if ($realTarget !== false
                && $realTarget !== $realBase
                && strncmp($realTarget, $realBase . DIRECTORY_SEPARATOR, strlen($realBase) + 1) === 0
            ) {
                if (File::exists($realTarget)) {
                    File::delete($realTarget);
                }

                $thumbDir = dirname($realTarget) . '/thumbs';
                $realThumbDir = realpath($thumbDir);

                // Delete thumbnails - same containment check applied to the
                // thumbs directory before touching anything in it.
                if ($realThumbDir !== false
                    && strncmp($realThumbDir, $realBase . DIRECTORY_SEPARATOR, strlen($realBase) + 1) === 0
                ) {
                    $sizes = ['l', 'm', 's'];
                    foreach ($sizes as $size) {
                        $thumbName = 'phoca_thumb_' . $size . '_' . $filename;
                        $thumbFile = $realThumbDir . '/' . $thumbName;
                        if (File::exists($thumbFile)) {
                            File::delete($thumbFile);
                        }
                    }
                }
            }
        }

        return ['success' => true];
    }

    public function onContentBeforeSave($event)
    {

        // Debugging Event Arguments
        $args = $event->getArguments();
        ///error_log('[PhocaCartImageUpload] onContentBeforeSave triggered (Modern). Argument Keys: ' . implode(', ', array_keys($args)));

        // Try Named Arguments (Modern Phoca Dispatcher)
        if (isset($args['context'])) {
            $context = $args['context'];
            $table = $args['subject'] ?? null;
            $isNew = $args['isNew'] ?? false;
            $data = $args['data'] ?? [];
            ///error_log('[PhocaCartImageUpload] Using Named Arguments. Context: ' . $context);
        } else {
            // Fallback to Indexed Arguments (Legacy/Standard Trigger)
            $context = $event->getArgument(0);
            $table = $event->getArgument(1);
            $isNew = $event->getArgument(2);
            $data = $event->getArgument(3);
             ///error_log('[PhocaCartImageUpload] Using Indexed Arguments. Context: ' . $context);
        }

        if ($context !== 'com_phocacart.phocacartitem') {
            return;
        }

        ///error_log('[PhocaCartImageUpload] Context match. Checking data...');

        $rawInput = Factory::getApplication()->getInput();
        $jform = $rawInput->get('jform', [], 'array');

        $imagesData = null;
        if (isset($jform['phocacartimageupload_images_data'])) {
             ///error_log('[PhocaCartImageUpload] Found phocacartimageupload_images_data in Raw Input! Length: ' . strlen($jform['phocacartimageupload_images_data']));
             $imagesData = $jform['phocacartimageupload_images_data'];
        }

        if (empty($imagesData) && !empty($data['phocacartimageupload_images_data'])) {
             $imagesData = $data['phocacartimageupload_images_data'];
        }

        if (empty($imagesData)) {
            ///error_log('[PhocaCartImageUpload] phocacartimageupload_images_data is EMPTY or MISSING in both Raw Input and filtered Data');
            return true;
        }

        $data['phocacartimageupload_images_data'] = $imagesData;
        ///error_log('[PhocaCartImageUpload] Data found: ' . substr($imagesData, 0, 100) . '...');

        $app = Factory::getApplication();
        $input = $app->getInput();
        // Decode image data
        $images = json_decode(html_entity_decode($data['phocacartimageupload_images_data']), true);

        if ($images === null) {
            return true;
        }

        // Prepare main image
        if (!empty($images) && isset($images[0])) {
            $mainImage = $images[0];
            // Format for DB: we need relative path from root
            $table->image = $mainImage['fullPath'];
        } else {
            $table->image = '';
        }

        // Additional images are saved via model, but we can prepare them in $data for the model if it supports it
        // Phoca Cart model 'save' method calls 'storeImagesByProductId' using $data['phocacart_additional_images']?
        // Let's reconstruct 'additional_images' array for the model to use
        $additionalImages = [];
        if (count($images) > 1) {
            for ($i = 1; $i < count($images); $i++) {
                $img = $images[$i];
                $additionalImages[] = [
                    'image' => $img['fullPath'],
                    'ordering' => $img['order'],
                    'id' => isset($img['id']) ? $img['id'] : 0
                ];
            }
        }

        // We will pass the processed images to AfterSave via a property
        $this->processedImages = $images;

        // Reconstruct 'additional_images' array for the model to use and avoid Warnings
        $additionalImages = [];
        if (count($images) > 1) {
            for ($i = 1; $i < count($images); $i++) {
                $img = $images[$i];
                $additionalImages[] = [
                    'image' => $img['fullPath'],
                    'ordering' => $img['order'],
                    'id' => isset($img['id']) ? $img['id'] : 0
                ];
            }
        }

        // Inject into $data so standard Product Model can save them!
        $data['additional_images'] = $additionalImages;

        // Also inject into $table object because $data is not passed by reference to the Model,
        // but $table IS. The Model can then check $table->additional_images.
        if (is_object($table)) {
            $table->additional_images = $additionalImages;
             ///error_log('[PhocaCartImageUpload] Injected additional_images into Table object.');
        }

        // Also ensure 'image' (main) is in $data just in case
        if (isset($mainImage)) {
            $data['image'] = $mainImage['fullPath'];
        } else {
            $data['image'] = '';
        }

        // Try to update the event argument 'data'
        try {
             if (is_object($event) && method_exists($event, 'offsetSet')) {
                 $event['data'] = $data;
                 ///error_log('[PhocaCartImageUpload] Updated Event data with additional_images.');
             }
        } catch (\Throwable $e) {
             ///error_log('[PhocaCartImageUpload] Failed to update Event data: ' . $e->getMessage());
        }

        return true;
    }

    public function onContentAfterSave($event)
    {
        // Debugging Event Arguments
        $args = $event->getArguments();
        ///error_log('[PhocaCartImageUpload] onContentAfterSave triggered. Argument Keys: ' . implode(', ', array_keys($args)));

        // Try Named Arguments (Modern Phoca Dispatcher)
        if (isset($args['context'])) {
            $context = $args['context'];
            $table = $args['subject'] ?? null;
            $isNew = $args['isNew'] ?? false;
            $data = $args['data'] ?? [];
        } else {
             // Fallback to Indexed Arguments
            $context = $event->getArgument(0);
            $table = $event->getArgument(1);
            $isNew = $event->getArgument(2);
            $data = $event->getArgument(3);
        }

        if ($context !== 'com_phocacart.phocacartitem') {
            return;
        }

        $id = $table->id;
        $isBatchCopy = $data['is_batch_copy'] ?? false;
        $copyImagesBatch = (int)$this->params->get('copy_images_batch', 1);

        if ($isBatchCopy && $id > 0 && $copyImagesBatch > 0) {
            $idSource = $data['id_source'] ?? 0;
             if ($idSource > 0) {
                 ///error_log('[PhocaCartImageUpload] Detected Batch Copy. Source: ' . $idSource . ', Dest: ' . $id);
                 $this->handleBatchCopy($idSource, $id);
             }
             return;
        }

        // Logic for renaming temp folder to product ID folder
        $app = Factory::getApplication();
        $tempToken = $app->getSession()->get('phocacart.image.temp_token');

        if ($this->processedImages === null) {
              ///error_log('[PhocaCartImageUpload] processedImages property is null. Trying fallback parse.');
              // Try to parse data again if property not set (e.g. if BeforeSave didn't run or context lost)
              if (!empty($data['phocacartimageupload_images_data'])) {
                 $this->processedImages = json_decode(html_entity_decode($data['phocacartimageupload_images_data']), true);
              }
        }

        if ($this->processedImages === null) {
            return true;
        }

        $id = $table->id;

        // Handle Temp Folder Rename for New Products
        // Check if images are in a temp folder
        $firstImage = $this->processedImages[0] ?? null;
        $tempToken = '';

        if ($firstImage && strpos($firstImage['fullPath'], 'temp_') === 0) {
            $parts = explode('/', $firstImage['fullPath']);
            $tempToken = $parts[0]; // temp_XXXX
        } else if ($app->getSession()->get('phocacart.image.temp_token')) {
             // Fallback if no images but token exists (maybe all deleted)
             // But if all deleted, we might not need to rename anything.
             // If we have mixed images (some already moved?), assume consistent folder.
             // But the uploader forces one product folder.
             // If we found a temp folder in the paths, let's rename it.
        }

        $pathImages = \PhocacartPath::getPath('productimage');

        if (!empty($tempToken) && $id > 0) {
            $basePath = JPATH_ROOT . '/' . $pathImages['orig_rel_ds'];
            $oldPath = $basePath . $tempToken;
            $newPath = $basePath . $id;

            if (Folder::exists($oldPath)) {
                // Remove old folder if it exists (cleanup)
                if (Folder::exists($newPath)) {
                    Folder::delete($newPath); // Dangerous? Phoca usually creates ID folder.
                    // If we are saving a new item, ID folder shouldn't contain much or we overwrite.
                }

                Folder::move($oldPath, $newPath);
            }

            // Now update paths in $this->processedImages
            foreach ($this->processedImages as &$img) {
                $img['fullPath'] = \PhocacartImage::replaceTempToIdPath($img['fullPath'], $id);
            }
            unset($img);

            // Clear token
            $app->getSession()->set('phocacart.image.temp_token', null);

            // Clean up other abandoned temp folders too
            $this->cleanUpTempFolders();

            // Update Main Table Image with new path (Only if we renamed, which handles new items)
            $db = Factory::getContainer()->get('DatabaseDriver');
            if (isset($this->processedImages[0])) {
                $newMainImage = $this->processedImages[0]['fullPath'];
                $query = $db->getQuery(true)
                    ->update($db->quoteName('#__phocacart_products'))
                    ->set($db->quoteName('image') . ' = ' . $db->quote($newMainImage))
                    ->where($db->quoteName('id') . ' = ' . (int)$id);
                $db->setQuery($query)->execute();

                // Also update Additional Images Table (replace temp path with ID path)
                $oldPathPrefix = $tempToken . '/';
                $newPathPrefix = $id . '/';

                $query = $db->getQuery(true)
                    ->update($db->quoteName('#__phocacart_product_images'))
                    ->set($db->quoteName('image') . ' = REPLACE(' . $db->quoteName('image') . ', ' . $db->quote($oldPathPrefix) . ', ' . $db->quote($newPathPrefix) . ')')
                    ->where($db->quoteName('product_id') . ' = ' . (int)$id)
                    ->where($db->quoteName('image') . ' LIKE ' . $db->quote($oldPathPrefix . '%'));
                $db->setQuery($query)->execute();
            } else {
                // All images deleted
                $query = $db->getQuery(true)
                    ->update($db->quoteName('#__phocacart_products'))
                    ->set($db->quoteName('image') . ' = ' . $db->quote(''))
                    ->where($db->quoteName('id') . ' = ' . (int)$id);
                $db->setQuery($query)->execute();
            }
        } else {
             // For Existing Products (No temp token rename), we MUST still update the Main Image
             // because BeforeSave modification of $table might have been overwritten by Core Model binding.
             if ($id > 0) {
                $db = Factory::getContainer()->get('DatabaseDriver');
                $newMainImage = isset($this->processedImages[0]) ? $this->processedImages[0]['fullPath'] : '';

                // We should only update if it changed? No, force update is safer.
                $query = $db->getQuery(true)
                    ->update($db->quoteName('#__phocacart_products'))
                    ->set($db->quoteName('image') . ' = ' . $db->quote($newMainImage))
                    ->where($db->quoteName('id') . ' = ' . (int)$id);
                $db->setQuery($query)->execute();
             }
        }

        // Additional Images are now saved via Core Model (PhocaCartItem::save)
        // because we injected 'additional_images' into the $table object in onContentBeforeSave.
        // And we modified the Model to read it from there.

        return true;
    }

    public function onContentAfterDelete($event)
    {
        $context = $event[0];
        $table   = $event[1];

        if ($context !== 'com_phocacart.phocacartitem') {
            return;
        }

        $deleteFolderProduct = (int)$this->params->get('delete_folder_product', 0);
        if ($deleteFolderProduct !== 1) {
            return;
        }

        $productId = (int)($table->id ?? 0);
        if ($productId <= 0) {
            return;
        }

        $pathImages = \PhocacartPath::getPath('productimage');
        if (empty($pathImages)) {
            return;
        }

        $folderName = (string)$productId;
        $baseRelPath = $pathImages['orig_rel_ds'];
        $folderPath = JPATH_ROOT . '/' . $baseRelPath . $folderName;

        if (Folder::exists($folderPath)) {
            try {
                Folder::delete($folderPath);
            } catch (\Throwable $e) {
                // Log error if needed
            }
        }
    }
    protected function handleBatchCopy(int $idSource, int $idDest): void
    {
        if ($idSource < 1 || $idDest < 1 || $idSource === $idDest) {
            return;
        }

        $copyImagesBatch = (int)$this->params->get('copy_images_batch', 1);
        if ($copyImagesBatch === 0) {
            return;
        }

        $pathImages = \PhocacartPath::getPath('productimage');
        $basePath = JPATH_ROOT . '/' . $pathImages['orig_rel_ds'];
        $oldFolder = $basePath . $idSource;
        $newFolder = $basePath . $idDest;
        $thumbDir  = $newFolder . '/thumbs';

        // 1. Physically copy the folder (handles all images that were in the product folder)
        // Only copy if source exists and destination does NOT exist (to avoid exception)
        if (Folder::exists($oldFolder) && !Folder::exists($newFolder)) {
            try {
                // Ensure parent exists
                if (!Folder::exists(dirname($newFolder))) {
                    Folder::create(dirname($newFolder));
                }
                Folder::copy($oldFolder, $newFolder);
            } catch (\Throwable $e) {
                ///error_log('[PhocaCartImageUpload] Batch Copy Error: ' . $e->getMessage() . ' | Source: ' . $oldFolder . ' | Dest: ' . $newFolder);
            }
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        // Helper function to process and copy image paths
        $processImagePath = function($imagePath) use ($idSource, $idDest, $basePath, $copyImagesBatch, $newFolder, $thumbDir) {
            if (empty($imagePath)) {
                return $imagePath;
            }

            // Case A: Image starts with source product ID folder (already handled by Folder::copy if folder exists)
            if (strpos($imagePath, (string)$idSource . '/') === 0) {
                return str_replace($idSource . '/', $idDest . '/', $imagePath);
            }

            // Case B: Image is outside (legacy/shared) and YES (ALL) setting is active
            if ($copyImagesBatch === 2) {
                // Prevent "loop" or overwriting if it already points to the destination product folder
                if (strpos($imagePath, (string)$idDest . '/') === 0) {
                    return $imagePath;
                }

                $filename = basename($imagePath);
                $sourceFileAbs = $basePath . $imagePath;
                $destFileRel = $idDest . '/' . $filename;
                $destFileAbs = $basePath . $destFileRel;

                // Optimization: if it is already in the product folder relative to basePath, skip
                if ($imagePath === $destFileRel) {
                    return $imagePath;
                }

                if (File::exists($sourceFileAbs)) {
                    // Create destination folder if not exists (might not exit if idSource folder didn't exist)
                    if (!Folder::exists($newFolder)) {
                        Folder::create($newFolder);
                    }
                    if (!Folder::exists($thumbDir)) {
                        Folder::create($thumbDir);
                    }

                    // Check if file already exists in destination (per user: if something is there, don't copy/overwrite)
                    if (!File::exists($destFileAbs)) {
                        if (File::copy($sourceFileAbs, $destFileAbs)) {
                            // Copy thumbnails too
                            $sizes = ['l', 'm', 's'];
                            $sourceDir = dirname($sourceFileAbs);
                            foreach ($sizes as $size) {
                                $thumbName = 'phoca_thumb_' . $size . '_' . $filename;
                                $sourceThumb = $sourceDir . '/thumbs/' . $thumbName;
                                if (!File::exists($sourceThumb)) {
                                    $sourceThumb = $sourceDir . '/' . $thumbName; // Fallback
                                }

                                if (File::exists($sourceThumb)) {
                                    File::copy($sourceThumb, $thumbDir . '/' . $thumbName);
                                }
                            }
                            return $destFileRel;
                        }
                    } else {
                        // File already exists in dest, update DB to point to it as it's the intended consistent behavior
                        return $destFileRel;
                    }
                }
            }

            return $imagePath;
        };

        // 2. Update Main Image in #__phocacart_products
        $query = $db->getQuery(true)
            ->select($db->quoteName('image'))
            ->from($db->quoteName('#__phocacart_products'))
            ->where($db->quoteName('id') . ' = ' . (int)$idDest);
        $db->setQuery($query);
        $mainImage = $db->loadResult();

        if ($mainImage) {
            $newMainImage = $processImagePath($mainImage);
            if ($newMainImage !== $mainImage) {
                $query = $db->getQuery(true)
                    ->update($db->quoteName('#__phocacart_products'))
                    ->set($db->quoteName('image') . ' = ' . $db->quote($newMainImage))
                    ->where($db->quoteName('id') . ' = ' . (int)$idDest);
                $db->setQuery($query)->execute();
            }
        }

        // 3. Update Additional Images in #__phocacart_product_images
        $query = $db->getQuery(true)
            ->select([$db->quoteName('id'), $db->quoteName('image')])
            ->from($db->quoteName('#__phocacart_product_images'))
            ->where($db->quoteName('product_id') . ' = ' . (int)$idDest);
        $db->setQuery($query);
        $items = $db->loadObjectList();

        if (!empty($items)) {
            foreach ($items as $item) {
                $newImagePath = $processImagePath($item->image);
                if ($newImagePath !== $item->image) {
                    $updQuery = $db->getQuery(true)
                        ->update($db->quoteName('#__phocacart_product_images'))
                        ->set($db->quoteName('image') . ' = ' . $db->quote($newImagePath))
                        ->where($db->quoteName('id') . ' = ' . (int)$item->id);
                    $db->setQuery($updQuery)->execute();
                }
            }
        }
    }

    protected function cleanUpTempFolders(): void
    {
        $pathImages = \PhocacartPath::getPath('productimage');
        if (empty($pathImages)) {
            return;
        }

        $basePath = JPATH_ROOT . '/' . $pathImages['orig_rel_ds'];
        if (!Folder::exists($basePath)) {
            return;
        }

        $folders = Folder::folders($basePath, '^temp_');
        $now = time();
        $sessionLifetime = (int)Factory::getApplication()->get('lifetime', 15);
        $expiryTime = $sessionLifetime * 60; // Use Joomla session lifetime (converted to seconds)

        foreach ($folders as $folder) {
            $folderPath = $basePath . $folder;
            $mtime = filemtime($folderPath);

            if (($now - $mtime) > $expiryTime) {
                try {
                    Folder::delete($folderPath);
                } catch (\Throwable $e) {
                    // Ignore errors during mass cleanup
                }
            }
        }
    }
}
