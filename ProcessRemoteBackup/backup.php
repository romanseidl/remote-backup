<?php namespace ProcessWire;
/**
* Command line interface to the backup module
* Run this from a cron job with a user having sufficient privileges to read all files included and create a file in the storage directory.
* Usage: backup.php <job id>
*/

/**
 * Load ProcessWire dynamically based on current directory (thanks Teppo/ProcessWireLinkChecker)
 */
if (!defined("PROCESSWIRE") || (!$wire && !function_exists("wire"))) {
	if (is_null($root)) $root = substr(__DIR__, 0, strrpos(__DIR__, "/modules")) . "/..";
	require rtrim($root, "/") . "/index.php";
	if (!defined("PROCESSWIRE") || (!$wire && !function_exists("wire"))) {
		throw new Exception("Unable to bootstrap ProcessWire.");
	}
}

$backup = wire('modules')->get("ProcessRemoteBackup");

if($argv[1] != null)
	$backup->backup($argv[1]);
else {
	echo "backup.php - Usage: backup.php <job id>\n".
		 "Please be aware to run the job as a user that has sufficient privileges to read all files included and write to the storage directory.\n\n".
		 "Avialable Jobs:\n".
			 " id => name  \n".
			 "-----------------\n";

	$jobs = $backup->getJobs();
	foreach($jobs as $id => $job)
		echo " ".$id." => ".$job['name']."\n";
}
