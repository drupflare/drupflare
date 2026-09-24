<?php

declare(strict_types=1);

namespace Drupal\drupflare\File;

use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystem;

/**
 * Moves an upload the host parsed, which `move_uploaded_file()` refuses.
 *
 * PHP moves a file only when its path is in the table of this request's uploads, and only a real
 * POST SAPI fills that table. This interpreter has none, so the host parses multipart itself and
 * writes each file part to a temp path. `move_uploaded_file()` refused every one of them, and
 * `FileUploadHandler` answered "Could not move uploaded file" for every upload on every site.
 *
 * The host records each temp path it wrote in `$GLOBALS['__cfw_uploads']` and clears the list at
 * the start of the next request, so this accepts the same set PHP would: the files this request
 * uploaded. Any other path goes to core's check unchanged.
 */
class CfwFileSystem extends FileSystem
{
	/**
	 * {@inheritdoc}
	 */
	public function moveUploadedFile($filename, $uri)
	{
		if (!isset($GLOBALS['__cfw_uploads'][$filename])) {
			return parent::moveUploadedFile($filename, $uri);
		}
		try {
			$this->move($filename, $uri, FileExists::Replace);
		} catch (FileException) {
			return false;
		}
		unset($GLOBALS['__cfw_uploads'][$filename]);
		return true;
	}
}
