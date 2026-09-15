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
 *
 * THE TITLE IS NOT "Cloudflare Images", AND IT NAMED THE WRONG ENGINE ON EVERY DEFAULT SITE.
 * This one plugin fronts two: tinyimg, a wasm encoder running in the front worker, which is the
 * DEFAULT, and Cloudflare Images, which is opt-in behind IMAGE_ENGINE=images. The old title told
 * an operator their site used a product it does not use, and buildConfigurationForm() said there
 * was nothing to configure while the choice sat in a deployed variable.
 */
#[
	ImageToolkit(
		id: 'cfw_images',
		title: new TranslatableMarkup('Drupflare (delivery-time resizing)'),
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
	 * a guaranteed fatal in it.
	 *
	 * A REPORT RATHER THAN A FORM, and it submits nothing. The one real choice here is which engine
	 * encodes, and that is a Worker lever rather than site configuration: it belongs to the
	 * deployment, is shared by every site on it, and is settable without a redeploy from the
	 * Drupflare settings page. What this page owes an operator is which engine is in force and what
	 * it can encode, which is what the page previously did not say at all.
	 */
	public function buildConfigurationForm(array $form, FormStateInterface $form_state)
	{
		// READ FROM THE HOST, NOT DERIVED FROM A NAME. `getSupportedExtensions()` below records why:
		// deriving capability from the engine name pinned the wasm arm to whatever it encoded the day
		// the line was written, and tinyimg 1.1 added AVIF with every shipped style still degrading
		// to webp. The same argument applies to which engine is running at all.
		$reply = Host::call('cfwImageUrl', [
			'uri' => 'public://cfw-capability-probe.png',
			'transform' => ['width' => 1],
		]);
		$engine = (string) ($reply['engine'] ?? '');
		$extensions = $reply['extensions'] ?? [];

		$names = [
			'tinyimg' => new TranslatableMarkup(
				'Worker-side encoder, which runs in the front worker',
			),
			'images' => new TranslatableMarkup(
				'Cloudflare Images, which resizes on delivery from a URL',
			),
		];

		$form['engine'] = [
			'#type' => 'item',
			'#title' => new TranslatableMarkup('Engine'),
			'#markup' =>
				$engine === ''
					? new TranslatableMarkup(
						'The runtime did not report an engine, so this site is not being served by a Worker.',
					)
					: $names[$engine] ??
						new TranslatableMarkup('An engine this module does not recognise: @id', [
							'@id' => $engine,
						]),
			'#description' => new TranslatableMarkup(
				'Set by the <code>IMAGE_ENGINE</code> runtime lever rather than here, because it belongs to the Worker and not to this site. It can be changed without a redeploy from the Drupflare settings page.',
			),
		];

		$form['formats'] = [
			'#type' => 'item',
			'#title' => new TranslatableMarkup('Formats this engine encodes'),
			'#markup' =>
				is_array($extensions) && $extensions !== []
					? implode(', ', array_map('strval', $extensions))
					: new TranslatableMarkup('none reported'),
			'#description' => new TranslatableMarkup(
				'Read from the engine rather than assumed. An image style asking for a format absent from this list falls back rather than failing.',
			),
		];

		// derivatives are never written, so there is no quality or resampling setting to store; the
		// two items above are a report rather than a form and submit nothing
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
