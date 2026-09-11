<?php

declare(strict_types=1);

namespace Drupal\drupflare\Plugin\ImageToolkit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\ImageToolkit\ImageToolkitOperationManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Drupal\drupflare\Degradation;
use Drupal\drupflare\Host;
use Drupal\Core\ImageToolkit\ImageToolkitBase;
use Drupal\Core\ImageToolkit\Attribute\ImageToolkit;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * An image toolkit that never processes an image.
 *
 * Gd is not compiled into the wasm build -- it cost 684,821 bytes and
 * the platform already has Cloudflare Images, which resizes on delivery from a URL
 * rather than by rewriting files. So the correct toolkit here does NOT generate
 * derivatives: it records the source dimensions so Drupal's render pipeline can
 * emit correct width and height attributes, and reports every manipulation as
 * successful-but-deferred so image styles do not fail.
 *
 * `apply()` returning TRUE without touching bytes means a
 * style-derived file on disk is the original. That is correct for delivery through
 * an image-resizing CDN and wrong for anything that reads the derivative's own
 * pixels. Drupal core does not; contrib that does will see full-size images.
 *
 * Dimensions come from getimagesize(), which is part of PHP's core and does not
 * need gd.
 */
#[
	ImageToolkit(
		id: 'cfw_images',
		title: new TranslatableMarkup('Cloudflare Images (delivery-time resizing)'),
	),
]
class CfwImageToolkit extends ImageToolkitBase
{
	/**
	 * The logger channel is named, because `LoggerInterface` alone cannot autowire.
	 *
	 * Without this the plugin is discovered and available and then throws
	 * `AutowiringFailedException` on instantiation, which is the same outage one layer later.
	 */
	public function __construct(
		array $configuration,
		string $plugin_id,
		array $plugin_definition,
		ImageToolkitOperationManagerInterface $operation_manager,
		#[Autowire('logger.channel.image')] LoggerInterface $logger,
		ConfigFactoryInterface $config_factory,
	) {
		parent::__construct(
			$configuration,
			$plugin_id,
			$plugin_definition,
			$operation_manager,
			$logger,
			$config_factory,
		);
	}

	/**
	 * Source width, or NULL when unknown.
	 */
	protected ?int $width = null;

	/**
	 * Source height, or NULL when unknown.
	 */
	protected ?int $height = null;

	/**
	 * Detected MIME type.
	 */
	protected string $mimeType = '';

