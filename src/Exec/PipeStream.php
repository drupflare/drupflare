<?php

declare(strict_types=1);

namespace Drupal\drupflare\Exec;

/**
 * The pipes of one process opened through {@see Functions::procOpen()}.
 *
 * Symfony Process writes input into stdin, closes it, then polls the process and reads stdout and
 * stderr with `stream_select()`. A plain temporary file cannot stand in for either end: stdin loses
 * its bytes when closed, and an empty stdout is at end of file before the command has run, which
 * makes the reader close it. This wrapper keeps stdin's bytes, reports end of file on the output
 * pipes only after the command delivered, and hands `stream_select()` a real file to poll.
 */
final class PipeStream
{
	/**
	 * The stream scheme the wrapper registers under.
	 */
	public const SCHEME = 'cfwpipe';

	/**
	 * The pipe sets, by id: stdin bytes and closed flag, and each output descriptor's bytes and delivery.
	 *
	 * @var array<int, array{in: string, closed: bool, out: array<int, string>, done: array<int, bool>}>
	 */
	private static array $pipes = [];

	/**
	 * The next pipe set id.
	 *
	 * @var int
	 */
	private static int $next = 1;

	/**
	 * A real file `stream_select()` polls in place of this stream.
	 *
	 * @var resource|null
	 */
	private $select = null;

	/**
	 * The pipe set this handle belongs to.
	 *
	 * @var int
	 */
	private int $id = 0;

	/**
	 * The descriptor this handle stands for.
	 *
	 * @var int
	 */
	private int $fd = 0;

	/**
	 * The stream context PHP assigns.
	 *
	 * @var resource|null
	 */
	public $context;

	/**
	 * Registers the wrapper if needed and starts a pipe set.
	 *
	 * @return int
	 *   The id of the new pipe set.
	 */
	public static function create(): int
	{
		if (!in_array(self::SCHEME, stream_get_wrappers(), true)) {
			stream_wrapper_register(self::SCHEME, self::class);
		}
		$id = self::$next++;
		self::$pipes[$id] = ['in' => '', 'closed' => false, 'out' => [], 'done' => []];
		return $id;
	}

	/**
	 * The parent's handle on one descriptor: written for stdin, read for the rest.
	 *
	 * @param int $id
	 *   The pipe set.
	 * @param int $fd
	 *   The descriptor.
	 *
	 * @return resource
	 *   The stream.
	 */
	public static function handle(int $id, int $fd)
	{
		$handle = fopen(self::SCHEME . '://' . $id . '/' . $fd, $fd === 0 ? 'w' : 'r');
		if ($handle === false) {
			throw new Refused('a process pipe could not be opened');
		}
		return $handle;
	}

	/**
	 * Whether the writer has closed stdin.
	 *
	 * @param int $id
	 *   The pipe set.
	 *
	 * @return bool
	 *   True once stdin is closed, or when the set is unknown.
	 */
	public static function closed(int $id): bool
	{
		return self::$pipes[$id]['closed'] ?? true;
	}

	/**
	 * Everything written to stdin, forgetting it.
	 *
	 * @param int $id
	 *   The pipe set.
	 *
	 * @return string
	 *   The bytes written.
	 */
	public static function takeInput(int $id): string
	{
		$data = self::$pipes[$id]['in'] ?? '';
		if (isset(self::$pipes[$id])) {
			self::$pipes[$id]['in'] = '';
		}
		return $data;
	}

	/**
	 * Gives an output descriptor its bytes and lets it reach end of file.
	 *
	 * @param int $id
	 *   The pipe set.
	 * @param int $fd
	 *   The descriptor.
	 * @param string $bytes
	 *   What the command printed.
	 */
	public static function deliver(int $id, int $fd, string $bytes): void
	{
		if (isset(self::$pipes[$id])) {
			self::$pipes[$id]['out'][$fd] = $bytes;
			self::$pipes[$id]['done'][$fd] = true;
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
	{
		$parts = explode('/', substr($path, strlen(self::SCHEME) + 3));
		$this->id = (int) $parts[0];
		$this->fd = (int) ($parts[1] ?? 0);
		$select = tmpfile();
		$this->select = $select === false ? null : $select;
		return isset(self::$pipes[$this->id]);
	}

	/**
	 * {@inheritdoc}
	 */
	public function stream_write(string $data): int
	{
		self::$pipes[$this->id]['in'] .= $data;
		return strlen($data);
	}

	/**
	 * {@inheritdoc}
	 */
	public function stream_read(int $count): string
	{
		$buffer = self::$pipes[$this->id]['out'][$this->fd] ?? '';
		self::$pipes[$this->id]['out'][$this->fd] = (string) substr($buffer, $count);
		return substr($buffer, 0, $count);
	}

	/**
	 * {@inheritdoc}
	 */
	public function stream_eof(): bool
	{
		return (self::$pipes[$this->id]['done'][$this->fd] ?? false) &&
			(self::$pipes[$this->id]['out'][$this->fd] ?? '') === '';
	}

	/**
	 * {@inheritdoc}
	 */
	public function stream_flush(): bool
	{
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function stream_close(): void
	{
		if ($this->fd === 0 && isset(self::$pipes[$this->id])) {
			self::$pipes[$this->id]['closed'] = true;
		}
		if (is_resource($this->select)) {
			fclose($this->select);
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
	{
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function stream_cast(int $castAs)
	{
		return $this->select ?? false;
	}

	/**
	 * {@inheritdoc}
	 */
	public function stream_stat(): array
	{
		return [];
	}
}
