<?php
/**
* Command line interface to the backup module
* Run this from a cron job with a user having sufficient privileges to read all files included and create a file in the storage directory. 
* Usage: backup.php <job id>
*/

//Include Processwire
include(dirname(__FILE__)."/../../../index.php"); 

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