	/**
	 * {@inheritdoc}
	 */
	public function isValid()
	{
		return $this->width !== null && $this->height !== null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function parseFile()
	{
		$path = $this->getSource();
		if ($path === null || $path === '') {
			return false;
		}
		// getimagesize() reads only the header, so this is cheap and gd-free
		$info = @getimagesize($path);
		if ($info === false) {
			// every image style silently produces nothing from here, so the failure has to say so
			Degradation::record(
				'image.dimensions',
				'getimagesize() could not read this file header, so no image style can be built from it.',
			);
			return false;
		}
		$this->width = (int) $info[0];
		$this->height = (int) $info[1];
		// getimagesize() always sets 'mime' on a successful read, so there is nothing to
		// fall back to; a ?? here would read as a case that can happen
		$this->mimeType = (string) $info['mime'];
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function save($destination)
	{
		// A derivative is not written. Copying the original would double storage for
		// no benefit, since delivery-time resizing serves from the source URL.
		if ($destination === $this->getSource()) {
			return true;
		}
		// declared because the bytes at $destination are the FULL-SIZE original: a module that
		// reads a derivative's pixels gets the source, and a flush reports success either way
		Degradation::record(
			'image.derivatives',
			'image styles are applied at delivery rather than written to disk, so a saved derivative is a copy of the original.',
			'untested',
		);
		// `@` because a failed copy is a false return, not an exception, and the warning it emits
		// says nothing a caller can act on -- but the REASON is worth keeping, so it is recorded
		// rather than discarded with the warning
		$copied = @copy($this->getSource(), $destination);
		if (!$copied) {
			$error = error_get_last();
			Degradation::record(
				'image.derivative_write',
				sprintf(
					'a derivative could not be written to %s: %s',
					$destination,
					$error['message'] ?? 'no reason reported',
				),
				'untested',
			);
		}
		return $copied;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getMimeType()
	{
		return $this->mimeType;
	}

	/**
	 * Reports the image width discovered by the host-side decoder.
	 *
	 * @return int|null
	 *   Width in pixels, or NULL if nothing has been parsed yet.
	 */
	public function getWidth(): ?int
	{
		return $this->width;
	}

	/**
	 * Reports the image height discovered by the host-side decoder.
	 *
	 * @return int|null
	 *   Height in pixels, or NULL if nothing has been parsed yet.
	 */
	public function getHeight(): ?int
	{
		return $this->height;
	}

	/**
	 * Records the dimensions a manipulation WOULD have produced.
	 *
	 * Image style plugins call this through apply(); reporting the post-manipulation
	 * size keeps the width/height attributes in the rendered markup correct even
	 * though no pixels moved.
	 */
	public function setDimensions(?int $width, ?int $height): void
	{
		$this->width = $width;
		$this->height = $height;
	}

	/**
	 * {@inheritdoc}
	 *
	 * ImageToolkitBase implements PluginFormInterface but leaves these two abstract,
	 * so a toolkit that omits them is not a loadable class at all. `php -l` cannot
	 * see that -- it is a linkage error against real Drupal, raised the first time
	 * something autoloads the class -- and this file was "lint-clean" for a day with
	 * a guaranteed fatal in it. There is nothing to configure, so the form is empty.
	 */
	public function buildConfigurationForm(array $form, FormStateInterface $form_state)
	{
		return $form;
	}

	/**
	 * {@inheritdoc}
	 */
	public function submitConfigurationForm(array &$form, FormStateInterface $form_state)
	{
		// resizing happens at delivery, so there is no toolkit-side setting to store
	}

	/**
	 * {@inheritdoc}
	 */
	public static function isAvailable()
	{
		// available whenever the runtime exposes the delivery capability; without it
		// Drupal should fall back rather than silently serve unresized images
		return Host::has('cfwImageUrl');
	}

	/**
	 * {@inheritdoc}
	 */
	public static function getSupportedExtensions()
	{
		// AVIF IS CLAIMED ONLY WHEN THE ENGINE ENCODES IT, and the honesty is load-bearing. All
		// four shipped image styles are image_scale + image_convert_avif, and
		// AvifImageEffect::applyEffect() calls isAvifSupported() first and falls through to its
		// parent when the toolkit says no -- the shipped fallback extension is webp, which the
		// wasm engine does encode. So core degrades on its own with no configuration change.
		// Claim avif while the engine cannot produce it and the effect calls convert('avif'), gets
		// FALSE, and logs a failed derivative instead of falling back.
		//
		// THE HOST NAMES THEM NOW. This derived the list from the engine's NAME, which pinned the
		// wasm arm to whatever it encoded the day the line was written; tinyimg 1.1 added AVIF and
		// all four styles went on degrading to webp. The host reads the module's own feature list.
		$reply = Host::call('cfwImageUrl', [
			'uri' => 'public://cfw-capability-probe.png',
			'transform' => ['width' => 1],
		]);
		$named = $reply['extensions'] ?? null;
		if (is_array($named) && $named !== []) {
			return array_values(array_map('strval', $named));
		}
		// an older host answers no `extensions`; claim only what every engine has always encoded
		return ['png', 'jpe', 'jpeg', 'jpg', 'gif', 'webp'];
	}

	/**
	 * Builds a delivery URL carrying the transform, for a formatter to emit.
	 *
	 * @param string $uri
	 *   The source file URI.
	 * @param array $transform
	 *   Cloudflare Images options: width, height, fit, quality, format.
	 *
	 * @return string|null
	 *   The URL, or NULL when the capability is absent.
	 */
	public static function deliveryUrl(string $uri, array $transform): ?string
	{
		$reply = Host::call('cfwImageUrl', ['uri' => $uri, 'transform' => $transform]);
		return ($reply['ok'] ?? false) === true ? (string) ($reply['url'] ?? '') : null;
	}
}
