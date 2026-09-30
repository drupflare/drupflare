<?php

/**
 * @file
 * Declares the process functions that call the exec router, as the runtime does.
 */

use Drupal\drupflare\Exec\Functions;

if (!function_exists('exec')) {
	function exec($command, &$output = null, &$result_code = null)
	{
		return Functions::exec($command, $output, $result_code);
	}
	function shell_exec($command)
	{
		return Functions::shellExec($command);
	}
	function system($command, &$result_code = null)
	{
		return Functions::system($command, $result_code);
	}
	function passthru($command, &$result_code = null)
	{
		return Functions::passthru($command, $result_code);
	}
	function popen($command, $mode)
	{
		return Functions::popen($command, $mode);
	}
	function pclose($handle)
	{
		return Functions::pclose($handle);
	}
	function proc_open(
		$command,
		$descriptor_spec,
		&$pipes,
		$cwd = null,
		$env_vars = null,
		$options = null,
	) {
		return Functions::procOpen($command, $descriptor_spec, $pipes, $cwd, $env_vars, $options);
	}
	function proc_get_status($process)
	{
		return Functions::procGetStatus($process);
	}
	function proc_close($process)
	{
		return Functions::procClose($process);
	}
	function proc_terminate($process, $signal = 15)
	{
		return Functions::procTerminate($process, $signal);
	}
}
